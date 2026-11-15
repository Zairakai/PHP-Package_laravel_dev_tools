<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Services;

use Closure;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Writes on several lines what the handbook asks to spread (standards/php.md): every array that is not empty, a call of
 * more than two arguments and a function of more than two parameters. One element, argument or parameter per line, a
 * trailing comma, the indentation of the line. The destructurings (`[$a, $b] = ...`) and the `list()` and `array()` forms
 * are left as they are.
 *
 * Pint then aligns the `=>`. The source is returned unchanged when it cannot be parsed.
 */
final readonly class CodeSpreader
{
    private const string INDENT = '    ';

    private const int MAX_ROUNDS = 10;

    private const int MIN_SPREAD = 3;

    private Parser $parser;

    public function __construct(?Parser $parser = null)
    {
        $this->parser = $parser ?? new ParserFactory()->createForNewestSupportedVersion();
    }

    /**
     * @return string the source with its arrays and its calls spread, until nothing is left to spread
     */
    public function spread(string $source): string
    {
        for ($round = 0; self::MAX_ROUNDS > $round; $round++) {
            $spread = $this->applyEdits($source, $this->arrayEdits(...));
            $spread = $this->applyEdits($spread, $this->callEdits(...));
            $spread = $this->applyEdits($spread, $this->parameterEdits(...));

            if ($spread === $source) {
                break;
            }

            $source = $spread;
        }

        return $source;
    }

    /**
     * @param Closure(array<Node>, list<string>, string): list<array{start: int, end: int, text: string}> $finder
     */
    private function applyEdits(string $source, Closure $finder): string
    {
        try {
            $nodes = $this->parser->parse($source) ?? [];
        }
        catch (Error) {
            return $source;
        }

        $edits = $this->outermost($finder(
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
     * @param array<Node>  $nodes
     * @param list<string> $lines
     *
     * @return list<array{start: int, end: int, text: string}>
     */
    private function arrayEdits(
        array $nodes,
        array $lines,
        string $source,
    ): array {
        $edits       = [];
        $destructure = $this->destructurings($nodes);

        foreach (new NodeFinder()->findInstanceOf($nodes, Array_::class) as $array) {
            if (! $this->mustSpreadArray($array, $destructure)) {
                continue;
            }

            $edits[] = [
                'start' => $array->getStartFilePos(),
                'end'   => $array->getEndFilePos(),
                'text'  => $this->block(
                    '[',
                    $array->items,
                    ']',
                    $this->indentOf($lines, $array->getStartLine()),
                    $source,
                    $lines,
                ),
            ];
        }

        return $edits;
    }

    /**
     * @param array<Node>  $elements
     * @param list<string> $lines
     */
    private function block(
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
            $shift   = strlen($target) - strlen($this->indentOf($lines, $element->getStartLine()));
            $parts[] = $target . $this->shift(trim($text), $shift) . ',';
        }

        return $open . "\n" . implode("\n", $parts) . "\n" . $indent . $close;
    }

    /**
     * @param array<Node>  $nodes
     * @param list<string> $lines
     *
     * @return list<array{start: int, end: int, text: string}>
     */
    private function callEdits(
        array $nodes,
        array $lines,
        string $source,
    ): array {
        $edits = [];

        foreach ($this->calls($nodes) as $call) {
            $args = $call->args;

            if (! $this->mustSpreadCall($args)) {
                continue;
            }

            $last = $args[array_key_last($args)];

            if (! $last instanceof Arg) {
                continue;
            }

            $rest = substr($source, $last->getEndFilePos() + 1);

            // Only a call that closes right after its last argument (a trailing comma is allowed).
            if (1 !== preg_match(
                '/^\s*,?\s*\)/',
                $rest,
                $closing,
            )) {
                continue;
            }

            $edits[] = [
                'start' => $args[0]->getStartFilePos(),
                'end'   => $last->getEndFilePos() + strlen($closing[0]),
                'text'  => $this->block(
                    '',
                    $args,
                    ')',
                    $this->indentOf($lines, $args[0]->getStartLine()),
                    $source,
                    $lines,
                ),
            ];
        }

        return $edits;
    }

    /**
     * @param array<Node> $nodes
     *
     * @return list<FuncCall|MethodCall|New_|NullsafeMethodCall|StaticCall>
     */
    private function calls(array $nodes): array
    {
        $calls = [];

        foreach (new NodeFinder()->find($nodes, $this->isCall(...)) as $call) {
            if (! $this->isCall($call) || ($call instanceof New_ && ! $call->class instanceof Name)) {
                continue;
            }

            $calls[] = $call;
        }

        return $calls;
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
        $targets = [];

        foreach ($finder->findInstanceOf($nodes, Assign::class) as $assign) {
            $targets[] = $assign->var;
        }

        foreach ($finder->findInstanceOf($nodes, Foreach_::class) as $foreach) {
            $targets[] = $foreach->keyVar;
            $targets[] = $foreach->valueVar;
        }

        $ids = [];

        foreach ($targets as $target) {
            if (! $target instanceof Array_) {
                continue;
            }

            foreach ($finder->findInstanceOf([
                $target,
            ], Array_::class) as $array) {
                $ids[spl_object_id($array)] = true;
            }
        }

        return $ids;
    }

    /**
     * @param list<string> $lines
     */
    private function indentOf(array $lines, int $line): string
    {
        return 1 === preg_match(
            '/^\s*/',
            $lines[$line - 1],
            $matches,
        )
            ? $matches[0]
            : '';
    }

    /**
     * @phpstan-assert-if-true FuncCall|MethodCall|New_|NullsafeMethodCall|StaticCall $node
     */
    private function isCall(Node $node): bool
    {
        return $node instanceof FuncCall
            || $node instanceof MethodCall
            || $node instanceof StaticCall
            || $node instanceof New_
            || $node instanceof NullsafeMethodCall;
    }

    /**
     * A non-empty array written with brackets that is on one line, or that holds a multi-line element on the line of its
     * opening bracket (`['a' => [`). Not a destructuring.
     *
     * @param array<int, true> $destructure
     */
    private function mustSpreadArray(Array_ $array, array $destructure): bool
    {
        $first = $array->items[0] ?? null;

        if (null === $first || in_array(
            null,
            $array->items,
            true,
        )) {
            return false;
        }

        if (isset($destructure[spl_object_id($array)]) || Array_::KIND_SHORT !== $array->getAttribute('kind')) {
            return false;
        }

        return $array->getStartLine() === $array->getEndLine() || $first->getStartLine() === $array->getStartLine();
    }

    /**
     * @param array<Arg|Node\ArgPlaceholder|Node\VariadicPlaceholder> $args
     */
    private function mustSpreadCall(array $args): bool
    {
        $first = $args[0]                     ?? null;
        $last  = $args[array_key_last($args)] ?? null;

        return count($args) >= self::MIN_SPREAD
            && $first instanceof Arg
            && $last instanceof Arg
            && $first->getStartLine() === $last->getEndLine();
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

    /**
     * @param array<Node>  $nodes
     * @param list<string> $lines
     *
     * @return list<array{start: int, end: int, text: string}>
     */
    private function parameterEdits(
        array $nodes,
        array $lines,
        string $source,
    ): array {
        $edits = [];

        foreach (new NodeFinder()->findInstanceOf($nodes, FunctionLike::class) as $function) {
            $params = $function->getParams();
            $first  = $params[0]                       ?? null;
            $last   = $params[array_key_last($params)] ?? null;

            if (count($params) < self::MIN_SPREAD || null === $first || null === $last) {
                continue;
            }

            if ($first->getStartLine() !== $last->getEndLine()) {
                continue;
            }

            if (1 !== preg_match(
                '/^\\s*,?\\s*\\)/',
                substr($source, $last->getEndFilePos() + 1),
                $closing,
            )) {
                continue;
            }

            $edits[] = [
                'start' => $first->getStartFilePos(),
                'end'   => $last->getEndFilePos() + strlen($closing[0]),
                'text'  => $this->block(
                    '',
                    $params,
                    ')',
                    $this->indentOf($lines, $first->getStartLine()),
                    $source,
                    $lines,
                ),
            ];
        }

        return $edits;
    }

    /**
     * Moves the lines of a multi-line element after its first line, by the same number of spaces as the element.
     */
    private function shift(string $text, int $spaces): string
    {
        if (0 === $spaces || ! str_contains($text, "\n")) {
            return $text;
        }

        $lines = explode("\n", $text);
        $first = array_shift($lines);

        foreach ($lines as $index => $line) {
            $lines[$index] = 0 < $spaces
                ? str_repeat(' ', $spaces) . $line
                : (string) preg_replace(
                    '/^ {1,' . -$spaces . '}/',
                    '',
                    $line,
                );
        }

        return $first . "\n" . implode("\n", $lines);
    }
}
