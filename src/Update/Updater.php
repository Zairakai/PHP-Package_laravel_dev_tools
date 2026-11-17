<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Update;

use RuntimeException;

/**
 * Automatic updates of the requirements of a PHP package of the zairakai group, in GitLab CI.
 *
 * - update: update Composer, open a merge request into develop and merge it when its pipeline succeeds (UpdateRun).
 * - release: open the merge request of develop into main after an update (ReleaseRun).
 * - tag: tag main when a published range changed since the last tag (TagRun).
 * - cascade: after the publication of this package, start the update of the projects that use it (CascadeRun).
 * - audit: look for security advisories, open one issue per advisory and, with AUTO_FIX=true, start an update to fix
 *   them (AuditRun).
 * - audit-check: read the report of `composer audit --format=json` and fail on an advisory that the daily audit fixes at
 *   once (high or critical): the same level as `audit`, for the blocking audit of every pipeline (AuditCheckRun).
 * - policy: in the repository dependency-policy, lift the entries of php.json whose condition is met (PolicyWatchRun).
 * - policy-check: fail when the change since a ref updates a package of the dependency policy (PolicyCheckRun).
 * - dependabot-sync: rewrite the ignore rules of .github/dependabot.yml from the dependency policy (DependabotRun).
 *
 * It reads `.dependency-update.json` at the root of the project, like the JavaScript tool of the group. This class reads
 * the configuration and starts the mode that is asked for.
 */
final class Updater
{
    /**
     * @var array{reject: list<string>, cooldown: string, tag: array{when: string, level: string}, cascade: ?array{groups?: list<string>}}
     */
    private array $config;

    public function __construct(private readonly string $directory)
    {
        $user = [];

        if (is_file($this->directory . '/' . UpdateConfig::FILE)) {
            /** @var array<string, mixed> $user */
            $user = json_decode(
                (string) file_get_contents($this->directory . '/' . UpdateConfig::FILE),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        }

        $this->config = UpdateConfig::config($user);

        // Composer has no cooling period or exclusion: refuse to ignore a setting silently.
        if ([] !== $this->config['reject']) {
            throw new RuntimeException(
                '"reject" is not supported for a Composer project: remove it from ' . UpdateConfig::FILE . '.',
            );
        }
    }

    public function run(string $mode, string $argument = ''): void
    {
        $shell   = new ProcessShell($this->directory);
        $project = new Project($shell, $this->directory);
        $policy  = new Policy($shell, new WebNetwork());

        // The audit of a pipeline only reads the report of `composer audit`: it needs no token.
        if ('audit-check' === $mode) {
            new AuditCheckRun($shell)->run($argument);

            return;
        }

        // The check only reads: it runs anywhere, GitHub included.
        if ('policy-check' === $mode) {
            new PolicyCheckRun($shell, $project, $policy)->run($argument);

            return;
        }

        if ('dependabot-sync' === $mode) {
            new DependabotRun($shell, $project, $policy)->run();

            return;
        }

        $this->assertEnvironment($shell);

        $this->runMode($mode, $shell, $project, $policy);
    }

    private function assertEnvironment(Shell $shell): void
    {
        $envTagsName = [
            'GITLAB_TOKEN',
            'CI_PROJECT_ID',
            'CI_PROJECT_PATH',
            'CI_SERVER_HOST',
        ];

        foreach ($envTagsName as $name) {
            if ('' === $shell->env($name)) {
                throw new RuntimeException(
                    "{$name} is not set: run this in GitLab CI, with the group variable GITLAB_TOKEN.",
                );
            }
        }

        // The runner clones the project with another user than the one of the job: Git refuses it otherwise.
        $shell->exec([
            'git',
            'config',
            '--global',
            '--add',
            'safe.directory',
            $this->directory,
        ]);
    }

    private function runMode(string $mode, Shell $shell, Project $project, Policy $policy): void
    {
        $api           = new CurlGitLabApi($shell);
        $network       = new WebNetwork();
        $issues        = new Issues($api, $shell);
        $mergeRequests = new MergeRequests($api, $shell);
        $signing       = new Signing($shell);
        $audit         = new ComposerAudit($shell);

        match ($mode) {
            'update'  => new UpdateRun($shell, $project, $signing, $policy, $mergeRequests)->run(),
            'release' => new ReleaseRun($shell, $audit, $issues, $mergeRequests)->run(),
            'tag'     => new TagRun($shell, $project, $signing, $this->config)->run(),
            'cascade' => new CascadeRun($shell, $project, $api, $network, $this->config['cascade'])->run(),
            'audit'   => new AuditRun($shell, $project, $audit, $issues, $mergeRequests, $api, $network)->run(),
            'policy'  => new PolicyWatchRun($shell, $project, $signing, $network, $mergeRequests)->run(),
            default   => throw new RuntimeException(
                'Usage: update-dependencies update | release | tag | cascade | audit | audit-check <report> | policy '
                . '| policy-check <ref> | dependabot-sync',
            ),
        };
    }
}
