<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Update;

use RuntimeException;

/**
 * In the repository dependency-policy: lift the entries of php.json whose condition is met, with a merge request into main.
 */
final readonly class PolicyWatchRun
{
    private const string BRANCH_PREFIX = 'chore/unblock';

    public function __construct(
        private Shell $shell,
        private Project $project,
        private Signing $signing,
        private Network $network,
        private MergeRequests $mergeRequests,
    ) {}

    public function run(): void
    {
        $file = $this->project->directory() . '/' . Policy::FILE;
        $doc  = $this->document($file);

        $lifted = $this->lifted($doc);

        if ([] === $lifted) {
            $this->shell->log('No entry can be lifted.');

            return;
        }

        if ($this->hasOpenRequest()) {
            $this->shell->log('A merge request that lifts an entry is already open.');

            return;
        }

        $this->propose($file, $doc, $lifted);
    }

    /**
     * @param list<array<string, mixed>> $lifted
     */
    private function details(array $lifted): string
    {
        return implode(
            '; ',
            array_map(
                static fn (array $entry): string => Json::text($entry, 'package') . ' ' . Json::text(
                    Json::map($entry['unblock'] ?? null),
                    'version',
                ) . ' (' . implode(
                    ', ',
                    Json::strings(Json::map($entry['unblock'] ?? null)['peers'] ?? null),
                ) . ')',
                $lifted,
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function document(string $file): array
    {
        if (! is_file($file)) {
            throw new RuntimeException(
                Policy::FILE . ' is not in the current directory: run this in the repository dependency-policy.',
            );
        }

        $doc    = Json::map(json_decode(
            (string) file_get_contents($file),
            true,
            512,
            JSON_THROW_ON_ERROR,
        ));
        $errors = PolicyDocument::validatePolicy($doc);

        if ([] !== $errors) {
            throw new RuntimeException(Policy::FILE . ' is not valid: ' . implode(' ', $errors));
        }

        return $doc;
    }

    private function hasOpenRequest(): bool
    {
        return array_filter(
            $this->mergeRequests->opened(),
            static fn (array $request): bool => str_starts_with(
                Json::text($request, 'source_branch'),
                self::BRANCH_PREFIX,
            ),
        ) !== [];
    }

    /**
     * The entries whose condition is met: every peer now accepts the version in its range.
     *
     * @param array<string, mixed> $doc
     *
     * @return list<array<string, mixed>>
     */
    private function lifted(array $doc): array
    {
        $lifted = [];

        foreach (Json::rows($doc['entries'] ?? []) as $entry) {
            $peers  = Json::strings(Json::map($entry['unblock'] ?? null)['peers'] ?? null);
            $ranges = [];

            foreach ($peers as $peer) {
                $ranges[$peer] = Releases::peerRange(
                    $this->network->packagistReleases($peer),
                    Json::text($entry, 'package'),
                );
            }

            if (PolicyChecks::isLifted($entry, $ranges)) {
                $lifted[] = $entry;
            }
        }

        return $lifted;
    }

    /**
     * @param array<string, mixed>       $doc
     * @param list<array<string, mixed>> $lifted
     */
    private function propose(string $file, array $doc, array $lifted): void
    {
        $names   = array_map(static fn (array $entry): string => Json::text($entry, 'package'), $lifted);
        $list    = implode(', ', $names);
        $branch  = self::BRANCH_PREFIX . '-' . date('Ymd');
        $subject = "chore(policy): unblock {$list}";
        $details = $this->details($lifted);

        $this->signing->configure();
        $this->shell->exec([
            'git',
            'switch',
            '-C',
            $branch,
        ]);
        file_put_contents($file, PolicyDocument::encodePolicy(PolicyDocument::withoutEntries($doc, $names)));
        $this->shell->exec([
            'git',
            'add',
            Policy::FILE,
        ]);
        $this->shell->exec([
            'git',
            'commit',
            '-m',
            $subject,
            '-m',
            'The ranges of the packages that held it back now accept it.',
        ]);
        $this->shell->exec([
            'git',
            'push',
            $this->shell->remoteUrl(),
            "HEAD:refs/heads/{$branch}",
        ]);

        $this->mergeRequests->open(
            $branch,
            'main',
            "The condition of the entry is met: {$details}. It is merged when its pipeline succeeds, "
            . 'and the update tools take the package at their next run.',
            true,
            $subject,
        );
        $this->network->notify('Dependency policy: block lifted', "{$list}: {$details}");
    }
}
