<?php

namespace NightlyWorks\FilamentAuthorizationAudit\Tests;

use NightlyWorks\FilamentAuthorizationAudit\Auditor;
use NightlyWorks\FilamentAuthorizationAudit\Finding;
use PHPUnit\Framework\TestCase;

final class AuditorTest extends TestCase
{
    /** @return list<string> "rule@file" for each finding */
    private function audit(string $fixture): array
    {
        return array_map(
            fn (Finding $f) => "{$f->rule}@{$f->file}",
            (new Auditor())->audit(__DIR__.'/fixtures/'.$fixture),
        );
    }

    public function test_finds_every_planted_flaw_in_the_demo_app_as_delivered(): void
    {
        $found = $this->audit('as-delivered');

        $this->assertContains('panel-access-open@Models/User.php', $found);
        $this->assertContains('panel-registration@Providers/Filament/AdminPanelProvider.php', $found);
        $this->assertContains('panel-not-strict@Providers/Filament/AdminPanelProvider.php', $found);
        $this->assertContains('resource-without-policy@Filament/Resources/Users/UserResource.php', $found);
        $this->assertContains('custom-action-visibility-only@Filament/Resources/Invoices/InvoiceResource.php', $found);
        $this->assertContains('editable-column-unchecked@Filament/Resources/Invoices/InvoiceResource.php', $found);
        $this->assertContains('bulk-action-any-only@Filament/Resources/Users/UserResource.php', $found);
        // InvoicePolicy exists, so the invoice resource is not reported as policy-less.
        $this->assertNotContains('resource-without-policy@Filament/Resources/Invoices/InvoiceResource.php', $found);
    }

    public function test_reports_nothing_above_notice_once_fixed(): void
    {
        $severities = array_map(fn (Finding $f) => $f->severity, (new Auditor())->audit(__DIR__.'/fixtures/fixed'));

        $this->assertNotContains(Finding::HIGH, $severities);
        $this->assertNotContains(Finding::MEDIUM, $severities);
    }

    public function test_hand_written_cases(): void
    {
        $findings = (new Auditor())->audit(__DIR__.'/fixtures/other');
        $byRule = [];
        foreach ($findings as $f) {
            $byRule[$f->rule][] = $f->message;
        }

        $this->assertArrayHasKey('authorization-skipped', $byRule);
        // export has no check at all; archive is authorized; the toggle is disabled; in NotFilament.php,
        // other classes named Action or SelectColumn and a link-only action are not findings, and a
        // disabled action counts as visibility-only.
        $this->assertCount(1, $byRule['custom-action-unauthorized']);
        $this->assertCount(1, $byRule['custom-action-visibility-only']);
        $this->assertStringContainsString("'lock'", $byRule['custom-action-visibility-only'][0]);
        $this->assertStringContainsString("'export'", $byRule['custom-action-unauthorized'][0]);
        $this->assertArrayNotHasKey('editable-column-unchecked', $byRule);
        // Report has no policy; Team has one through Gate::policy().
        $this->assertCount(1, $byRule['resource-without-policy']);
        $this->assertStringContainsString('Report', $byRule['resource-without-policy'][0]);
    }

    public function test_command_exit_codes(): void
    {
        $bin = PHP_BINARY.' '.escapeshellarg(__DIR__.'/../bin/filament-authorization-audit');
        exec($bin.' '.escapeshellarg(__DIR__.'/fixtures/as-delivered'), $out, $code);
        $this->assertSame(1, $code);
        exec($bin.' '.escapeshellarg(__DIR__.'/fixtures/fixed'), $out, $code);
        $this->assertSame(0, $code);
        exec($bin.' --fail-on=never '.escapeshellarg(__DIR__.'/fixtures/as-delivered'), $out, $code);
        $this->assertSame(0, $code);
        exec($bin.' --fail-on=hgh '.escapeshellarg(__DIR__.'/fixtures/as-delivered').' 2>/dev/null', $out, $code);
        $this->assertSame(2, $code);
    }
}
