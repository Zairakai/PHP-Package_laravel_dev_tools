<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Services\Spread;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\NodeFinder;

/**
 * The calls of a parsed source: a function, a method, a static method, a nullsafe method, and `new` of a named class.
 */
final readonly class CallNodes
{
    /**
     * @param array<Node> $nodes
     *
     * @return list<FuncCall|MethodCall|New_|NullsafeMethodCall|StaticCall>
     */
    public static function in(array $nodes): array
    {
        $calls = [];

        foreach (new NodeFinder()->find($nodes, self::isCall(...)) as $call) {
            if (
                self::isCall($call)
                && self::isNamed($call)
            ) {
                $calls[] = $call;
            }
        }

        return $calls;
    }

    /**
     * @phpstan-assert-if-true FuncCall|MethodCall|New_|NullsafeMethodCall|StaticCall $node
     */
    private static function isCall(Node $node): bool
    {
        return $node instanceof FuncCall
            || $node instanceof MethodCall
            || $node instanceof StaticCall
            || $node instanceof New_
            || $node instanceof NullsafeMethodCall;
    }

    /**
     * Not `new class {…}` or `new $name`: only a call whose name is written.
     */
    private static function isNamed(Node $call): bool
    {
        return ! $call instanceof New_
            || $call->class instanceof Name;
    }
}
