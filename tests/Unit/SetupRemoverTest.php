<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Zairakai\LaravelDevTools\Services\ConfigStubPublisher;
use Zairakai\LaravelDevTools\Services\PlainFile;
use Zairakai\LaravelDevTools\Services\SetupChecker;
use Zairakai\LaravelDevTools\Services\SetupRemover;
use Zairakai\LaravelDevTools\Services\SilentPublishOutput;

final class SetupRemoverTest extends TestCase
{
    private string $project;

    private string $vendor;

    protected function setUp(): void
    {
        $this->vendor  = dirname(__DIR__, 2);
        $this->project = sys_get_temp_dir() . '/ldt-remover-' . bin2hex(random_bytes(6));

        mkdir($this->project . '/src', 0755, true);
        file_put_contents($this->project . '/composer.json', "{\n  \"name\": \"acme/sample\"\n}\n");
        file_put_contents($this->project . '/.gitignore', "vendor/\n");

        foreach (['setup', 'quality', 'style', 'testing'] as $group) {
            new ConfigStubPublisher(new SilentPublishOutput())->publishGroup($group, $this->vendor, $this->project, false);
        }
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->project));
    }

    #[Test]
    public function a_dry_run_lists_the_files_and_writes_nothing(): void
    {
        $before = $this->tree();

        $done = $this->remove(dryRun: true);

        $this->assertContains('Makefile', $done['removed']);
        $this->assertNull($done['backup']);
        $this->assertSame($before, $this->tree());
    }

    #[Test]
    public function it_copies_everything_that_it_removes_and_the_copies_put_everything_back(): void
    {
        file_put_contents($this->project . '/config/dev-tools/rector.php', "<?php // mine\n");

        $done = $this->remove(customized: true);

        $this->assertNotNull($done['backup']);

        foreach (['Makefile', '.editorconfig', 'config/dev-tools/pint.json', 'config/dev-tools/phpstan.neon', 'composer.json', '.gitignore', 'config/dev-tools/rector.php'] as $file) {
            $this->assertFileExists($done['backup'] . '/' . $file, $file);
        }

        $this->assertSame("<?php // mine\n", file_get_contents($done['backup'] . '/config/dev-tools/rector.php'));

        exec('cp -R ' . escapeshellarg($done['backup']) . '/. ' . escapeshellarg($this->project));

        // Back as it was: the only file that differs from the package is the one that the project changed
        $differing = array_column(array_filter(
            new SetupChecker()->check($this->vendor, $this->project),
            static fn (array $result): bool => SetupChecker::SAME !== $result['state'],
        ), 'file');

        $this->assertSame(['config/dev-tools/rector.php'], array_values($differing));
    }

    #[Test]
    public function it_has_nothing_to_do_in_a_project_that_was_never_set_up(): void
    {
        exec('rm -rf ' . escapeshellarg($this->project));
        mkdir($this->project, 0755, true);
        file_put_contents($this->project . '/composer.json', '{"name": "acme/sample"}');

        $done = $this->remove();

        $this->assertSame([], $done['removed']);
        $this->assertSame([], $done['kept']);
        $this->assertNull($done['backup']);
    }

    #[Test]
    public function it_keeps_a_baseline_that_holds_accepted_errors(): void
    {
        file_put_contents($this->project . '/config/dev-tools/baseline.neon', "parameters:\n  ignoreErrors:\n    - '#some error#'\n");

        $done = $this->remove();

        $this->assertFileExists($this->project . '/config/dev-tools/baseline.neon');
        $this->assertContains(['file' => 'config/dev-tools/baseline.neon', 'reason' => 'customized'], $done['kept']);
    }

    #[Test]
    public function it_keeps_a_file_that_the_project_changed_and_says_why(): void
    {
        file_put_contents($this->project . '/Makefile', "mine:\n");
        file_put_contents($this->project . '/.editorconfig', "root = true\n");

        $done = $this->remove();

        $this->assertSame("mine:\n", file_get_contents($this->project . '/Makefile'));
        $this->assertSame("root = true\n", file_get_contents($this->project . '/.editorconfig'));
        $this->assertContains(['file' => 'Makefile', 'reason' => 'customized'], $done['kept']);
        $this->assertContains(['file' => '.editorconfig', 'reason' => 'customized'], $done['kept']);
    }

    #[Test]
    public function it_keeps_a_script_that_the_project_changed(): void
    {
        $file     = $this->project . '/composer.json';
        $composer = json_decode((string) file_get_contents($file), true);

        $composer['scripts']['quality'] = 'my own gate';
        file_put_contents($file, json_encode($composer, JSON_PRETTY_PRINT));

        $this->remove();

        $after = json_decode((string) file_get_contents($file), true);

        $this->assertSame(['quality' => 'my own gate'], $after['scripts']);
    }

    #[Test]
    public function it_keeps_a_symbolic_link_and_what_it_points_at(): void
    {
        $shared = $this->project . '/shared-editorconfig';
        $link   = $this->project . '/.editorconfig';

        file_put_contents($shared, "root = true\n");
        PlainFile::delete($link);
        symlink($shared, $link);

        $done = $this->remove();

        $this->assertTrue(is_link($link));
        $this->assertSame("root = true\n", file_get_contents($shared));
        $this->assertContains(['file' => '.editorconfig', 'reason' => 'symbolic link'], $done['kept']);
    }

    #[Test]
    public function it_keeps_the_config_files_that_the_project_changed_unless_it_is_asked_to_remove_them(): void
    {
        file_put_contents($this->project . '/config/dev-tools/rector.php', "<?php // mine\n");

        $done = $this->remove();

        $this->assertFileExists($this->project . '/config/dev-tools/rector.php');
        $this->assertContains(['file' => 'config/dev-tools/rector.php', 'reason' => 'customized'], $done['kept']);

        $done = $this->remove(customized: true);

        $this->assertFileDoesNotExist($this->project . '/config/dev-tools/rector.php');
        $this->assertDirectoryDoesNotExist($this->project . '/config/dev-tools');
        $this->assertContains('config/dev-tools/rector.php', $done['removed']);
    }

    #[Test]
    public function it_removes_a_thin_file_that_points_at_config_dev_tools_and_keeps_another_one(): void
    {
        file_put_contents($this->project . '/.markdownlint.json', '{"extends": "./my/own.json"}');

        $done = $this->remove();

        $this->assertFileExists($this->project . '/.markdownlint.json');
        $this->assertContains(['file' => '.markdownlint.json', 'reason' => 'customized'], $done['kept']);
    }

    #[Test]
    public function it_removes_every_file_that_is_still_what_the_package_publishes(): void
    {
        $done = $this->remove();

        foreach (['Makefile', '.editorconfig', 'config/dev-tools/pint.json', 'config/dev-tools/phpunit.xml', '.markdownlintignore', 'config/dev-tools/phpstan.neon', '.markdownlint.json'] as $file) {
            $this->assertFileDoesNotExist($this->project . '/' . $file, $file);
            $this->assertContains($file, $done['removed']);
        }

        $this->assertDirectoryDoesNotExist($this->project . '/config/dev-tools');
        $this->assertSame([], $done['kept']);
    }

    #[Test]
    public function it_removes_the_scripts_of_the_package_and_the_block_of_gitignore(): void
    {
        $this->remove();

        $composer = json_decode((string) file_get_contents($this->project . '/composer.json'), true);

        $this->assertArrayNotHasKey('scripts', $composer);
        $this->assertSame("vendor/\n", file_get_contents($this->project . '/.gitignore'));
        $this->assertStringStartsWith("{\n  \"name\"", (string) file_get_contents($this->project . '/composer.json'));
    }

    #[Test]
    public function the_folder_of_the_copies_is_ignored_by_git_on_its_own(): void
    {
        $done = $this->remove();

        $this->assertSame("*\n", file_get_contents($this->project . '/.dev-tools-backup/.gitignore'));
        $this->assertNotNull($done['backup']);
    }

    /**
     * @return array{removed: list<string>, kept: list<array{file: string, reason: string}>, backup: ?string}
     */
    private function remove(bool $dryRun = false, bool $customized = false): array
    {
        return new SetupRemover()->remove($this->vendor, $this->project, $dryRun, $customized);
    }

    /**
     * @return list<string>
     */
    private function tree(): array
    {
        exec('cd ' . escapeshellarg($this->project) . ' && find . -type f -exec sha256sum {} + | sort', $lines);

        return $lines;
    }
}
