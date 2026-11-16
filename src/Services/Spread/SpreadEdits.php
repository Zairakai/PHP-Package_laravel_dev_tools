<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Services\Spread;

use PhpParser\Node;

/**
 * Finds what to write on several lines in a parsed source: one kind of node for each implementation.
 */
interface SpreadEdits
{
    /**
     * @param array<Node>  $nodes
     * @param list<string> $lines
     *
     * @return list<array{start: int, end: int, text: string}> the positions in the source, and the text to put there
     */
    public function edits(array $nodes, array $lines, string $source): array;
}
