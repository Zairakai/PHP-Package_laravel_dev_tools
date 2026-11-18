<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PhpInsightsRequirementsTest extends TestCase
{
    private const array VARIABLES = [
        'INSIGHTS_MIN_QUALITY',
        'INSIGHTS_MIN_COMPLEXITY',
        'INSIGHTS_MIN_ARCHITECTURE',
        'INSIGHTS_MIN_STYLE',
    ];

    protected function tearDown(): void
    {
        foreach (self::VARIABLES as $variable) {
            putenv($variable);
        }

        parent::tearDown();
    }

    #[Test]
    public function it_ignores_a_variable_that_is_not_a_number_or_is_empty(): void
    {
        putenv('INSIGHTS_MIN_QUALITY=high');
        putenv('INSIGHTS_MIN_COMPLEXITY=');

        $requirements = $this->requirements();

        self::assertSame(80, $requirements['min-quality']);
        self::assertSame(40, $requirements['min-complexity']);
    }

    #[Test]
    public function it_keeps_the_requirements_of_the_package_without_a_variable(): void
    {
        self::assertSame(
            [
                'min-quality'      => 80,
                'min-complexity'   => 40,
                'min-architecture' => 75,
                'min-style'        => 0,
            ],
            $this->requirements(),
        );
    }

    #[Test]
    public function it_reads_each_requirement_from_its_variable(): void
    {
        putenv('INSIGHTS_MIN_QUALITY=90');
        putenv('INSIGHTS_MIN_COMPLEXITY=60');
        putenv('INSIGHTS_MIN_ARCHITECTURE=85');
        putenv('INSIGHTS_MIN_STYLE=70');

        self::assertSame(
            [
                'min-quality'      => 90,
                'min-complexity'   => 60,
                'min-architecture' => 85,
                'min-style'        => 70,
            ],
            $this->requirements(),
        );
    }

    /**
     * @return array<string, int>
     */
    private function requirements(): array
    {
        $config = require dirname(__DIR__, 2) . '/config/phpinsights.php';

        // The other keys (disable-security-check) are added by php-dev-tools, not by the environment
        return array_intersect_key(
            $config['requirements'],
            array_flip(['min-quality', 'min-complexity', 'min-architecture', 'min-style']),
        );
    }
}
