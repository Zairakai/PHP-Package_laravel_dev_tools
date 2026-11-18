<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Services;

use JsonException;
use ReflectionClass;
use RuntimeException;
use StanislasPoisson\DevTools\Pint;

/**
 * Writes the pint.json of a project.
 *
 * Pint cannot extend another file. The file of config/dev-tools/ is the complete one that the project edits: it is
 * generated once from the rules of stanislas-poisson/php-dev-tools, then from each layer given (the Laravel layer of
 * this package), a later layer replacing the rules of an earlier one and adding its exclusions. The extension of an
 * editor takes its path in its settings.
 */
final class PintConfigWriter
{
    /**
     * @param string       $root   absolute path to the root of the project
     * @param list<string> $layers absolute paths of the layers, in order; a file that does not exist is skipped
     * @param string       $target path of the file to write, from the root of the project
     *
     * @return string the path of the file written
     *
     * @throws RuntimeException when a file cannot be read or written
     */
    public static function write(
        string $root,
        array $layers,
        string $target = 'config/dev-tools/pint.json',
    ): string {
        $config = self::read(self::rulesFile());

        foreach ($layers as $layer) {
            if (is_file($layer)) {
                $config = Pint::merge($config, self::read($layer));
            }
        }

        $file = $root . '/' . $target;

        if (! is_dir(dirname($file))) {
            mkdir(
                dirname($file),
                0755,
                true,
            );
        }

        if (false === file_put_contents($file, self::encode($config))) {
            throw new RuntimeException(sprintf('The file "%s" cannot be written.', $file));
        }

        return $file;
    }

    /**
     * The JSON of the repositories is indented with 2 spaces (editorconfig), while PHP indents with 4.
     *
     * @param array<string, mixed> $config
     */
    private static function encode(array $config): string
    {
        $json = json_encode($config, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $halved = preg_replace_callback(
            '/^ +/m',
            static fn (array $m): string => str_repeat(' ', intdiv(strlen($m[0]), 2)),
            $json,
        );

        return $halved . "\n";
    }

    /**
     * @return array<string, mixed>
     */
    private static function read(string $path): array
    {
        try {
            $content = json_decode(
                (string) file_get_contents($path),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        }
        catch (JsonException $jsonException) {
            throw new RuntimeException(
                sprintf(
                    'The file "%s" is not valid JSON: %s',
                    $path,
                    $jsonException->getMessage(),
                ),
                0,
                $jsonException,
            );
        }

        return is_array($content) ? array_filter(
            $content,
            is_string(...),
            ARRAY_FILTER_USE_KEY,
        ) : [];
    }

    /**
     * The base rules of php-dev-tools: the file of its package, found through its class.
     *
     * @throws RuntimeException when php-dev-tools is not installed
     */
    private static function rulesFile(): string
    {
        if (! class_exists(Pint::class)) {
            throw new RuntimeException('The Pint rules of php-dev-tools were not found - run: composer install');
        }

        return dirname((string) new ReflectionClass(Pint::class)->getFileName(), 2) . '/config/pint.json';
    }
}
