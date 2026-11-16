<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Update;

/**
 * Tag main when a published range changed since the last tag.
 */
final readonly class TagRun
{
    /**
     * @param array{tag: array{when: string, level: string}} $config
     */
    public function __construct(
        private Shell $shell,
        private Project $project,
        private Signing $signing,
        private array $config,
    ) {}

    public function run(): void
    {
        $this->shell->exec([
            'git',
            'fetch',
            '--tags',
            '--quiet',
        ]);
        $last = Releases::latestTag($this->lines($this->shell->exec([
            'git',
            'tag',
            '--list',
        ])));

        $plan = ReleasePlan::planTag(
            $this->config,
            $last,
            $this->commitsSince($last),
            null !== $last
                ? $this->project->manifest($last)
                : null,
            $this->project->manifest(),
        );

        if (null === $plan['version']) {
            $this->shell->log("No tag: {$plan['reason']}.");

            return;
        }

        $this->publish($plan['version'], (string) $last, $plan['reason']);
    }

    /**
     * The commits of the update since the last tag.
     *
     * @return list<string>
     */
    private function commitsSince(?string $last): array
    {
        if (null === $last) {
            return [];
        }

        // git reads a basic regular expression: the parentheses of the subject are literal.
        $subject = MergeRequests::COMMIT_SUBJECT;

        return $this->lines($this->shell->exec([
            'git',
            'log',
            "{$last}..HEAD",
            '--format=%s',
            "--grep=^{$subject}",
        ]));
    }

    /**
     * @return list<string>
     */
    private function lines(string $output): array
    {
        return array_values(array_filter(explode("\n", $output)));
    }

    private function publish(string $version, string $last, string $reason): void
    {
        $this->signing->configure();
        $this->shell->exec([
            'git',
            'tag',
            '-s',
            $version,
            '-m',
            $version,
            (string) getenv('CI_COMMIT_SHA'),
        ]);
        $this->shell->exec([
            'git',
            'push',
            $this->shell->remoteUrl(),
            "refs/tags/{$version}",
        ]);

        $this->shell->log(
            "Tag {$version} signed and pushed (last tag {$last}): {$reason}. Its pipeline publishes the package.",
        );
    }
}
