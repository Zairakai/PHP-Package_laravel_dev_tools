<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Update;

use Throwable;

/**
 * Open the merge request of develop into main after an update.
 */
final readonly class ReleaseRun
{
    public function __construct(
        private Shell $shell,
        private ComposerAudit $audit,
        private Issues $issues,
        private MergeRequests $mergeRequests,
    ) {}

    public function run(): void
    {
        // The update that was just merged into develop may fix advisories: their issues have no reason to stay open.
        $this->closeObsolete();

        $existing = $this->mergeRequests->opened('&source_branch=develop&target_branch=main');

        if ([] !== $existing) {
            $this->mergeRequests->mergeWhenGreen(Json::int($existing[0], 'iid'));

            return;
        }

        $this->mergeRequests->open(
            'develop',
            'main',
            'Brings the automatic update of the dependencies into main. It is merged when its pipeline succeeds.',
            false,
        );
    }

    /**
     * Close the issues whose advisory is gone from the merged tree. It never stops a release.
     */
    private function closeObsolete(): void
    {
        try {
            $titles = array_map(Advisories::advisoryTitle(...), $this->audit->advisories());

            $token = $this->shell->env('FRESHNESS_TOKEN', $this->shell->env('GITLAB_TOKEN'));

            $this->issues->closeResolved($titles, $token);
        }
        catch (Throwable $throwable) {
            $this->shell->log(
                'The obsolete issues could not be checked: '
                . strtok($this->shell->mask($throwable->getMessage()), "\n"),
            );
        }
    }
}
