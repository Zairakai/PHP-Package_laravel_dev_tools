<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Update;

use RuntimeException;

/**
 * The audit that blocks a pipeline, from the JSON report of `composer audit`.
 *
 * An advisory blocks at the level that the daily audit fixes at once (Advisories::isUrgent: high or critical). The others
 * are listed and do not block: Composer does not always give a severity, and an unknown one counts as a medium one. A
 * report without the list of advisories is not an audit: Composer could not run. The JavaScript tool of the group does
 * the same (auditGate).
 */
final readonly class AuditCheckRun
{
    public function __construct(private Shell $shell) {}

    public function run(string $file): void
    {
        if ('' === $file) {
            throw new RuntimeException(
                'Usage: update-dependencies audit-check <report of composer audit --format=json>',
            );
        }

        $blocking = 0;

        foreach (Advisories::advisories($this->report($file)) as $advisory) {
            if (Advisories::isUrgent($advisory['severity'])) {
                $this->shell->error('Blocking: ' . $this->line($advisory));
                $blocking++;

                continue;
            }

            $this->shell->log('Not blocking: ' . $this->line($advisory));
        }

        if (0 < $blocking) {
            throw new RuntimeException("{$blocking} advisory(ies) at the high level or above.");
        }

        $this->shell->log('No advisory at the high level or above.');
    }

    /**
     * The line of an advisory in the log of the job.
     *
     * @param array{id: string, package: string, link: string, severity: string} $advisory
     */
    private function line(array $advisory): string
    {
        $severity = '' === $advisory['severity']
            ? 'unknown'
            : $advisory['severity'];

        return "{$advisory['package']} ({$advisory['id']}, {$severity}): {$advisory['link']}";
    }

    /**
     * The report of `composer audit`, when it holds a list of advisories.
     *
     * @return array<string, mixed>
     */
    private function report(string $file): array
    {
        $report = is_file($file)
            ? json_decode((string) file_get_contents($file), true)
            : null;

        if (
            ! is_array($report)
            || ! is_array($report['advisories'] ?? null)
        ) {
            throw new RuntimeException('composer audit could not run: its report has no list of advisories');
        }

        return Json::map($report);
    }
}
