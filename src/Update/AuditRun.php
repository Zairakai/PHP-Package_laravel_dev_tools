<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Update;

/**
 * Look for security advisories in the lock file. One issue per advisory, never twice, closed when the advisory is gone.
 * With AUTO_FIX=true, an advisory that is high or critical starts an update of the dependencies (an unknown severity counts as a medium one).
 */
final readonly class AuditRun
{
    /**
     * The labels of the issue of an advisory, before its priority.
     */
    private const array ISSUE_LABELS = [
        'Area::Security',
        'Automated',
        'Kind::Security',
    ];

    public function __construct(
        private Shell $shell,
        private Project $project,
        private ComposerAudit $audit,
        private Issues $issues,
        private MergeRequests $mergeRequests,
        private GitLabApi $api,
        private Network $network,
    ) {}

    public function run(): void
    {
        $found = $this->audit->advisories();
        $auto  = getenv('AUTO_FIX') === 'true';
        $token = $this->shell->env('FRESHNESS_TOKEN', $this->shell->env('GITLAB_TOKEN'));

        $this->shell->log(count($found) . ' security advisory(ies) found.');

        $result = $this->openIssues($found, $auto, $token);

        // One message per audit, whatever the number of new advisories.
        $alert = Advisories::auditAlert($result['opened'], (string) getenv('CI_PROJECT_PATH'));

        if (null !== $alert) {
            $this->network->notify($alert['title'], $alert['message']);
        }

        $this->issues->closeResolved($result['titles'], $token);
        $this->startFix($auto, $result['urgent'], $token);
    }

    /**
     * Why no update is started, or null when one is.
     */
    private function noFixReason(bool $auto, bool $urgent): ?string
    {
        return match (true) {
            ! $auto                                 => 'AUTO_FIX is off: update by hand.',
            ! $urgent                               => 'Nothing urgent: the weekly update takes the rest.',
            $this->mergeRequests->hasOpenUpdate()   => 'An update is already open: nothing to start.',
            default                                 => null,
        };
    }

    /**
     * @param array{id: string, package: string, title: string, link: string, cve: string, severity: string, range: string} $advisory
     * @param array<string, mixed>                                                                                          $lock
     *
     * @return array{advisory: array{id: string, package: string, title: string, link: string, cve: string, severity: string, range: string}, url: string}|null
     */
    private function openIssue(array $advisory, string $title, array $lock, bool $auto, string $token): ?array
    {
        if ($this->issues->exists($title, $token)) {
            $this->shell->log("Issue already open: {$title}");

            return null;
        }

        // Inherited from a package of the group: the issue is in the package that brings it, and the update of this project takes the fix when there is one.
        if (PackageGraph::isInherited($lock, $this->project->manifest(), $advisory['package'])) {
            $this->shell->log("Inherited from the group, followed in the package that brings it: {$title}");

            return null;
        }

        $url = $this->issues->create(
            $title,
            Advisories::advisoryIssueBody(
                $advisory,
                (string) getenv('CI_PIPELINE_URL'),
                $auto,
            ),
            Advisories::advisoryLabels($advisory, self::ISSUE_LABELS),
            $token,
        );
        $this->shell->log("Issue created: {$url}");

        return [
            'advisory' => $advisory,
            'url'      => $url,
        ];
    }

    /**
     * One issue for each advisory that has none yet.
     *
     * @param list<array{id: string, package: string, title: string, link: string, cve: string, severity: string, range: string}> $found
     *
     * @return array{titles: list<string>, opened: list<array{advisory: array{id: string, package: string, title: string, link: string, cve: string, severity: string, range: string}, url: string}>, urgent: bool}
     */
    private function openIssues(array $found, bool $auto, string $token): array
    {
        $lock   = $this->project->lock();
        $titles = [];
        $opened = [];
        $urgent = false;

        foreach ($found as $advisory) {
            $title    = Advisories::advisoryTitle($advisory);
            $titles[] = $title;
            $urgent   = $urgent || Advisories::isUrgent($advisory['severity']);
            $item     = $this->openIssue($advisory, $title, $lock, $auto, $token);

            if (null !== $item) {
                $opened[] = $item;
            }
        }

        return [
            'titles' => $titles,
            'opened' => $opened,
            'urgent' => $urgent,
        ];
    }

    private function startFix(bool $auto, bool $urgent, string $token): void
    {
        $reason = $this->noFixReason($auto, $urgent);

        if (null !== $reason) {
            $this->shell->log($reason);

            return;
        }

        $started = $this->api->startUpdate((string) getenv('CI_PROJECT_ID'), $token);

        $this->shell->log(
            $started['ok']
                ? 'Update started.'
                : "The update could not be started ({$started['status']}).",
        );
    }
}
