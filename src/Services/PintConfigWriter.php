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
 * Pint cannot extend another file, and the editor reads only the pint.json of the root: that file is a copy. It is
 * generated from the rules of stanislas-poisson/php-dev-tools, then from each layer given (the Laravel layer of this
 * package, then the changes of the project), a later layer replacing the rules of an earlier one and adding its
 * exclusions. The copy is never edited by hand: it is written again each time the tools run.
 */
final class PintConfigWriter
{
    /**
     * @param string       $root   absolute path to the root of the project
     * @param list<string> $layers absolute paths of the layers, in order; a file that does not exist is skipped
     *
     * @return string the path of the file written
     *
     * @throws RuntimeException when a file cannot be read or written
     */
    public static function write(string $root, array $layers): string
    {
        $pintFile = (string) new ReflectionClass(Pint::class)->getFileName();
        $config   = self::read(dirname($pintFile, 2) . '/config/pint.json');

        foreach ($layers as $layer) {
            if (is_file($layer)) {
                $config = Pint::merge($config, self::read($layer));
            }
        }

        $file = $root . '/pint.json';

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
}
