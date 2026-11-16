<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Update;

use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * After the publication of this package, start the update of the projects that use it.
 */
final readonly class CascadeRun
{
    /**
     * @param array{groups?: list<string>}|null $cascade The "cascade" of the configuration, null when there is none.
     */
    public function __construct(
        private Shell $shell,
        private Project $project,
        private GitLabApi $api,
        private Network $network,
        private ?array $cascade,
    ) {}

    public function run(): void
    {
        if (null === $this->cascade) {
            $this->shell->log('No cascade configured.');

            return;
        }

        $name    = Json::text($this->project->manifest(), 'name');
        $version = (string) getenv('CI_COMMIT_TAG');

        if ('' === $version) {
            throw new RuntimeException('The cascade runs on the pipeline of a tag.');
        }

        $this->waitForPackagist($name, $version);

        foreach ($this->cascade['groups'] ?? [] as $group) {
            $this->updateGroup($group);
        }
    }

    private function updateGroup(string $group): void
    {
        $projects = $this->api->call(
            'GET',
            '/groups/' . rawurlencode($group) . '/projects?include_subgroups=true&archived=false&per_page=100',
        );

        foreach (Json::rows($projects['data']) as $project) {
            $id   = Json::text($project, 'id');
            $path = Json::text($project, 'path_with_namespace');

            if ((string) getenv('CI_PROJECT_ID') !== $id) {
                $this->updateProject($id, $path);
            }
        }
    }

    /**
     * Opt-in: a project is updated only if it has the same file.
     */
    private function updateProject(string $id, string $path): void
    {
        $file = $this->api->call(
            'GET',
            "/projects/{$id}/repository/files/" . rawurlencode(UpdateConfig::FILE) . '?ref=develop',
        );

        if (! $file['ok']) {
            $this->shell->log("{$path}: no " . UpdateConfig::FILE . ', skipped.');

            return;
        }

        $started = $this->api->startUpdate($id);

        $this->shell->log(
            "{$path}: " . (
                $started['ok']
                    ? 'update started'
                    : "not started ({$started['status']})"
            ) . '.',
        );
    }

    /**
     * Packagist needs a few minutes to list a new version: wait for it instead of waiting for a fixed hour.
     */
    private function waitForPackagist(string $name, string $version): void
    {
        $available = false;

        for ($attempt = 0; 60 > $attempt && ! $available; $attempt++) {
            $available = $this->network->onPackagist($name, $version);

            if (! $available) {
                Sleep::sleep(30);
            }
        }

        if (! $available) {
            throw new RuntimeException("{$name}@{$version} is not on Packagist after 30 minutes.");
        }
    }
}
