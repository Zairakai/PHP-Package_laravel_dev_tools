<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Tests\Unit\Update;

use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Zairakai\LaravelDevTools\Tests\Unit\Update\Support\UpdateTestCase;
use Zairakai\LaravelDevTools\Update\AuditCheckRun;

final class AuditCheckRunTest extends UpdateTestCase
{
    #[Test]
    public function an_unknown_severity_counts_as_a_medium_one_and_does_not_block(): void
    {
        $this->check($this->report(['c/unknown' => '']));

        $this->assertSame([], $this->shell->errors);
        $this->assertStringContainsString('Not blocking: c/unknown', $this->shell->logs[0]);
        $this->assertStringContainsString('unknown', $this->shell->logs[0]);
    }

    #[Test]
    public function it_asks_for_the_report(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Usage: update-dependencies audit-check');

        new AuditCheckRun($this->shell)->run('');
    }

    #[Test]
    public function it_blocks_at_the_level_that_the_daily_audit_fixes_at_once(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('2 advisory(ies) at the high level or above.');

        try {
            $this->check($this->report([
                'a/high'     => 'high',
                'b/critical' => 'critical',
                'c/unknown'  => '',
                'd/medium'   => 'medium',
                'e/low'      => 'low',
            ]));
        }
        finally {
            $this->assertCount(2, $this->shell->errors);
            $this->assertStringContainsString('Blocking: a/high', $this->shell->errors[0]);
            $this->assertCount(3, $this->shell->logs);
        }
    }

    #[Test]
    public function it_lists_the_advisories_below_the_high_level_and_does_not_block(): void
    {
        $this->check($this->report([
            'd/medium' => 'medium',
            'e/low'    => 'low',
        ]));

        $this->assertSame([], $this->shell->errors);
        $this->assertCount(3, $this->shell->logs);
    }

    #[Test]
    public function it_passes_when_the_report_has_no_advisory(): void
    {
        $this->check('{"advisories": []}');

        $this->assertSame(['No advisory at the high level or above.'], $this->shell->logs);
        $this->assertSame([], $this->shell->errors);
    }

    #[Test]
    public function it_refuses_a_missing_report(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('composer audit could not run');

        new AuditCheckRun($this->shell)->run($this->directory . '/nothing.json');
    }

    #[Test]
    public function it_refuses_a_report_that_is_not_an_audit(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('composer audit could not run');

        $this->check('{"error": "could not reach packagist"}');
    }

    private function check(string $json): void
    {
        $file = $this->directory . '/audit-report.json';

        file_put_contents($file, $json);

        new AuditCheckRun($this->shell)->run($file);
    }

    /**
     * The report of `composer audit` for packages and their severity.
     *
     * @param array<string, string> $severities
     */
    private function report(array $severities): string
    {
        $advisories = [];

        foreach ($severities as $package => $severity) {
            $advisories[$package] = [
                [
                    'advisoryId'       => 'PKSA-' . $package,
                    'packageName'      => $package,
                    'title'            => 'A flaw',
                    'link'             => 'https://example.test/' . $package,
                    'cve'              => '',
                    'affectedVersions' => '<1.0',
                    ...('' === $severity ? [] : ['severity' => $severity]),
                ],
            ];
        }

        return (string) json_encode(['advisories' => $advisories]);
    }
}
