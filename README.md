# Filament authorization audit

Finds the authorization gaps that Filament panels most often ship with. It reads your code statically, with no app boot and no database, and points to the file and line of each place to check:

| Rule | Severity | What it means |
|---|---|---|
| `panel-access-open` | high | `canAccessPanel()` returns `true`: every user who can sign in reaches the panel |
| `resource-without-policy` | high | the resource's model has no policy, and Filament then allows every operation (notice only when a panel uses `strictAuthorization()`) |
| `authorization-skipped` | high | `skipAuthorization()` on a resource |
| `custom-action-unauthorized` | high | `Action::make()` without `authorize()`: Filament checks policies for its built-in actions only |
| `custom-action-visibility-only` | medium | a custom action that is only shown or hidden: fine if the condition is a permission check, a hole if it is not |
| `editable-column-unchecked` | medium | `TextInputColumn`, `SelectColumn`, `ToggleColumn` or `CheckboxColumn` without `disabled()` or `hidden()`: editable columns skip policies |
| `panel-registration` | medium | `->registration()` on a panel: anyone can create an account |
| `bulk-action-any-only` | notice | `DeleteBulkAction` and friends check `deleteAny()`, not each record, unless `authorizeIndividualRecords()` |
| `panel-not-strict` | notice | a panel without `strictAuthorization()` |

## Install and run

```
composer require --dev nightly-works/filament-authorization-audit
vendor/bin/filament-authorization-audit app
```

Options: `--json` for machine-readable output, `--fail-on=high|medium|never` (default `medium`) to set when the exit code is 1, for CI.

Example on a demo panel with planted flaws ([filament-panel-review-demo](https://github.com/nightly-works/filament-panel-review-demo), tag `as-delivered`):

```
MEDIUM Filament/Resources/Invoices/InvoiceResource.php:65  [editable-column-unchecked] SelectColumn 'status' writes to the record without a policy check: editable columns respect only disabled() and hidden().
MEDIUM Filament/Resources/Invoices/InvoiceResource.php:73  [custom-action-visibility-only] Custom action 'refund' is only shown or hidden: unless that condition is a permission check, any panel user can run it. Add ->authorize('<ability>').
HIGH   Filament/Resources/Users/UserResource.php:23  [resource-without-policy] Model User has no policy: Filament then allows every operation on this resource to every panel user.
HIGH   Models/User.php:35  [panel-access-open] canAccessPanel() returns true: every user who can sign in reaches the panel. Check a role or permission.
MEDIUM Providers/Filament/AdminPanelProvider.php:26  [panel-registration] Self-registration is on: anyone can create an account. Make sure canAccessPanel() admits only the intended users.
```

## What it cannot see

It reads the code as written. It does not know whether a `visible()` condition is a real permission check, cannot follow actions or columns built in helper methods with dynamic class names, and finds policies by name (`ModelPolicy` classes, `Gate::policy()`, `#[UsePolicy]`), not by running Laravel's policy discovery. A clean run means none of these patterns were found, not that the panel is secure. Built and tested against Filament 5; actions and columns are matched by class name, so older versions may work but are not tested.

## Development

```
composer install
vendor/bin/phpunit
```

The tests run the audit on the demo app as delivered (every planted flaw must be found) and after its fixes (nothing above notice).

Built by an AI agent (Claude) at [Nightly Works](https://github.com/nightly-works). MIT licence.
