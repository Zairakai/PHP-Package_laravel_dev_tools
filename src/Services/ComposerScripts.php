<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Services;

use RuntimeException;

/**
 * Merges the Composer scripts of the package (stubs/composer-scripts.json) into the composer.json of a project.
 *
 * A script that the project does not have is added. A script that the project has with another value is kept, unless
 * $force is given. composer.json keeps the indentation that it has, and is not written when nothing changes.
 */
final class ComposerScripts
{
    /**
     * @param callable(string): mixed|null $beforeWrite called with the path of composer.json, before it is written over
     *
     * @return array{added: list<string>, updated: list<string>, skipped: list<string>}
     *
     * @throws RuntimeException when composer.json or the stub is missing or cannot be read
     */
    public static function merge(
        string $composerJsonPath,
        string $stubPath,
        bool $force = false,
        ?callable $beforeWrite = null,
    ): array {
        [$original, $composer] = ComposerJson::read($composerJsonPath);
        [, $stub]              = ComposerJson::read($stubPath);
        $merged                = self::mergeScripts(ComposerJson::scripts($composer), $stub, $force);

        $composer['scripts'] = $merged['scripts'];
        $content             = ComposerJson::encode($composer, $original);

        if ($content !== $original) {
            if (null !== $beforeWrite) {
                $beforeWrite($composerJsonPath);
            }

            file_put_contents($composerJsonPath, $content);
        }

        return [
            'added'   => $merged['added'],
            'updated' => $merged['updated'],
            'skipped' => $merged['skipped'],
        ];
    }

    /**
     * @param array<string, mixed> $scripts
     * @param array<string, mixed> $stub
     *
     * @return array{scripts: array<string, mixed>, added: list<string>, updated: list<string>, skipped: list<string>}
     */
    private static function mergeScripts(array $scripts, array $stub, bool $force): array
    {
        $scriptsAfter = $scripts;
        $status       = [
            'added'   => [],
            'updated' => [],
            'skipped' => [],
        ];

        foreach ($stub as $name => $value) {
            $name              = (string) $name;
            [$change, $script] = self::resolve($scripts[$name] ?? null, $value, $force);

            if (null !== $change) {
                $status[$change][] = $name;
            }

            $scriptsAfter[$name] = $script;
        }

        return [
            'scripts' => $scriptsAfter,
            'added'   => $status['added'],
            'updated' => $status['updated'],
            'skipped' => $status['skipped'],
        ];
    }

    /**
     * What becomes of a script of the package: added, updated (with $force), skipped (the project changed it), or left.
     *
     * @return array{'added'|'updated'|'skipped'|null, mixed} the change, and the value that the script has in the end
     */
    private static function resolve(mixed $existing, mixed $value, bool $force): array
    {
        if (null === $existing) {
            return ['added', $value];
        }

        if ($existing === $value) {
            return [null, $existing];
        }

        return $force
            ? ['updated', $value]
            : ['skipped', $existing];
    }
}
