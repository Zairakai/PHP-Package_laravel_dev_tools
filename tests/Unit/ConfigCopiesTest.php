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
    public function it_converts_every_kind_of_path_of_the_phpunit_schema(): void
    {
        $xml = <<<'XML_WRAP'
        <?xml version="1.0" encoding="UTF-8"?>
        <!-- <directory>comment/not/a/path</directory> -->
        <phpunit
          xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
          xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
          bootstrap="vendor/autoload.php"
          cacheDirectory="build/cache"
          extensionsDirectory="tools/extensions"
          colors="true">
          <testsuites>
            <testsuite name="Unit">
              <directory prefix="Foo" suffix="Test.php">tests/Unit</directory>
              <file>tests/ExtraTest.php</file>
              <exclude>tests/Unit/Skipped</exclude>
            </testsuite>
          </testsuites>
          <source baseline="build/baseline.xml">
            <include>
              <directory>src</directory>
              <file>app.php</file>
            </include>
            <exclude>
              <directory>src/Generated</directory>
              <file>src/legacy.php</file>
            </exclude>
          </source>
          <coverage>
            <report>
              <html outputDirectory="build/coverage"/>
              <clover outputFile="build/logs/clover.xml"/>
              <text outputFile="php://stdout"/>
              <xml outputDirectory="/var/ci/xml"/>
            </report>
          </coverage>
          <logging>
            <junit outputFile="build/logs/junit.xml"/>
            <testdoxHtml outputFile="C:\ci\testdox.html"/>
          </logging>
          <php>
            <includePath>vendor/lib</includePath>
            <env name="SOME_PATH" value="vendor/autoload.php"/>
            <ini name="error_log" value="build/php.log"/>
          </php>
          <extensions>
            <bootstrap class="Some\Extension"/>
          </extensions>
        </phpunit>
        XML_WRAP;

        $config = ConfigCopies::phpunitForConfig($xml);

        foreach ([
            'xsi:noNamespaceSchemaLocation="../../vendor/phpunit/phpunit/phpunit.xsd"',
            'bootstrap="../../vendor/autoload.php"',
            'cacheDirectory="../../build/cache"',
            'extensionsDirectory="../../tools/extensions"',
            '<directory prefix="Foo" suffix="Test.php">../../tests/Unit</directory>',
            '<file>../../tests/ExtraTest.php</file>',
            '<exclude>../../tests/Unit/Skipped</exclude>',
            'baseline="../../build/baseline.xml"',
            '<directory>../../src</directory>',
            '<file>../../app.php</file>',
            '<directory>../../src/Generated</directory>',
            '<file>../../src/legacy.php</file>',
            'outputDirectory="../../build/coverage"',
            'outputFile="../../build/logs/clover.xml"',
            'outputFile="../../build/logs/junit.xml"',
            '<includePath>../../vendor/lib</includePath>',
        ] as $converted) {
            self::assertStringContainsString($converted, $config);
        }

        foreach ([
            'outputFile="php://stdout"',
            'outputDirectory="/var/ci/xml"',
            'outputFile="C:\ci\testdox.html"',
            '<directory>comment/not/a/path</directory>',
            'value="vendor/autoload.php"',
            'value="build/php.log"',
            '<bootstrap class="Some\Extension"/>',
        ] as $left) {
            self::assertStringContainsString($left, $config, 'not a path of the project');
        }

    }

    #[Test]
    public function it_finds_no_problem_when_the_root_agrees_with_config_dev_tools(): void
    {
        $this->publishEverything();

        self::assertSame([], ConfigCopies::problems($this->root));
    }

    #[Test]
    #[DataProvider('phpunitFilesOfThePackage')]
    public function it_goes_up_two_folders_in_every_phpunit_file_of_the_package(string $file): void
    {
        $xml = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $file);

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
    }

    #[Test]
    public function it_has_nothing_to_compare_in_a_project_without_config_dev_tools(): void
    {
        File::deleteDirectory($this->root . '/config');

        self::assertSame([], ConfigCopies::problems($this->root));
    }

    #[Test]
    public function it_keeps_the_layout_of_the_phpunit_file(): void
    {
        $xml = "<phpunit\n  bootstrap=\"vendor/autoload.php\"\n  colors=\"true\">\n  <testsuites>\n    <testsuite name=\"Unit\">\n      <directory>\n        tests/Unit\n      </directory>\n    </testsuite>\n  </testsuites>\n</phpunit>\n";

        self::assertSame(
            "<phpunit\n  bootstrap=\"../../vendor/autoload.php\"\n  colors=\"true\">\n  <testsuites>\n    <testsuite name=\"Unit\">\n      <directory>\n        ../../tests/Unit\n      </directory>\n    </testsuite>\n  </testsuites>\n</phpunit>\n",
            ConfigCopies::phpunitForConfig($xml),
        );
    }

    #[Test]
    public function it_leaves_a_schema_that_is_an_address_as_it_is(): void
    {
        $xml = '<phpunit xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/12.0/phpunit.xsd" bootstrap="vendor/autoload.php"/>';

        self::assertSame(
            '<phpunit xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/12.0/phpunit.xsd" bootstrap="../../vendor/autoload.php"/>',
            ConfigCopies::phpunitForConfig($xml),
        );
    }

    #[Test]
    public function it_tells_a_file_of_config_dev_tools_that_is_missing(): void
    {
        $this->publishEverything();

        File::delete($this->root . '/config/dev-tools/.markdownlint.json');

        self::assertSame(
            [
                'config/dev-tools/.markdownlint.json is missing',
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
        file_put_contents($this->root . '/phpunit.xml', '<phpunit/>');
        file_put_contents($this->root . '/phpstan.neon', "includes:\n  - baseline.neon\n");

        self::assertSame(
            [
                'rector.php at the root is not read: move its changes to config/dev-tools/rector.php and remove it',
                'phpinsights.php at the root is not read: move its changes to config/dev-tools/phpinsights.php and remove it',
                'phpunit.xml at the root is not read: move its changes to config/dev-tools/phpunit.xml and remove it',
                'phpstan.neon at the root is not read: move its changes to config/dev-tools/phpstan.neon and remove it',
            ],
            ConfigCopies::problems($this->root),
        );
    }

    #[Test]
    public function it_tells_a_file_that_is_missing_at_the_root(): void
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
    public function it_tells_a_pint_file_at_the_root_where_no_tool_reads_it(): void
    {
        $this->publishEverything();

        file_put_contents($this->root . '/pint.json', '{"preset": "psr12"}');

        self::assertSame(
            [
                'pint.json at the root is not read: move its changes to config/dev-tools/pint.json and remove it',
            ],
            ConfigCopies::problems($this->root),
        );
    }

    #[Test]
    public function it_tells_a_thin_file_that_does_not_point_at_config_dev_tools(): void
    {
        $this->publishEverything();

        file_put_contents($this->root . '/.markdownlint.json', '{"extends": "./vendor/zairakai/laravel-dev-tools/config/.markdownlint.json"}');

        self::assertSame(
            [
                '.markdownlint.json at the root does not point at ./config/dev-tools/.markdownlint.json',
            ],
            ConfigCopies::problems($this->root),
        );
    }

    #[Test]
    public function it_tells_an_ignore_file_in_config_dev_tools_that_the_tool_does_not_read(): void
    {
        $this->publishEverything();

        file_put_contents($this->root . '/config/dev-tools/.markdownlintignore', "vendor/\n");

        self::assertSame(
            [
                'config/dev-tools/.markdownlintignore is not read: the file is the one of the root, remove it',
            ],
            ConfigCopies::problems($this->root),
        );
    }

    private function publishEverything(): void
    {
        $published = $this->root . '/config/dev-tools';
        $xml       = '<phpunit bootstrap="../../vendor/autoload.php"><testsuite><directory>../../tests</directory></testsuite></phpunit>';

        file_put_contents($published . '/pint.json', '{"preset": "laravel"}');
        file_put_contents($published . '/phpunit.xml', $xml);
        file_put_contents($published . '/phpstan.neon', "includes:\n  - baseline.neon\n");
        file_put_contents($published . '/.markdownlint.json', '{}');

        file_put_contents($this->root . '/.markdownlintignore', "vendor/\n");
        file_put_contents($this->root . '/.markdownlint.json', '{"extends": "./config/dev-tools/.markdownlint.json"}');
    }
}
