<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Services\Spread;

use PhpParser\Node;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Param;
use PhpParser\NodeFinder;

/**
 * The functions of more than two parameters that are on one line, to write with one parameter per line.
 */
final readonly class ParameterEdits implements SpreadEdits
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

        foreach (new NodeFinder()->findInstanceOf($nodes, FunctionLike::class) as $function) {
            $params = array_values($function->getParams());

            if (! $this->mustSpread($params)) {
                continue;
            }

            $first   = $params[0];
            $last    = $params[count($params) - 1];
            $closing = SpreadBlock::closing($source, $last->getEndFilePos());

            if (null === $closing) {
                continue;
            }

            $edits[] = [
                'start' => $first->getStartFilePos(),
                'end'   => $last->getEndFilePos() + strlen($closing),
                'text'  => SpreadBlock::write(
                    '',
                    $params,
                    ')',
                    SpreadBlock::indentOf($lines, $first->getStartLine()),
                    $source,
                    $lines,
                ),
            ];
        }

        return $edits;
    }

    /**
     * @param list<Param> $params
     */
    private function mustSpread(array $params): bool
    {
        return count($params) >= SpreadBlock::MIN_SPREAD
            && $params[0]->getStartLine() === $params[count($params) - 1]->getEndLine();
    }
}
