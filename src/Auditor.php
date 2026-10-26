<?php

namespace NightlyWorks\FilamentAuthorizationAudit;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Reads a Laravel application's PHP files and reports Filament authorization gaps. Static analysis:
 * it sees what the code says, not what runs, so every finding is a place to check, and code it
 * cannot follow (closures built elsewhere, dynamic class names) is not reported.
 */
final class Auditor
{
    /** Actions and columns are matched by class short name, whatever their namespace. */
    private const CUSTOM_ACTIONS = ['Action', 'BulkAction'];
    private const EDITABLE_COLUMNS = ['TextInputColumn', 'SelectColumn', 'ToggleColumn', 'CheckboxColumn'];
    private const BULK_ACTIONS_CHECKING_ANY = ['DeleteBulkAction', 'ForceDeleteBulkAction', 'RestoreBulkAction'];
    private const SKIPPED_DIRS = ['vendor', 'node_modules', 'storage', '.git', 'tests'];

    private Parser $parser;
    private NodeFinder $finder;

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
        $this->finder = new NodeFinder();
    }

    /** @return list<Finding> */
    public function audit(string $path): array
    {
        $files = [];
        foreach ($this->phpFiles($path) as $file) {
            $ast = $this->parse($file);
            if ($ast !== null) {
                $files[$file] = $ast;
            }
        }

        $policies = $this->modelsWithPolicies($files);
        $strict = $this->anyPanelIsStrict($files);

        $findings = [];
        foreach ($files as $file => $ast) {
            $relative = ltrim(substr($file, strlen(rtrim($path, '/'))), '/') ?: basename($file);
            array_push($findings, ...$this->checkFile($relative, $ast, $policies, $strict));
        }

        usort($findings, fn (Finding $a, Finding $b) => [$a->file, $a->line] <=> [$b->file, $b->line]);

        return $findings;
    }

    /** @param array<string, array<Node>> $files @param array<string, true> $policies @return list<Finding> */
    private function checkFile(string $file, array $ast, array $policies, bool $strict): array
    {
        $findings = [];
        $add = function (string $rule, string $severity, Node $node, string $message) use (&$findings, $file) {
            $findings[] = new Finding($rule, $severity, $file, $node->getStartLine(), $message);
        };

        foreach ($this->finder->findInstanceOf($ast, Node\Stmt\Class_::class) as $class) {
            $parent = $class->extends?->getLast() ?? '';

            // canAccessPanel() { return true; }
            foreach ($class->getMethods() as $method) {
                if ($method->name->toString() === 'canAccessPanel' && $this->returnsOnlyTrue($method)) {
                    $add('panel-access-open', Finding::HIGH, $method, 'canAccessPanel() returns true: every user who can sign in reaches the panel. Check a role or permission.');
                }
            }

            // A panel provider without strictAuthorization().
            if (str_ends_with($parent, 'PanelProvider')) {
                $calls = $this->methodNames($class);
                if (! isset($calls['strictAuthorization'])) {
                    $add('panel-not-strict', Finding::NOTICE, $class, 'Panel without strictAuthorization(): a model without a policy allows every action instead of failing loudly.');
                }
            }

            // A resource whose model has no policy.
            if (str_ends_with($parent, 'Resource')) {
                $model = $this->resourceModel($class);
                if ($model !== null && ! isset($policies[$model['name']])) {
                    $add('resource-without-policy', $strict ? Finding::NOTICE : Finding::HIGH, $model['node'], $strict
                        ? "Model {$model['name']} has no policy; strictAuthorization() makes its pages fail instead of allowing everything."
                        : "Model {$model['name']} has no policy: Filament then allows every operation on this resource to every panel user.");
                }
            }
        }

        foreach ($this->finder->findInstanceOf($ast, Node\Expr\MethodCall::class) as $call) {
            if ($this->name($call) === 'registration' && $this->rootIsPanelChain($call)) {
                $add('panel-registration', Finding::MEDIUM, $call, 'Self-registration is on: anyone can create an account. Make sure canAccessPanel() admits only the intended users.');
            }
        }

        foreach ($this->finder->findInstanceOf($ast, Node\Expr\StaticCall::class) as $call) {
            $method = $call->name instanceof Node\Identifier ? $call->name->toString() : null;
            $class = $call->class instanceof Node\Name ? $call->class->getLast() : null;

            if ($method === 'skipAuthorization' && ($call->args === [] || $this->isTrue($call->args[0]->value ?? null))) {
                $add('authorization-skipped', Finding::HIGH, $call, 'skipAuthorization() removes every policy check for this resource.');
            }

            if ($method !== 'make' || $class === null) {
                continue;
            }
            $chain = $this->chainNames($call);

            if (in_array($class, self::CUSTOM_ACTIONS, true)) {
                if (! array_intersect($chain, ['authorize', 'authorizeAny', 'authorizeIndividualRecords'])) {
                    $label = $this->firstArgString($call);
                    if (array_intersect($chain, ['visible', 'hidden'])) {
                        $add('custom-action-visibility-only', Finding::MEDIUM, $call, "Custom action{$label} is only shown or hidden: unless that condition is a permission check, any panel user can run it. Add ->authorize('<ability>').");
                    } else {
                        $add('custom-action-unauthorized', Finding::HIGH, $call, "Custom action{$label} has no authorization: policies are not checked for custom actions. Add ->authorize('<ability>').");
                    }
                }
            }

            if (in_array($class, self::EDITABLE_COLUMNS, true) && ! array_intersect($chain, ['disabled', 'hidden', 'visible'])) {
                $label = $this->firstArgString($call);
                $add('editable-column-unchecked', Finding::MEDIUM, $call, "{$class}{$label} writes to the record without a policy check: editable columns respect only disabled() and hidden().");
            }

            if (in_array($class, self::BULK_ACTIONS_CHECKING_ANY, true) && ! in_array('authorizeIndividualRecords', $chain, true)) {
                $add('bulk-action-any-only', Finding::NOTICE, $call, "{$class} checks the policy's *Any() method only, not each record. Add ->authorizeIndividualRecords('<ability>') if some records must be protected.");
            }
        }

        return $findings;
    }

    /** @return array<string, true> model short names that have a policy */
    private function modelsWithPolicies(array $files): array
    {
        $models = [];
        foreach ($files as $ast) {
            foreach ($this->finder->findInstanceOf($ast, Node\Stmt\Class_::class) as $class) {
                $name = $class->name?->toString() ?? '';
                if (str_ends_with($name, 'Policy') && $name !== 'Policy') {
                    $models[substr($name, 0, -6)] = true;
                }
                foreach ($class->attrGroups as $group) {
                    foreach ($group->attrs as $attr) {
                        if ($attr->name->getLast() === 'UsePolicy' && $class->name !== null) {
                            $models[$class->name->toString()] = true;
                        }
                    }
                }
            }
            // Gate::policy(Model::class, ...)
            foreach ($this->finder->findInstanceOf($ast, Node\Expr\StaticCall::class) as $call) {
                if ($call->class instanceof Node\Name && $call->class->getLast() === 'Gate'
                    && $call->name instanceof Node\Identifier && $call->name->toString() === 'policy'
                    && ($arg = $call->args[0]->value ?? null) instanceof Node\Expr\ClassConstFetch
                    && $arg->class instanceof Node\Name) {
                    $models[$arg->class->getLast()] = true;
                }
            }
        }

        return $models;
    }

    private function anyPanelIsStrict(array $files): bool
    {
        foreach ($files as $ast) {
            foreach ($this->finder->findInstanceOf($ast, Node\Expr\MethodCall::class) as $call) {
                if ($this->name($call) === 'strictAuthorization' && ($call->args === [] || $this->isTrue($call->args[0]->value ?? null))) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return array{name: string, node: Node}|null */
    private function resourceModel(Node\Stmt\Class_ $class): ?array
    {
        foreach ($class->getProperties() as $property) {
            foreach ($property->props as $prop) {
                if ($prop->name->toString() === 'model'
                    && $prop->default instanceof Node\Expr\ClassConstFetch
                    && $prop->default->class instanceof Node\Name) {
                    return ['name' => $prop->default->class->getLast(), 'node' => $property];
                }
            }
        }

        return null;
    }

    private function returnsOnlyTrue(Node\Stmt\ClassMethod $method): bool
    {
        $stmts = $method->stmts ?? [];

        return count($stmts) === 1 && $stmts[0] instanceof Node\Stmt\Return_ && $this->isTrue($stmts[0]->expr);
    }

    private function isTrue(?Node $expr): bool
    {
        return $expr instanceof Node\Expr\ConstFetch && strtolower($expr->name->toString()) === 'true';
    }

    private function name(Node\Expr\MethodCall $call): ?string
    {
        return $call->name instanceof Node\Identifier ? $call->name->toString() : null;
    }

    /** Method names called on the result of $call, in order: Foo::make()->a()->b() gives [a, b]. */
    private function chainNames(Node\Expr $call): array
    {
        $names = [];
        $node = $call;
        while (($parent = $node->getAttribute('parent')) instanceof Node\Expr\MethodCall && $parent->var === $node) {
            $names[] = $this->name($parent);
            $node = $parent;
        }

        return array_values(array_filter($names));
    }

    private function rootIsPanelChain(Node\Expr\MethodCall $call): bool
    {
        $node = $call;
        while ($node instanceof Node\Expr\MethodCall) {
            $node = $node->var;
        }

        return $node instanceof Node\Expr\Variable && $node->name === 'panel';
    }

    /** @return array<string, true> every method name called anywhere in the class */
    private function methodNames(Node\Stmt\Class_ $class): array
    {
        $names = [];
        foreach ($this->finder->findInstanceOf($class, Node\Expr\MethodCall::class) as $call) {
            if ($name = $this->name($call)) {
                $names[$name] = true;
            }
        }

        return $names;
    }

    private function firstArgString(Node\Expr\StaticCall $call): string
    {
        $arg = $call->args[0]->value ?? null;

        return $arg instanceof Node\Scalar\String_ ? " '{$arg->value}'" : '';
    }

    /** @return array<Node>|null */
    private function parse(string $file): ?array
    {
        try {
            $ast = $this->parser->parse((string) file_get_contents($file));
        } catch (\PhpParser\Error) {
            return null;
        }
        $traverser = new NodeTraverser(new NameResolver(), new ParentConnectingVisitor());

        return $traverser->traverse($ast ?? []);
    }

    /** @return iterable<string> */
    private function phpFiles(string $path): iterable
    {
        if (is_file($path)) {
            yield $path;

            return;
        }
        $iterator = new RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            fn (\SplFileInfo $f) => ! ($f->isDir() && in_array($f->getFilename(), self::SKIPPED_DIRS, true)),
        ));
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                yield $file->getPathname();
            }
        }
    }
}
