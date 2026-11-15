<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Tests\Unit;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Zairakai\LaravelDevTools\Services\ConfigCopies;
use Zairakai\LaravelDevTools\Tests\TestCase;

/**
 * The files of the root that an IDE reads, and their agreement with config/dev-tools/.
 */
final class ConfigCopiesTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/config-copies-' . uniqid('', true);

        mkdir(
            $this->root . '/config/dev-tools',
            0755,
            true,
        );
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function phpunitFilesOfThePackage(): iterable
    {
        foreach ([
            'config/phpunit.xml',
            'config/phpunit-app.xml',
        ] as $file) {
            yield $file => [
                $file,
            ];
        }
    }

    #[Test]
    public function it_calls_the_callback_only_for_a_copy_that_exists_and_differs(): void
    {
        $this->publishEverything();

        file_put_contents($this->root . '/pint.json', 'stale');

        $seen = [];

        ConfigCopies::sync(
            $this->root,
            [],
            static function (string $file) use (&$seen): void {
                $seen[] = basename($file);
            },
        );

        self::assertSame([
            'pint.json',
        ], $seen, 'the copies that already agree are not saved');
    }

    #[Test]
    public function it_finds_no_problem_when_the_root_agrees_with_config_dev_tools(): void
    {
        $this->publishEverything();

        self::assertSame([], ConfigCopies::problems($this->root));
    }

    #[Test]
    #[DataProvider('phpunitFilesOfThePackage')]
    public function it_gives_back_the_phpunit_file_of_the_package_from_the_one_of_config_dev_tools(string $file): void
    {
        $xml = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $file);

        self::assertSame($xml, ConfigCopies::phpunitForTheRoot(ConfigCopies::phpunitForConfig($xml)));
        self::assertStringContainsString('../../', ConfigCopies::phpunitForConfig($xml));
    }

    #[Test]
    public function it_goes_up_two_folders_in_the_phpunit_file_of_config_dev_tools(): void
    {
        $xml = <<<'XML'
            <phpunit bootstrap="vendor/autoload.php" cacheDirectory="build/cache">
              <testsuites><testsuite name="Unit"><directory>tests/Unit</directory></testsuite></testsuites>
              <coverage><report>
                <html outputDirectory="build/coverage"/>
                <text outputFile="php://stdout"/>
                <clover outputFile="build/logs/clover.xml"/>
              </report></coverage>
            </phpunit>
            XML;

        $config = ConfigCopies::phpunitForConfig($xml);

        self::assertStringContainsString('bootstrap="../../vendor/autoload.php"', $config);
        self::assertStringContainsString('cacheDirectory="../../build/cache"', $config);
        self::assertStringContainsString('<directory>../../tests/Unit</directory>', $config);
        self::assertStringContainsString('outputDirectory="../../build/coverage"', $config);
        self::assertStringContainsString('outputFile="../../build/logs/clover.xml"', $config);
        self::assertStringContainsString(
            'outputFile="php://stdout"',
            $config,
            'a stream is not a path of the project',
        );
        self::assertSame($xml, ConfigCopies::phpunitForTheRoot($config));
    }

    #[Test]
    public function it_has_nothing_to_compare_in_a_project_without_config_dev_tools(): void
    {
        File::deleteDirectory($this->root . '/config');

        self::assertSame([], ConfigCopies::problems($this->root));
    }

    #[Test]
    public function it_tells_a_copy_that_differs_from_the_file_of_config_dev_tools(): void
    {
        $this->publishEverything();

        file_put_contents($this->root . '/pint.json', '{"preset": "psr12"}');

        self::assertSame(
            [
                'pint.json at the root differs from config/dev-tools/pint.json',
            ],
            ConfigCopies::problems($this->root),
        );
    }

    #[Test]
    public function it_tells_a_copy_that_is_missing_at_the_root(): void
    {
        $this->publishEverything();

        File::delete($this->root . '/.markdownlintignore');

        self::assertSame(
            [
                '.markdownlintignore is missing at the root, where the editor reads it',
            ],
            ConfigCopies::problems($this->root),
        );
    }

    #[Test]
    public function it_tells_a_file_of_config_dev_tools_that_is_missing(): void
    {
        $this->publishEverything();

        File::delete($this->root . '/config/dev-tools/phpunit.xml');

        self::assertSame(
            [
                'config/dev-tools/phpunit.xml is missing',
            ],
            ConfigCopies::problems($this->root),
        );
    }

    #[Test]
    public function it_tells_a_file_of_the_root_that_no_editor_reads(): void
    {
        $this->publishEverything();

        file_put_contents($this->root . '/rector.php', '<?php return [];');
        file_put_contents($this->root . '/phpinsights.php', '<?php return [];');

        self::assertSame(
            [
                'rector.php at the root is not read: move its changes to config/dev-tools/rector.php and remove it',
                'phpinsights.php at the root is not read: move its changes to config/dev-tools/phpinsights.php and remove it',
            ],
            ConfigCopies::problems($this->root),
        );
    }

    #[Test]
    public function it_tells_a_thin_file_that_does_not_point_at_config_dev_tools(): void
    {
        $this->publishEverything();

        file_put_contents($this->root . '/phpstan.neon', "includes:\n  - vendor/zairakai/laravel-dev-tools/config/library.neon\n");
        file_put_contents($this->root . '/.markdownlint.json', '{"extends": "./vendor/zairakai/laravel-dev-tools/config/.markdownlint.json"}');

        self::assertSame(
            [
                'phpstan.neon at the root does not point at config/dev-tools/phpstan.neon',
                '.markdownlint.json at the root does not point at ./config/dev-tools/.markdownlint.json',
            ],
            ConfigCopies::problems($this->root),
        );
    }

    #[Test]
    public function it_writes_again_the_copies_of_the_root_from_config_dev_tools(): void
    {
        $this->publishEverything();

        file_put_contents($this->root . '/pint.json', 'stale');
        file_put_contents($this->root . '/.markdownlintignore', 'stale');

        self::assertSame([
            'pint.json',
        ], ConfigCopies::sync($this->root, [
            'pint.json',
        ]));
        self::assertSame('{"preset": "laravel"}', file_get_contents($this->root . '/pint.json'));
        self::assertSame(
            'stale',
            file_get_contents($this->root . '/.markdownlintignore'),
            'only the names given',
        );

        ConfigCopies::sync($this->root);

        self::assertSame([], ConfigCopies::problems($this->root));
    }

    private function publishEverything(): void
    {
        $published = $this->root . '/config/dev-tools';
        $xml       = '<phpunit bootstrap="../../vendor/autoload.php"><testsuite><directory>../../tests</directory></testsuite></phpunit>';

        file_put_contents($published . '/pint.json', '{"preset": "laravel"}');
        file_put_contents($published . '/phpunit.xml', $xml);
        file_put_contents($published . '/.markdownlintignore', "vendor/\n");
        file_put_contents($published . '/phpstan.neon', "includes:\n  - baseline.neon\n");
        file_put_contents($published . '/.markdownlint.json', '{}');

        file_put_contents($this->root . '/pint.json', '{"preset": "laravel"}');
        file_put_contents($this->root . '/phpunit.xml', ConfigCopies::phpunitForTheRoot($xml));
        file_put_contents($this->root . '/.markdownlintignore', "vendor/\n");
        file_put_contents($this->root . '/phpstan.neon', "includes:\n  - config/dev-tools/phpstan.neon\n");
        file_put_contents($this->root . '/.markdownlint.json', '{"extends": "./config/dev-tools/.markdownlint.json"}');
    }
}
