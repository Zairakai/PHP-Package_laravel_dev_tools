<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Services;

/**
 * What the uninstall does with a file of the setup: remove it, or keep it and say why.
 *
 * A file that is still what the package publishes is ours and goes. A file that the project changed is its own: it stays,
 * except the files of config/dev-tools/ when the project asks to remove them all, and the thin files of the root, which
 * point at config/dev-tools/ and would point at nothing.
 */
final class RemovalRules
{
    public const string CONFIG_FOLDER = 'config/dev-tools/';

    private const string BASELINE = 'config/dev-tools/baseline.neon';

    /**
     * Null when the file is not ours to decide on (missing, or handled apart), 'remove', or the reason why it is kept.
     *
     * @param array{file: string, state: string, detail: string} $result
     */
    public static function decide(string $vendorPath, string $basePath, array $result, bool $customized): ?string
    {
        $file = $result['file'];

        if (self::isElsewhere($result)) {
            return null;
        }

        // A symbolic link is the project's: it stays, and no copy of it is kept
        if (is_link($basePath . '/' . $file)) {
            return 'symbolic link';
        }

        $thin     = isset(ConfigCopies::THIN_FILES[$file]);
        $inConfig = str_starts_with($file, self::CONFIG_FOLDER);

        return match (true) {
            $thin     => self::removeIf(self::pointsAtTheConfig($basePath, $file)),
            $inConfig => self::configFile($vendorPath, $basePath, $result, $customized),
            default   => self::removeIf(SetupChecker::SAME === $result['state']),
        };
    }

    /**
     * @param array{file: string, state: string, detail: string} $result
     */
    private static function configFile(string $vendorPath, string $basePath, array $result, bool $customized): string
    {
        return self::removeIf($customized || ! self::holdsTheProjectsOwn($vendorPath, $basePath, $result));
    }

    /**
     * Whether the file of config/dev-tools/ holds something that the project wrote: a changed file, or a baseline that is
     * not the empty one of the package (it holds the errors that the project accepted).
     *
     * @param array{file: string, state: string, detail: string} $result
     */
    private static function holdsTheProjectsOwn(string $vendorPath, string $basePath, array $result): bool
    {
        if (self::BASELINE !== $result['file']) {
            return SetupChecker::DIFFERENT === $result['state'];
        }

        return (string) file_get_contents($basePath . '/' . self::BASELINE)
            !== (string) file_get_contents($vendorPath . '/stubs/baseline.neon');
    }

    /**
     * A file that is missing, or that is edited in place and not removed with its file (composer.json, .gitignore).
     *
     * @param array{file: string, state: string, detail: string} $result
     */
    private static function isElsewhere(array $result): bool
    {
        return SetupChecker::MISSING === $result['state']
            || in_array($result['file'], ['composer.json', '.gitignore'], true);
    }

    private static function pointsAtTheConfig(string $basePath, string $file): bool
    {
        return str_contains(
            (string) file_get_contents($basePath . '/' . $file),
            ConfigCopies::THIN_FILES[$file],
        );
    }

    private static function removeIf(bool $ours): string
    {
        return $ours
            ? 'remove'
            : 'customized';
    }
}
