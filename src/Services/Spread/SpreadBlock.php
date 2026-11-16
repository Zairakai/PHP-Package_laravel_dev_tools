<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Services\Spread;

use PhpParser\Node;

/**
 * Writes the elements of an array, the arguments of a call or the parameters of a function on several lines.
 */
final readonly class SpreadBlock
{
    public const string INDENT = '    ';

    /**
     * A call or a function with fewer than this number of arguments or parameters stays on one line.
     */
    public const int MIN_SPREAD = 3;

    /**
     * The closing parenthesis that follows the end of the last argument or parameter (a trailing comma is allowed),
     * with what lies before it, or null when the call does not close right after it.
     */
    public static function closing(string $source, int $endPosition): ?string
    {
        return 1 === preg_match('/^\s*,?\s*\)/', substr($source, $endPosition + 1), $closing)
            ? $closing[0]
            : null;
    }

    /**
     * @param list<string> $lines
     */
    public static function indentOf(array $lines, int $line): string
    {
        return 1 === preg_match('/^\s*/', $lines[$line - 1], $matches)
            ? $matches[0]
            : '';
    }

    /**
     * The elements one per line, with a trailing comma, in the indentation of the line.
     *
     * @param array<Node>  $elements
     * @param list<string> $lines
     */
    public static function write(
        string $open,
        array $elements,
        string $close,
        string $indent,
        string $source,
        array $lines,
    ): string {
        $target = $indent . self::INDENT;
        $parts  = [];

        foreach ($elements as $element) {
            $text    = substr(
                $source,
                $element->getStartFilePos(),
                $element->getEndFilePos() - $element->getStartFilePos() + 1,
            );
            $shift   = strlen($target) - strlen(self::indentOf($lines, $element->getStartLine()));
            $parts[] = $target . self::shift(trim($text), $shift) . ',';
        }

        return $open . "\n" . implode("\n", $parts) . "\n" . $indent . $close;
    }

    /**
     * Moves the lines of a multi-line element after its first line, by the same number of spaces as the element.
     */
    private static function shift(string $text, int $spaces): string
    {
        if (0 === $spaces || ! str_contains($text, "\n")) {
            return $text;
        }

        $lines = explode("\n", $text);
        $first = array_shift($lines);

        foreach ($lines as $index => $line) {
            $lines[$index] = 0 < $spaces
                ? str_repeat(' ', $spaces) . $line
                : (string) preg_replace('/^ {1,' . -$spaces . '}/', '', $line);
        }

        return $first . "\n" . implode("\n", $lines);
    }
}
