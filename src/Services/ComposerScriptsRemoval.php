<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Services;

use RuntimeException;

/**
 * Takes the Composer scripts of the package out of the composer.json of a project: the ones that still equal the scripts
 * of the package. A script that the project changed is its own.
 */
final class ComposerScriptsRemoval
{
    /**
     * The scripts of the project that equal the ones of the package.
     *
     * @return list<string>
     *
     * @throws RuntimeException when composer.json or the stub is missing or cannot be read
     */
    public static function removable(string $composerJsonPath, string $stubPath): array
    {
        [, $composer] = ComposerJson::read($composerJsonPath);
        [, $stub]     = ComposerJson::read($stubPath);

        return array_values(array_map(strval(...), array_keys(array_filter(
            ComposerJson::scripts($composer),
            static fn (mixed $value, string $name): bool => array_key_exists($name, $stub) && $stub[$name] === $value,
            ARRAY_FILTER_USE_BOTH,
        ))));
    }

    /**
     * @param callable(string): mixed|null $beforeWrite called with the path of composer.json, before it is written over
     *
     * @return list<string> the names of the scripts removed
     *
     * @throws RuntimeException when composer.json or the stub is missing or cannot be read
     */
    public static function remove(string $composerJsonPath, string $stubPath, ?callable $beforeWrite = null): array
    {
        $removed = self::removable($composerJsonPath, $stubPath);

        if ([] === $removed) {
            return [];
        }

        [$original, $composer] = ComposerJson::read($composerJsonPath);
        $kept                  = array_diff_key(ComposerJson::scripts($composer), array_flip($removed));

        if ([] === $kept) {
            unset($composer['scripts']);
        }
        else {
            $composer['scripts'] = $kept;
        }

        if (null !== $beforeWrite) {
            $beforeWrite($composerJsonPath);
        }

        file_put_contents($composerJsonPath, ComposerJson::encode($composer, $original));

        return $removed;
    }
}
