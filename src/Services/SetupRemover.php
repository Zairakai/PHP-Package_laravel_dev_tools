<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Services;

use RuntimeException;

/**
 * Removes the files of the setup from a project, and only the ones that are still what the package publishes.
 *
 * A file that the project changed is kept and named. The files of config/dev-tools/ that the project changed go only when
 * $customized is given. Everything that is removed is copied first in `.dev-tools-backup/<date>/`, under its path from the
 * root (the same folder as --force and setup-update): copying that folder at the root puts everything back.
 */
final readonly class SetupRemover
{
    public function __construct(private SetupChecker $checker = new SetupChecker()) {}

    /**
     * @return array{removed: list<string>, kept: list<array{file: string, reason: string}>, backup: ?string}
     */
    public function remove(string $vendorPath, string $basePath, bool $dryRun = false, bool $customized = false): array
    {
        $backup    = new ConfigBackup($basePath);
        $decisions = $this->decisions($vendorPath, $basePath, $customized);
        $removing  = array_filter($decisions, static fn (array $item): bool => 'remove' === $item['decision']);

        if (! $dryRun) {
            array_walk($removing, fn (array $item) => $this->delete($basePath, $item['result'], $backup));
            $this->removeEmptyFolder($basePath);
        }

        return [
            'removed' => [
                ...array_values(array_map(static fn (array $item): string => $item['result']['file'], $removing)),
                ...$this->projectFiles($vendorPath, $basePath, $dryRun, $backup),
            ],
            'kept'    => $this->kept($decisions),
            'backup'  => $backup->directoryUsed(),
        ];
    }

    /**
     * What the uninstall decides for each file of the setup that the project has.
     *
     * @return list<array{result: array{file: string, state: string, detail: string}, decision: string}>
     */
    private function decisions(string $vendorPath, string $basePath, bool $customized): array
    {
        $decisions = [];

        foreach ($this->checker->check($vendorPath, $basePath) as $result) {
            $decision = RemovalRules::decide($vendorPath, $basePath, $result, $customized);

            if (null !== $decision) {
                $decisions[] = [
                    'result'   => $result,
                    'decision' => $decision,
                ];
            }
        }

        return $decisions;
    }

    /**
     * @param array{file: string, state: string, detail: string} $result
     */
    private function delete(string $basePath, array $result, ConfigBackup $backup): void
    {
        $path = $basePath . '/' . $result['file'];

        // Everything that goes is copied first: the folder of the backup is what puts it back
        $backup->save($path);
        PlainFile::delete($path);
    }

    private function gitignore(string $basePath, bool $dryRun, ConfigBackup $backup): bool
    {
        $file = $basePath . '/.gitignore';
        $text = is_file($file)
            ? GitignoreBlock::removed((string) file_get_contents($file))
            : null;

        if (null === $text) {
            return false;
        }

        if (! $dryRun) {
            $backup->save($file);
            file_put_contents($file, $text);
        }

        return true;
    }

    /**
     * @param list<array{result: array{file: string, state: string, detail: string}, decision: string}> $decisions
     *
     * @return list<array{file: string, reason: string}>
     */
    private function kept(array $decisions): array
    {
        return array_values(array_map(
            static fn (array $item): array => [
                'file'   => $item['result']['file'],
                'reason' => $item['decision'],
            ],
            array_filter($decisions, static fn (array $item): bool => 'remove' !== $item['decision']),
        ));
    }

    /**
     * The scripts that the setup added to composer.json and the block that it added to .gitignore: they are edited in
     * place, so they are removed from the file, not with it.
     *
     * @return list<string>
     */
    private function projectFiles(string $vendorPath, string $basePath, bool $dryRun, ConfigBackup $backup): array
    {
        $removed = [];
        $scripts = $this->scripts($vendorPath, $basePath, $dryRun, $backup);

        if ([] !== $scripts) {
            $removed[] = sprintf('composer.json (%d scripts of the package)', count($scripts));
        }

        if ($this->gitignore($basePath, $dryRun, $backup)) {
            $removed[] = '.gitignore (block of the dev-tools)';
        }

        return $removed;
    }

    private function removeEmptyFolder(string $basePath): void
    {
        $folder = $basePath . '/' . RemovalRules::CONFIG_FOLDER;

        if (
            is_dir($folder)
            && [] === array_diff((array) scandir($folder), ['.', '..'])
        ) {
            rmdir($folder);
        }
    }

    /**
     * @return list<string>
     */
    private function scripts(string $vendorPath, string $basePath, bool $dryRun, ConfigBackup $backup): array
    {
        $composer = $basePath . '/composer.json';
        $stub     = $vendorPath . '/stubs/composer-scripts.json';

        try {
            return $dryRun
                ? ComposerScriptsRemoval::removable($composer, $stub)
                : ComposerScriptsRemoval::remove($composer, $stub, $backup->save(...));
        }
        catch (RuntimeException) {
            return [];
        }
    }
}
