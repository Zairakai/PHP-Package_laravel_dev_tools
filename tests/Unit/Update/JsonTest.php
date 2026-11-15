<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Tests\Unit\Update;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Zairakai\LaravelDevTools\Update\Json;

final class JsonTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function rowsWithTheDefault(): iterable
    {
        yield 'a text' => [
            [
                'key' => 'value',
            ],
            'value',
        ];

        yield 'a missing key' => [
            [],
            'default',
        ];

        yield 'an empty text' => [
            [
                'key' => '',
            ],
            'default',
        ];

        yield 'a value of another type' => [
            [
                'key' => [
                    'nested',
                ],
            ],
            'default',
        ];

        yield 'a number, read as a text' => [
            [
                'key' => 3,
            ],
            '3',
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    #[Test]
    #[DataProvider('rowsWithTheDefault')]
    public function it_reads_a_text_or_gives_the_default_one(array $row, string $expected): void
    {
        self::assertSame($expected, Json::textOr(
            $row,
            'key',
            'default',
        ));
    }
}
