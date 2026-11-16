<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Services;

use PhpParser\Error;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Zairakai\LaravelDevTools\Services\Spread\ArrayEdits;
use Zairakai\LaravelDevTools\Services\Spread\CallEdits;
use Zairakai\LaravelDevTools\Services\Spread\ParameterEdits;
use Zairakai\LaravelDevTools\Services\Spread\SpreadEdits;

/**
 * Writes on several lines what the handbook asks to spread (standards/php.md): every array that is not empty, a call of
 * more than two arguments and a function of more than two parameters. One element, argument or parameter per line, a
 * trailing comma, the indentation of the line. The destructurings (`[$a, $b] = ...`) and the `list()` and `array()` forms
 * are left as they are.
 *
 * Pint then aligns the `=>`. The source is returned unchanged when it cannot be parsed.
 *
 * What to spread is found by ArrayEdits, CallEdits and ParameterEdits (one kind of node each); this class applies their
 * edits, round after round, until nothing is left to spread.
 */
final readonly class CodeSpreader
{
    private const int MAX_ROUNDS = 10;

    /**
     * @var list<SpreadEdits>
     */
    private array $finders;

    private Parser $parser;

    public function __construct(?Parser $parser = null)
    {
        $this->parser  = $parser ?? new ParserFactory()->createForNewestSupportedVersion();
        $this->finders = [new ArrayEdits(), new CallEdits(), new ParameterEdits()];
    }

    /**
     * @return string the source with its arrays and its calls spread, until nothing is left to spread
     */
    public function spread(string $source): string
    {
        for ($round = 0; self::MAX_ROUNDS > $round; $round++) {
            $spread = $source;

            foreach ($this->finders as $finder) {
                $spread = $this->applyEdits($spread, $finder);
            }

            if ($spread === $source) {
                break;
            }

            $source = $spread;
        }

        return $source;
    }

    private function applyEdits(string $source, SpreadEdits $finder): string
    {
        try {
            $nodes = $this->parser->parse($source) ?? [];
        }
        catch (Error) {
            return $source;
        }

        $edits = $this->outermost($finder->edits(
            $nodes,
            explode("\n", $source),
            $source,
        ));

        // From the end of the source to its start, so that the positions of the next edits stay true.
        usort($edits, static fn (array $a, array $b): int => $b['start'] <=> $a['start']);

        foreach ($edits as $edit) {
            $source = substr(
                $source,
                0,
                $edit['start'],
            ) . $edit['text'] . substr($source, $edit['end'] + 1);
        }

        return $source;
    }

    /**
     * @param list<array{start: int, end: int, text: string}> $edits
     *
     * @return list<array{start: int, end: int, text: string}>
     */
    private function outermost(array $edits): array
    {
        usort($edits, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        $kept = [];
        $end  = -1;

        foreach ($edits as $edit) {
            if ($edit['start'] <= $end) {
                continue;
            }

            $kept[] = $edit;
            $end    = $edit['end'];
        }

        return $kept;
    }
}
