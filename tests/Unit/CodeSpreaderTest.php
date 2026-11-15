<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Zairakai\LaravelDevTools\Services\CodeSpreader;

final class CodeSpreaderTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function spreads(): iterable
    {
        yield 'an array of three elements' => [
            "<?php\n\$a = ['x', 'y', 'z'];\n",
            "<?php\n\$a = [\n    'x',\n    'y',\n    'z',\n];\n",
        ];

        yield 'an array of one element' => [
            "<?php\n\$a = ['x'];\n",
            "<?php\n\$a = [\n    'x',\n];\n",
        ];

        yield 'an array of two elements' => [
            "<?php\n\$a = ['x', 'y'];\n",
            "<?php\n\$a = [\n    'x',\n    'y',\n];\n",
        ];

        yield 'an array with keys' => [
            "<?php\n\$a = ['a' => 1, 'b' => 2, 'c' => 3];\n",
            "<?php\n\$a = [\n    'a' => 1,\n    'b' => 2,\n    'c' => 3,\n];\n",
        ];

        yield 'the indentation of the line is kept' => [
            "<?php\nif (true) {\n    \$a = [1, 2, 3];\n}\n",
            "<?php\nif (true) {\n    \$a = [\n        1,\n        2,\n        3,\n    ];\n}\n",
        ];

        yield 'a call of three arguments' => [
            "<?php\nfoo(\$a, \$b, \$c);\n",
            "<?php\nfoo(\n    \$a,\n    \$b,\n    \$c,\n);\n",
        ];

        yield 'a method call and a static call' => [
            "<?php\n\$this->foo(1, 2, 3);\nBar::baz(1, 2, 3);\n",
            "<?php\n\$this->foo(\n    1,\n    2,\n    3,\n);\nBar::baz(\n    1,\n    2,\n    3,\n);\n",
        ];

        yield 'an object creation' => [
            "<?php\n\$o = new Foo(1, 2, 3);\n",
            "<?php\n\$o = new Foo(\n    1,\n    2,\n    3,\n);\n",
        ];

        yield 'a trailing comma is not doubled' => [
            "<?php\nfoo(\$a, \$b, \$c,);\n",
            "<?php\nfoo(\n    \$a,\n    \$b,\n    \$c,\n);\n",
        ];

        yield 'an array that holds a spread array is spread too' => [
            "<?php\n\$a = ['http' => [1, 2, 3]];\n",
            "<?php\n\$a = [\n    'http' => [\n        1,\n        2,\n        3,\n    ],\n];\n",
        ];

        yield 'an array in a call' => [
            "<?php\nfoo(\$a, [1, 2, 3]);\n",
            "<?php\nfoo(\$a, [\n    1,\n    2,\n    3,\n]);\n",
        ];

        yield 'a function of three parameters' => [
            "<?php\nfunction f(int \$a, string \$b, bool \$c): void\n{\n}\n",
            "<?php\nfunction f(\n    int \$a,\n    string \$b,\n    bool \$c,\n): void\n{\n}\n",
        ];

        yield 'a method of three parameters, with a default value' => [
            "<?php\nclass A\n{\n    public function f(int \$a, ?string \$b = null, array \$c = []): void\n    {\n    }\n}\n",
            "<?php\nclass A\n{\n    public function f(\n        int \$a,\n        ?string \$b = null,\n        array \$c = [],\n    ): void\n    {\n    }\n}\n",
        ];

        yield 'a closure of three parameters' => [
            "<?php\n\$f = static function (int \$a, int \$b, int \$c): int {\n    return \$a;\n};\n",
            "<?php\n\$f = static function (\n    int \$a,\n    int \$b,\n    int \$c,\n): int {\n    return \$a;\n};\n",
        ];

        yield 'named arguments' => [
            "<?php\nfoo(a: 1, b: 2, c: 3);\n",
            "<?php\nfoo(\n    a: 1,\n    b: 2,\n    c: 3,\n);\n",
        ];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function untouched(): iterable
    {
        yield 'an empty array' => [
            "<?php\n\$a = [];\n",
        ];

        yield 'a call of two arguments' => [
            "<?php\nfoo(\$a, \$b);\n",
        ];

        yield 'a call whose arguments are already on several lines' => [
            "<?php\nfoo(\n    \$a,\n    \$b,\n    \$c,\n);\n",
        ];

        yield 'an array already on several lines' => [
            "<?php\n\$a = [\n    1,\n    2,\n    3,\n];\n",
        ];

        yield 'a destructuring with a gap' => [
            "<?php\n[, \$b, \$c] = \$list;\n",
        ];

        yield 'a destructuring' => [
            "<?php\n[\$a, \$b, \$c] = \$list;\n",
        ];

        yield 'a destructuring in a foreach' => [
            "<?php\nforeach (\$rows as [\$a, \$b]) {\n}\n",
        ];

        yield 'a list()' => [
            "<?php\nlist(\$a, \$b) = \$pair;\n",
        ];

        yield 'an array() written the long way' => [
            "<?php\n\$a = array(1, 2, 3);\n",
        ];

        yield 'a function of two parameters' => [
            "<?php\nfunction f(int \$a, int \$b): void\n{\n}\n",
        ];

        yield 'a source that cannot be parsed' => [
            "<?php\nfoo(1, 2, 3;\n",
        ];
    }

    #[Test]
    #[DataProvider('spreads')]
    public function it_gives_the_same_result_when_it_runs_again(string $source, string $expected): void
    {
        $spreader = new CodeSpreader();

        self::assertSame($expected, $spreader->spread($expected));
        self::assertSame($expected, $spreader->spread($spreader->spread($source)));
    }

    #[Test]
    #[DataProvider('untouched')]
    public function it_leaves_the_rest_as_it_is(string $source): void
    {
        self::assertSame($source, new CodeSpreader()->spread($source));
    }

    #[Test]
    public function it_spreads_a_call_that_sits_in_an_array_element(): void
    {
        $spread = new CodeSpreader()->spread("<?php\n\$a = ['k' => foo(1, 2, 3), 'j' => 1];\n");

        self::assertStringContainsString("    'k' => foo(\n        1,\n        2,\n        3,\n    ),", $spread);
    }

    #[Test]
    #[DataProvider('spreads')]
    public function it_spreads_what_the_handbook_asks_to_spread(string $source, string $expected): void
    {
        self::assertSame($expected, new CodeSpreader()->spread($source));
    }
}
