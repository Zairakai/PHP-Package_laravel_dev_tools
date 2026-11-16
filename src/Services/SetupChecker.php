<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Services;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Tells what the setup would publish that the project does not have, or has differently.
 *
 * The files are generated in a temporary folder with the code of the setup itself (ConfigStubPublisher), then compared
 * with the ones of the project. Nothing is written in the project. A file that differs can be a change of the project or
 * a file late on the package: the check cannot tell, it shows the difference.
 */
final class SetupChecker
{
    public const string DIFFERENT = 'different';

    public const string MISSING = 'missing';

    public const string SAME = 'same';

    /**
     * The baseline is the project's own (it accumulates accepted errors): it is only checked for existing.
     */
    private const string BASELINE = 'config/dev-tools/baseline.neon';

    /**
     * The groups of the default publication. The hooks are a git setting, not a file.
     */
    private const array GROUPS = ['quality', 'style', 'testing'];

    /**
     * @return list<array{file: string, state: string, detail: string}>
     */
    public function check(string $vendorPath, string $basePath): array
    {
        return $this->inspect($vendorPath, $basePath, static fn (string $generated, array $results): array => $results);
    }

    /**
     * Generates the files of the setup in a temporary folder, compares them with the project's, calls $then with the
     * folder and the results, then removes the folder. The project is not written here.
     *
     * @template T
     *
     * @param callable(string, list<array{file: string, state: string, detail: string}>): T $then
     *
     * @return T
     */
    public function inspect(string $vendorPath, string $basePath, callable $then): mixed
    {
        $temporary = sys_get_temp_dir() . '/ldt-setup-check-' . bin2hex(random_bytes(6));

        mkdir($temporary, 0755, true);

        try {
            $this->mirrorTheProject($basePath, $temporary);

            $publisher = new ConfigStubPublisher(new SilentPublishOutput());

            foreach (self::GROUPS as $group) {
                $publisher->publishGroup($group, $vendorPath, $temporary, true);
            }

            return $then($temporary, $this->compare($temporary, $basePath));
        }
        finally {
            PlainFile::deleteDirectory($temporary);
        }
    }

    /**
     * @return list<array{file: string, state: string, detail: string}>
     */
    private function compare(string $temporary, string $basePath): array
    {
        $results = [];

        foreach ($this->generated($temporary) as $relative) {
            $mine = $basePath . '/' . $relative;

            if (! is_file($mine)) {
                $results[] = ['file' => $relative, 'state' => self::MISSING, 'detail' => ''];

                continue;
            }

            $generated = $temporary . '/' . $relative;

            if (
                self::BASELINE                === $relative
                || hash_file('sha256', $mine) === hash_file('sha256', $generated)
            ) {
                $results[] = ['file' => $relative, 'state' => self::SAME, 'detail' => ''];

                continue;
            }

            $results[] = [
                'file'   => $relative,
                'state'  => self::DIFFERENT,
                'detail' => FileDifference::of(
                    (string) file_get_contents($mine),
                    (string) file_get_contents($generated),
                    $relative,
                ),
            ];
        }

        return $results;
    }

    /**
     * The files that the publication wrote, from the root of the project, in order.
     *
     * @return list<string>
     */
    private function generated(string $temporary): array
    {
        $inputs   = ['composer.json', 'artisan'];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS),
        );
        $files    = [];

        foreach ($iterator as $leaf) {
            $files[] = $iterator->getSubPathname();
        }

        $files = array_filter(
            $files,
            static fn (string $relative): bool => ! in_array($relative, $inputs, true)
                && ! str_starts_with($relative, ConfigBackup::FOLDER . '/'),
        );

        sort($files);

        return $files;
    }

    /**
     * What the publication reads in the project to choose its files: the type of the project and its folders.
     */
    private function mirrorTheProject(string $basePath, string $temporary): void
    {
        foreach (['composer.json', 'artisan'] as $file) {
            if (is_file($basePath . '/' . $file)) {
                copy($basePath . '/' . $file, $temporary . '/' . $file);
            }
        }

        foreach (['app', 'database', 'routes', 'src', 'node_modules/@zairakai/js-dev-tools'] as $folder) {
            if (is_dir($basePath . '/' . $folder)) {
                mkdir($temporary . '/' . $folder, 0755, true);
            }
        }
    }
}
