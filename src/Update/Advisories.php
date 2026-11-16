<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Update;

/**
 * The security advisories of `composer audit`: their rows, their issues and their notification.
 */
final class Advisories
{
    /**
     * The start of the title of the issues opened by the audit.
     */
    public const string ADVISORY_TITLE_PREFIX = 'Security advisory: ';

    /**
     * The advisories of the JSON report of `composer audit`, one row per advisory.
     *
     * @param array<string, mixed> $report
     *
     * @return list<array{id: string, package: string, title: string, link: string, cve: string, severity: string, range: string}>
     */
    public static function advisories(array $report): array
    {
        $rows = [];

        foreach (Json::map($report['advisories'] ?? []) as $package => $advisories) {
            foreach (Json::rows($advisories) as $advisory) {
                $rows[] = [
                    'id'       => Json::text($advisory, 'advisoryId'),
                    'package'  => Json::textOr(
                        $advisory,
                        'packageName',
                        $package,
                    ),
                    'title'    => Json::text($advisory, 'title'),
                    'link'     => Json::text($advisory, 'link'),
                    'cve'      => Json::text($advisory, 'cve'),
                    'severity' => strtolower(Json::text($advisory, 'severity')),
                    'range'    => Json::text($advisory, 'affectedVersions'),
                ];
            }
        }

        usort($rows, static fn (array $a, array $b): int => [
            $a['package'],
            $a['id'],
        ] <=> [
            $b['package'],
            $b['id'],
        ]);

        return $rows;
    }

    /**
     * The body of the issue opened for an advisory.
     *
     * @param array{id: string, package: string, title: string, link: string, cve: string, severity: string, range: string} $advisory
     */
    public static function advisoryIssueBody(
        array $advisory,
        string $pipelineUrl,
        bool $autoFix,
    ): string {
        $lines = [
            "{$advisory['title']}.",
            '',
            "- Package: `{$advisory['package']}`",
            '- Affected versions: `' . self::orElse($advisory['range'], 'unknown') . '`',
            '- Severity: ' . self::orElse($advisory['severity'], 'not given'),
            '- Advisory: ' . self::orElse($advisory['link'], $advisory['id']),
        ];

        if ('' !== $advisory['cve']) {
            $lines[] = "- CVE: {$advisory['cve']}";
        }

        $lines[] = '- Automatic fix: ' . self::autoFixText($advisory['severity'], $autoFix);
        $lines[] = '';
        $lines[] = "Pipeline: {$pipelineUrl}";
        $lines[] = '';
        $lines[] = 'The issue is closed by the next audit that no longer finds this advisory.';

        return implode("\n", $lines);
    }

    /**
     * The labels of the issue of an advisory: kind and area, the priority from the severity, and Status::Ready.
     *
     * @param array{severity: string} $advisory
     * @param list<string>            $base     the labels that every issue has
     */
    public static function advisoryLabels(array $advisory, array $base): string
    {
        $priority = match ($advisory['severity']) {
            'critical' => 'Priority::Critical',
            'high'     => 'Priority::High',
            default    => 'Priority::Low',
        };

        return implode(',', [
            ...$base,
            $priority,
            'Status::Ready',
        ]);
    }

    /**
     * The title of the issue of an advisory. It is also the key that keeps the issue from being opened twice.
     *
     * @param array{id: string, package: string} $advisory
     */
    public static function advisoryTitle(array $advisory): string
    {
        return self::ADVISORY_TITLE_PREFIX . "{$advisory['package']} ({$advisory['id']})";
    }

    /**
     * The one notification of an audit, or null when nothing notable was opened. The advisories of a low severity only get their issue: they are counted, not listed.
     *
     * @param list<array{advisory: array{package: string, id: string, severity: string}, url: string}> $opened
     *
     * @return array{title: string, message: string}|null
     */
    public static function auditAlert(array $opened, string $project): ?array
    {
        $notable = array_values(
            array_filter($opened, static fn (array $item): bool => self::isNotable($item['advisory']['severity'])),
        );

        if ([] === $notable) {
            return null;
        }

        $lines  = array_map(
            self::alertLine(...),
            $notable,
        );
        $silent = count($opened) - count($notable);

        if (0 < $silent) {
            $lines[] = "{$silent} more of a low severity, issue only.";
        }

        return [
            'title'   => 'Security advisories: ' . count($notable) . " new in {$project}",
            'message' => implode("\n", $lines),
        ];
    }

    /**
     * Whether an advisory is worth a push notification: medium and above. Unknown counts as notable. Composer says medium, npm says moderate.
     */
    public static function isNotable(string $severity): bool
    {
        return in_array($severity, [
            '',
            'medium',
            'moderate',
            'high',
            'critical',
        ], true);
    }

    /**
     * Whether an advisory is fixed without waiting for the next weekly update. Composer does not always give a severity: unknown counts as urgent.
     */
    public static function isUrgent(string $severity): bool
    {
        return in_array($severity, [
            '',
            'high',
            'critical',
        ], true);
    }

    /**
     * The line of an advisory in the notification of an audit.
     *
     * @param array{advisory: array{package: string, id: string, severity: string}, url: string} $item
     */
    private static function alertLine(array $item): string
    {
        $advisory = $item['advisory'];
        $severity = self::orElse($advisory['severity'], 'unknown');

        return "{$advisory['package']} ({$advisory['id']}, {$severity}): {$item['url']}";
    }

    private static function autoFixText(string $severity, bool $autoFix): string
    {
        return $autoFix && self::isUrgent($severity)
            ? 'started (an update of the dependencies, taken without waiting)'
            : 'off for this advisory: update by hand';
    }

    private static function orElse(string $value, string $fallback): string
    {
        return '' !== $value
            ? $value
            : $fallback;
    }
}
