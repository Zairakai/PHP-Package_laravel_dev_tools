<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Zairakai\LaravelDevTools\Services\ConfigStubPublisher;
use Zairakai\LaravelDevTools\Services\PlainFile;
use Zairakai\LaravelDevTools\Services\SilentPublishOutput;

/**
 * The files that every project has from the setup: the Makefile, .editorconfig, the Composer scripts and .gitignore.
 */
final class BaseFilesPublisherTest extends TestCase
{
    private string $project;

    private string $vendor;

    protected function setUp(): void
    {
        $this->vendor  = dirname(__DIR__, 2);
        $this->project = sys_get_temp_dir() . '/ldt-base-' . bin2hex(random_bytes(6));

        mkdir($this->project, 0755, true);
        file_put_contents($this->project . '/composer.json', "{\n  \"name\": \"acme/sample\"\n}\n");
        file_put_contents($this->project . '/.gitignore', "vendor/\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->project));
    }

    #[Test]
    public function a_makefile_that_the_project_changed_is_left_alone_and_replaced_with_force_after_a_copy(): void
    {
        file_put_contents($this->project . '/Makefile', "mine:\n");

        $this->publish('makefile');

        $this->assertSame("mine:\n", $this->read('Makefile'));

        $this->publish('makefile', true);

        $this->assertFileEquals($this->vendor . '/stubs/Makefile', $this->project . '/Makefile');
        $this->assertSame("mine:\n", file_get_contents((glob($this->project . '/.dev-tools-backup/*/Makefile') ?: [''])[0]));
    }

    #[Test]
    public function a_project_without_composer_json_is_told_and_nothing_is_written(): void
    {
        PlainFile::delete($this->project . '/composer.json');

        $this->publish('composer-scripts');

        $this->assertFileDoesNotExist($this->project . '/composer.json');
    }

    #[Test]
    public function a_project_without_gitignore_gets_none(): void
    {
        PlainFile::delete($this->project . '/.gitignore');

        $this->publish('gitignore');

        $this->assertFileDoesNotExist($this->project . '/.gitignore');
    }

    #[Test]
    public function composer_json_keeps_its_indentation_and_is_not_written_when_nothing_changes(): void
    {
        $this->publish('composer-scripts');

        $this->assertMatchesRegularExpression('/^  "scripts": \{/m', $this->read('composer.json'));

        $before = $this->read('composer.json');
        $this->publish('composer-scripts');

        $this->assertSame($before, $this->read('composer.json'));
    }

    #[Test]
    public function the_block_of_gitignore_is_written_once(): void
    {
        $this->publish('gitignore');
        $this->publish('gitignore');

        $this->assertSame(1, substr_count($this->read('.gitignore'), '# Dev Tools temporary files'));
        $this->assertStringStartsWith("vendor/\n", $this->read('.gitignore'));
    }

    #[Test]
    public function the_block_of_the_older_versions_is_left_as_it_is(): void
    {
        file_put_contents($this->project . '/.gitignore', "vendor/\n\n# Dev Tools - Generated temporary files\n.*.tmp.*\n");

        $this->publish('gitignore');

        $this->assertStringNotContainsString('# Dev Tools temporary files', $this->read('.gitignore'));
    }

    #[Test]
    public function the_composer_scripts_are_added_and_the_custom_ones_are_kept(): void
    {
        file_put_contents($this->project . '/composer.json', json_encode([
            'name'    => 'acme/sample',
            'scripts' => [
                'quality' => 'my own gate',
            ],
        ], JSON_PRETTY_PRINT));

        $this->publish('composer-scripts');

        $this->assertSame('my own gate', $this->scripts()['quality']);
        $this->assertArrayHasKey('test', $this->scripts());
    }

    #[Test]
    public function the_composer_scripts_replace_a_custom_one_with_force_after_a_copy_of_composer_json(): void
    {
        file_put_contents($this->project . '/composer.json', json_encode([
            'name'    => 'acme/sample',
            'scripts' => [
                'quality' => 'my own gate',
            ],
        ], JSON_PRETTY_PRINT));

        $this->publish('composer-scripts', true);

        $this->assertNotSame('my own gate', $this->scripts()['quality']);
        $this->assertStringContainsString('my own gate', (string) file_get_contents((glob($this->project . '/.dev-tools-backup/*/composer.json') ?: [''])[0]));
    }

    #[Test]
    public function the_editorconfig_is_published_and_kept_when_the_project_changed_it(): void
    {
        $this->publish('editorconfig');

        $this->assertFileEquals($this->vendor . '/.editorconfig', $this->project . '/.editorconfig');

        file_put_contents($this->project . '/.editorconfig', "root = true\n");
        $this->publish('editorconfig');

        $this->assertSame("root = true\n", $this->read('.editorconfig'));
    }

    #[Test]
    public function the_fullstack_makefile_can_be_asked_for(): void
    {
        $this->publish('makefile', false, true);

        $this->assertFileEquals($this->vendor . '/stubs/Makefile.fullstack', $this->project . '/Makefile');
    }

    #[Test]
    public function the_makefile_is_the_one_for_php_and_js_when_the_project_uses_js_dev_tools(): void
    {
        mkdir($this->project . '/node_modules/@zairakai/js-dev-tools', 0755, true);

        $this->publish('makefile');

        $this->assertFileEquals($this->vendor . '/stubs/Makefile.fullstack', $this->project . '/Makefile');
    }

    #[Test]
    public function the_makefile_is_the_one_for_php_without_js_dev_tools(): void
    {
        $this->publish('makefile');

        $this->assertFileEquals($this->vendor . '/stubs/Makefile', $this->project . '/Makefile');
    }

    #[Test]
    public function the_setup_group_publishes_the_files_that_every_project_has(): void
    {
        $this->publish('setup');

        foreach (['Makefile', '.editorconfig', 'config/dev-tools/baseline.neon', 'config/dev-tools/phpstan.neon'] as $file) {
            $this->assertFileExists($this->project . '/' . $file);
        }

        $this->assertStringContainsString('.dev-tools-backup/', $this->read('.gitignore'));
        $this->assertArrayHasKey('quality', $this->scripts());
    }

    private function publish(string $key, bool $force = false, bool $fullstack = false): void
    {
        new ConfigStubPublisher(new SilentPublishOutput())->publishTarget($key, $this->vendor, $this->project, $force, $fullstack);
    }

    private function read(string $file): string
    {
        return (string) file_get_contents($this->project . '/' . $file);
    }

    /**
     * @return array<string, mixed>
     */
    private function scripts(): array
    {
        $composer = json_decode($this->read('composer.json'), true);

        return is_array($composer) && is_array($composer['scripts'] ?? null)
            ? $composer['scripts']
            : [];
    }
}
