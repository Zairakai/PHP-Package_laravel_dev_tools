<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Update;

use RuntimeException;

/**
 * Rewrite the ignore rules of .github/dependabot.yml from the dependency policy. The workflow of the repository commits
 * the result.
 */
final readonly class DependabotRun
{
    private const string FILE = '.github/dependabot.yml';

    public function __construct(
        private Shell $shell,
        private Project $project,
        private Policy $policy,
    ) {}

    public function run(): void
    {
        $file = $this->project->directory() . '/' . self::FILE;

        if (! is_file($file)) {
            throw new RuntimeException(self::FILE . ' does not exist.');
        }

        $names  = PolicyDocument::policyPackages($this->policy->read());
        $text   = (string) file_get_contents($file);
        $synced = Manifest::syncDependabot(
            $text,
            $names,
            'composer',
        );

        if (null === $synced) {
            throw new RuntimeException(
                self::FILE . ' has no block "# BEGIN dependency-policy (composer)" '
                . '... "# END dependency-policy (composer)".',
            );
        }

        if ($synced === $text) {
            $this->shell->log('The ignore rules of Dependabot are in sync with the policy.');

            return;
        }

        file_put_contents($file, $synced);
        $list = [] === $names
            ? 'none'
            : implode(', ', $names);

        $this->shell->log('The ignore rules of Dependabot are updated: ' . $list . '.');
    }
}
