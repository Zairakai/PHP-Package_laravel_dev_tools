<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Services;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Zairakai\LaravelDevTools\Contracts\PublishOutput;

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

            $publisher = new ConfigStubPublisher(new class implements PublishOutput
            {
                public function error(string $message): void {}

                public function task(string $description): void {}

                public function twoColumnDetail(string $first, string $second = ''): void {}

                public function warn(string $message): void {}
            });

            foreach (self::GROUPS as $group) {
                $publisher->publishGroup($group, $vendorPath, $temporary, true);
            }

            return $then($temporary, $this->compare($temporary, $basePath));
        }
        finally {
            $this->remove($temporary);
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

            if (self::BASELINE === $relative || hash_file('sha256', $mine) === hash_file('sha256', $temporary . '/' . $relative)) {
                $results[] = ['file' => $relative, 'state' => self::SAME, 'detail' => ''];

                continue;
            }

            $results[] = [
                'file'   => $relative,
                'state'  => self::DIFFERENT,
                'detail' => $this->difference((string) file_get_contents($mine), (string) file_get_contents($temporary . '/' . $relative), $relative),
            ];
        }

        return $results;
    }

    /**
     * What differs: the keys of a JSON file (the rules of pint.json), or the number of lines of another file.
     */
    private function difference(string $project, string $package, string $file): string
    {
        if (str_ends_with($file, '.json')) {
            $mine   = json_decode($project, true);
            $theirs = json_decode($package, true);

            if (is_array($mine) && is_array($theirs)) {
                return $this->jsonDifference($this->flatten($mine), $this->flatten($theirs));
            }
        }

        $mine   = explode("\n", $project);
        $theirs = explode("\n", $package);

        return sprintf(
            '%d line(s) only in the project, %d line(s) only in the package',
            count(array_diff($mine, $theirs)),
            count(array_diff($theirs, $mine)),
        );
    }

    /**
     * The leaves of a decoded JSON, by dotted path, as JSON text.
     *
     * @param array<mixed> $data
     *
     * @return array<string, string>
     */
    private function flatten(array $data, string $prefix = ''): array
    {
        $flat = [];

        foreach ($data as $key => $value) {
            $path = '' === $prefix ? (string) $key : $prefix . '.' . $key;

            if (is_array($value) && ! array_is_list($value)) {
                $flat += $this->flatten($value, $path);

                continue;
            }

            $flat[$path] = (string) json_encode($value);
        }

        return $flat;
    }

    /**
     * The files that the publication wrote, from the root of the project, in order.
     *
     * @return list<string>
     */
    private function generated(string $temporary): array
    {
        $files  = [];
        $inputs = ['composer.json', 'artisan'];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                continue;
            }

            $relative = ltrim(substr($file->getPathname(), strlen($temporary)), '/');

            if (in_array($relative, $inputs, true) || str_starts_with($relative, ConfigBackup::FOLDER . '/')) {
                continue;
            }

            $files[] = $relative;
        }

        sort($files);

        return $files;
    }

    /**
     * @param array<string, string> $project
     * @param array<string, string> $package
     */
    private function jsonDifference(array $project, array $package): string
    {
        $parts = [];

        $onlyProject = array_keys(array_diff_key($project, $package));
        $onlyPackage = array_keys(array_diff_key($package, $project));
        $changed     = array_keys(array_filter(
            array_intersect_key($project, $package),
            static fn (string $value, string $key): bool => $value !== $package[$key],
            ARRAY_FILTER_USE_BOTH,
        ));

        if ([] !== $onlyProject) {
            $parts[] = 'only in the project: ' . implode(', ', $onlyProject);
        }

        if ([] !== $onlyPackage) {
            $parts[] = 'only in the package: ' . implode(', ', $onlyPackage);
        }

        if ([] !== $changed) {
            $parts[] = 'changed: ' . implode(', ', $changed);
        }

        return implode('; ', $parts);
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

    private function remove(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $entry) {
            if ($entry instanceof SplFileInfo) {
                if ($entry->isDir()) {
                    rmdir($entry->getPathname());

                    continue;
                }

                PlainFile::delete($entry->getPathname());
            }
        }

        rmdir($directory);
    }
}
