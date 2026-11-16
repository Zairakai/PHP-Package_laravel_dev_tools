<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Update;

use RuntimeException;

/**
 * Update Composer, open a merge request into develop and merge it when its pipeline succeeds.
 */
final readonly class UpdateRun
{
    /**
     * The files an update may change.
     */
    private const array FILES = [
        'composer.json',
        'composer.lock',
    ];

    private const string PIPELINE_FILE = '.gitlab-ci.yml';

    public function __construct(
        private Shell $shell,
        private Project $project,
        private Signing $signing,
        private Policy $policy,
        private MergeRequests $mergeRequests,
    ) {}

    public function run(): void
    {
        if ($this->mergeRequests->hasOpenUpdate()) {
            $this->shell->log('An update is already open: nothing to do.');

            return;
        }

        $this->signing->configure();
        $before = $this->project->manifest('HEAD');
        $branch = MergeRequests::BRANCH_PREFIX . '-' . date('Ymd');
        $this->shell->exec([
            'git',
            'switch',
            '-C',
            $branch,
        ]);

        $this->updateComposer();

        $rows  = Manifest::diffDependencies($before, $this->project->manifest());
        $files = $this->changedFiles();

        if ($this->shell->exec([
            'git',
            'status',
            '--porcelain',
            '--',
            ...$files,
        ]) === '') {
            $this->shell->log('Everything is up to date.');

            return;
        }

        $this->propose($branch, $files, Manifest::formatChanges($rows));
    }

    /**
     * @param list<string>          $held
     * @param array<string, string> $locked
     */
    private function assertHeld(array $held, array $locked): void
    {
        foreach ($held as $name) {
            if (($this->project->lockVersions()[$name] ?? '') !== $locked[$name]) {
                throw new RuntimeException(
                    "{$name} is held back by the dependency policy but the update changed it: nothing is merged.",
                );
            }
        }
    }

    /**
     * The files to commit: the manifest and the lock file, and the pipeline file when only its ref changed.
     *
     * @return list<string>
     */
    private function changedFiles(): array
    {
        $files = self::FILES;

        // The one exception: the ref of the shared pipeline follows the installed version of the tools.
        if (
            is_file($this->project->directory() . '/' . self::PIPELINE_FILE)
            && Manifest::isRefOnlyChange($this->shell->exec([
                'git',
                'diff',
                '-U0',
                '--',
                self::PIPELINE_FILE,
            ]))
        ) {
            $files[] = self::PIPELINE_FILE;
        }

        return $files;
    }

    /**
     * The normalization is optional: not every project has the plugin.
     */
    private function normalize(): void
    {
        try {
            $this->shell->exec([
                'composer',
                'normalize',
                '--no-interaction',
            ]);
        }
        catch (RuntimeException) {
            $this->shell->log('composer normalize is not available: skipped.');
        }
    }

    /**
     * @param list<string> $files
     */
    private function propose(string $branch, array $files, string $table): void
    {
        // Only the manifest and the lock file: the plugin of laravel-dev-tools republishes other files during composer update, and the cache of Composer on the runner is not ignored by git.
        $this->shell->exec([
            'git',
            'add',
            ...$files,
        ]);
        $this->shell->exec([
            'git',
            'commit',
            '-m',
            MergeRequests::COMMIT_SUBJECT,
            '-m',
            "Minor and patch updates.\n\n{$table}",
        ]);
        $this->shell->exec([
            'git',
            'push',
            $this->shell->remoteUrl(),
            "HEAD:refs/heads/{$branch}",
        ]);

        $this->mergeRequests->open(
            $branch,
            'develop',
            'Automatic update. The pipeline of this merge request is the gate: it is merged when it succeeds, '
            . "and stays open otherwise. Major versions are never applied here.\n\n{$table}",
            true,
        );
    }

    /**
     * Update the requirements, but the ones of the dependency policy, which keep their locked version.
     */
    private function updateComposer(): void
    {
        $blocked = PolicyDocument::policyPackages($this->policy->read());
        $locked  = $this->project->lockVersions();
        $held    = array_values(array_intersect($blocked, array_keys($locked)));
        $all     = array_values(array_diff(array_keys($locked), $held));

        // A package of the policy keeps its locked version, also when another package requires it: the others are updated by name, with their dependencies.
        $this->shell->exec([
            'composer',
            'update',
            '-W',
            '--no-interaction',
            '--no-progress',
            ...Versions::heldConstraints($held, $locked),
            ...([] === $held ? [] : $all),
        ]);

        $this->assertHeld($held, $locked);

        $this->shell->exec([
            'composer',
            'bump',
            '--no-interaction',
        ]);

        $this->normalize();
    }
}
