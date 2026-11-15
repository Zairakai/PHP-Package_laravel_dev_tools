<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Tests\Unit;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Zairakai\LaravelDevTools\Services\PintConfigWriter;
use Zairakai\LaravelDevTools\Tests\TestCase;

final class PintConfigWriterTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . '/pint-config-writer-' . uniqid('', true);
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    #[Test]
    public function it_adds_the_exclusions_of_a_layer_and_lets_its_rules_replace_the_ones_of_the_base(): void
    {
        $layer = $this->directory . '/layer.json';

        file_put_contents($layer, '{"rules": {"single_quote": false}, "finder": {"exclude": ["generated"]}}');

        $config = $this->read(PintConfigWriter::write($this->directory, [
            $layer,
        ]));

        self::assertFalse($config['rules']['single_quote']);
        self::assertContains('generated', $config['finder']['exclude']);
        self::assertContains('vendor', $config['finder']['exclude']);
    }

    #[Test]
    public function it_applies_the_layers_in_order(): void
    {
        $first  = $this->directory . '/first.json';
        $second = $this->directory . '/second.json';

        file_put_contents($first, '{"rules": {"single_quote": false}}');
        file_put_contents($second, '{"rules": {"single_quote": true}}');

        $config = $this->read(PintConfigWriter::write($this->directory, [
            $first,
            $second,
        ]));

        self::assertTrue($config['rules']['single_quote']);
    }

    #[Test]
    public function it_gives_the_same_file_when_it_runs_again(): void
    {
        $once  = (string) file_get_contents(PintConfigWriter::write($this->directory, []));
        $again = (string) file_get_contents(PintConfigWriter::write($this->directory, []));

        self::assertSame($once, $again);
    }

    #[Test]
    public function it_indents_with_two_spaces_and_ends_with_a_newline(): void
    {
        $content = (string) file_get_contents(PintConfigWriter::write($this->directory, []));

        self::assertStringStartsWith("{\n  \"preset\"", $content);
        self::assertStringEndsWith("}\n", $content);
        self::assertStringContainsString("  \"rules\": {\n    \"", $content);
        self::assertDoesNotMatchRegularExpression('/^ ( {2})*\S/m', $content);
    }

    #[Test]
    public function it_refuses_a_layer_that_is_not_valid_json(): void
    {
        $layer = $this->directory . '/broken.json';

        file_put_contents($layer, '{not json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not valid JSON');

        PintConfigWriter::write($this->directory, [
            $layer,
        ]);
    }

    #[Test]
    public function it_skips_a_layer_that_does_not_exist(): void
    {
        $with    = $this->read(PintConfigWriter::write($this->directory, [
            $this->directory . '/missing.json',
        ]));
        $without = $this->read(PintConfigWriter::write($this->directory, []));

        self::assertSame($without, $with);
    }

    #[Test]
    public function it_writes_the_pint_json_of_config_dev_tools_from_the_rules_of_php_dev_tools(): void
    {
        $file = PintConfigWriter::write($this->directory, []);

        self::assertSame($this->directory . '/config/dev-tools/pint.json', $file);

        $config = $this->read($file);

        self::assertSame('laravel', $config['preset']);
        self::assertNotEmpty($config['rules']);
    }

    /**
     * @return array<string, mixed>
     */
    private function read(string $file): array
    {
        $config = json_decode(
            (string) file_get_contents($file),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($config);

        return $config;
    }
}
