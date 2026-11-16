<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Update;

use RuntimeException;

/**
 * Fail when the change since a ref updates a package of the dependency policy: a range of composer.json or a locked version.
 */
final readonly class PolicyCheckRun
{
    public function __construct(
        private Shell $shell,
        private Project $project,
        private Policy $policy,
    ) {}

    public function run(string $base): void
    {
        if ('' === $base) {
            throw new RuntimeException('Usage: update-dependencies policy-check <ref>');
        }

        $blocked  = PolicyDocument::policyPackages($this->policy->read());
        $problems = PolicyChecks::policyViolations(
            $blocked,
            ...$this->changes($base),
        );

        if ([] !== $problems) {
            foreach ($problems as $problem) {
                $this->shell->error($problem);
            }

            throw new RuntimeException('The change updates a package that the dependency policy holds back.');
        }

        $this->shell->log('No package of the dependency policy is updated.');
    }

    /**
     * What `policyViolations` compares: the manifest and the locked versions, before and after.
     *
     * @return array{array<string, mixed>, array<string, mixed>, array<string, string>, array<string, string>}
     */
    private function changes(string $base): array
    {
        $lock = $this->shell->exec([
            'git',
            'show',
            "{$base}:composer.lock",
        ], null, [
            0,
            128,
        ]);

        return [
            $this->project->manifest($base),
            $this->project->manifest(),
            Manifest::lockVersions(Json::map(json_decode('' === $lock ? '{}' : $lock, true))),
            $this->project->lockVersions(),
        ];
    }
}
