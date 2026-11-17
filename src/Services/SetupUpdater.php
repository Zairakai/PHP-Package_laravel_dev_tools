<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Services;

/**
 * Publishes again the files that SetupChecker reports as missing or different, and only those.
 *
 * A file of the project that is replaced is copied first in .dev-tools-backup/ (ConfigBackup, the folder that --force
 * uses). No merge: the project puts its own settings back from the copy. A symbolic link is left alone.
 */
final readonly class SetupUpdater
{
    public function __construct(private SetupChecker $checker = new SetupChecker()) {}

    /**
     * @return array{created: list<string>, replaced: list<string>, kept: list<string>, backup: ?string}
     */
    public function update(string $vendorPath, string $basePath, bool $dryRun = false): array
    {
        return $this->checker->inspect(
            $vendorPath,
            $basePath,
            function (string $generated, array $results) use ($basePath, $dryRun): array {
                $backup = new ConfigBackup($basePath);
                $done   = ['created' => [], 'replaced' => [], 'kept' => [], 'backup' => null];

                foreach ($results as $result) {
                    if (SetupChecker::SAME === $result['state']) {
                        continue;
                    }

                    $target = $basePath . '/' . $result['file'];

                    // A symbolic link is the project's: writing in it would write in what it points at, and no copy is kept
                    if (is_link($target)) {
                        $done['kept'][] = $result['file'];

                        continue;
                    }

                    $key    = SetupChecker::MISSING === $result['state']
                        ? 'created'
                        : 'replaced';

                    $done[$key][] = $result['file'];

                    if ($dryRun) {
                        continue;
                    }

                    if ('replaced' === $key) {
                        $backup->save($target);
                    }

                    if (! is_dir(dirname($target))) {
                        mkdir(dirname($target), 0755, true);
                    }

                    copy($generated . '/' . $result['file'], $target);
                }

                $done['backup'] = $backup->directoryUsed();

                return $done;
            },
        );
    }
}
