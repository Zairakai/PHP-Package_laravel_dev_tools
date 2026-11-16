<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Services\Spread;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArgPlaceholder;
use PhpParser\Node\VariadicPlaceholder;

/**
 * The calls of more than two arguments that are on one line, to write with one argument per line.
 */
final readonly class CallEdits implements SpreadEdits
{
    /**
     * @param array<Node>  $nodes
     * @param list<string> $lines
     *
     * @return list<array{start: int, end: int, text: string}>
     */
    public function edits(array $nodes, array $lines, string $source): array
    {
        $edits = [];

        foreach (CallNodes::in($nodes) as $call) {
            $args = $call->args;
            $last = $args[array_key_last($args)] ?? null;

            if (
                ! $this->mustSpread($args)
                || ! $last instanceof Arg
            ) {
                continue;
            }

            // Only a call that closes right after its last argument (a trailing comma is allowed).
            $closing = SpreadBlock::closing($source, $last->getEndFilePos());

            if (null === $closing) {
                continue;
            }

            $edits[] = [
                'start' => $args[0]->getStartFilePos(),
                'end'   => $last->getEndFilePos() + strlen($closing),
                'text'  => SpreadBlock::write(
                    '',
                    $args,
                    ')',
                    SpreadBlock::indentOf($lines, $args[0]->getStartLine()),
                    $source,
                    $lines,
                ),
            ];
        }

        return $edits;
    }

    /**
     * @param array<Arg|ArgPlaceholder|VariadicPlaceholder> $args
     */
    private function mustSpread(array $args): bool
    {
        $first = $args[0]                     ?? null;
        $last  = $args[array_key_last($args)] ?? null;

        return count($args) >= SpreadBlock::MIN_SPREAD
            && $first instanceof Arg
            && $last instanceof Arg
            && $first->getStartLine() === $last->getEndLine();
    }
}
