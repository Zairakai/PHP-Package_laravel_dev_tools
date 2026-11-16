<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Services\Spread;

use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\NodeFinder;

/**
 * The arrays to write on several lines: every array that is not empty, written with brackets, that is not a
 * destructuring.
 */
final readonly class ArrayEdits implements SpreadEdits
{
    /**
     * @param array<Node>  $nodes
     * @param list<string> $lines
     *
     * @return list<array{start: int, end: int, text: string}>
     */
    public function edits(array $nodes, array $lines, string $source): array
    {
        $destructure = $this->destructurings($nodes);
        $edits       = [];

        foreach (new NodeFinder()->findInstanceOf($nodes, Array_::class) as $array) {
            if (! $this->mustSpread($array, $destructure)) {
                continue;
            }

            $edits[] = [
                'start' => $array->getStartFilePos(),
                'end'   => $array->getEndFilePos(),
                'text'  => SpreadBlock::write(
                    '[',
                    $array->items,
                    ']',
                    SpreadBlock::indentOf($lines, $array->getStartLine()),
                    $source,
                    $lines,
                ),
            ];
        }

        return $edits;
    }

    /**
     * The arrays that are the target of an assignment or of a foreach (`[$a, $b] = ...`), the nested ones included.
     *
     * @param array<Node> $nodes
     *
     * @return array<int, true>
     */
    private function destructurings(array $nodes): array
    {
        $finder  = new NodeFinder();
        $targets = array_map(
            static fn (Assign $assign): Node => $assign->var,
            $finder->findInstanceOf($nodes, Assign::class),
        );

        foreach ($finder->findInstanceOf($nodes, Foreach_::class) as $foreach) {
            $targets = [...$targets, $foreach->keyVar, $foreach->valueVar];
        }

        $ids = [];

        foreach (array_filter($targets, static fn (?Node $target): bool => $target instanceof Array_) as $target) {
            foreach ($finder->findInstanceOf([$target], Array_::class) as $array) {
                $ids[spl_object_id($array)] = true;
            }
        }

        return $ids;
    }

    /**
     * Written with brackets, and with no hole in it (`[, $a]`).
     */
    private function isPlain(Array_ $array): bool
    {
        return Array_::KIND_SHORT === $array->getAttribute('kind')
            && ! in_array(null, $array->items, true);
    }

    /**
     * A non-empty array written with brackets that is on one line, or that holds a multi-line element on the line of its
     * opening bracket (`['a' => [`). Not a destructuring.
     *
     * @param array<int, true> $destructure
     */
    private function mustSpread(Array_ $array, array $destructure): bool
    {
        $first = $array->items[0] ?? null;

        if (
            null === $first
            || ! $this->isPlain($array)
            || isset($destructure[spl_object_id($array)])
        ) {
            return false;
        }

        return $array->getStartLine() === $array->getEndLine()
            || $first->getStartLine() === $array->getStartLine();
    }
}
