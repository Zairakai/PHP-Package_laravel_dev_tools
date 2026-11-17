<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Zairakai\LaravelDevTools\Services\ConfigStubPublisher;
use Zairakai\LaravelDevTools\Services\PlainFile;
use Zairakai\LaravelDevTools\Services\SetupChecker;
use Zairakai\LaravelDevTools\Services\SetupUpdater;
use Zairakai\LaravelDevTools\Services\SilentPublishOutput;

final class SetupUpdaterTest extends TestCase
{
    private string $project;

    private string $vendor;

    protected function setUp(): void
    {
        $this->vendor  = dirname(__DIR__, 2);
        $this->project = sys_get_temp_dir() . '/ldt-updater-' . bin2hex(random_bytes(6));

        mkdir($this->project . '/src', 0755, true);
        file_put_contents($this->project . '/composer.json', '{"name": "acme/sample"}');
        file_put_contents($this->project . '/.gitignore', "vendor/\n");

        $silent = new SilentPublishOutput();

        foreach (['setup', 'quality', 'style', 'testing'] as $group) {
            new ConfigStubPublisher($silent)->publishGroup($group, $this->vendor, $this->project, false);
        }
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->project));
    }

    #[Test]
    public function it_adds_the_composer_scripts_and_the_gitignore_block_that_the_project_lacks(): void
    {
        $file     = $this->project . '/composer.json';
        $composer = json_decode((string) file_get_contents($file), true);

        unset($composer['scripts']['quality']);
        file_put_contents($file, json_encode($composer, JSON_PRETTY_PRINT));
        file_put_contents($this->project . '/.gitignore', "vendor/\n");

        $done = new SetupUpdater()->update($this->vendor, $this->project);

        $this->assertSame(['.gitignore', 'composer.json'], $done['replaced']);
        $this->assertArrayHasKey('quality', json_decode((string) file_get_contents($file), true)['scripts']);
        $this->assertStringContainsString('.dev-tools-backup/', (string) file_get_contents($this->project . '/.gitignore'));
    }

    #[Test]
    public function it_creates_a_missing_file_and_replaces_a_different_one_after_a_copy(): void
    {
        PlainFile::delete($this->project . '/config/dev-tools/rector.php');
        file_put_contents($this->project . '/config/dev-tools/phpinsights.php', '<?php // mine');

        $done = new SetupUpdater()->update($this->vendor, $this->project);

        $this->assertSame(['config/dev-tools/rector.php'], $done['created']);
        $this->assertSame(['config/dev-tools/phpinsights.php'], $done['replaced']);
        $this->assertNotNull($done['backup']);
        $this->assertSame('<?php // mine', file_get_contents($done['backup'] . '/config/dev-tools/phpinsights.php'));
        $this->assertSame(
            [SetupChecker::SAME],
            array_unique(array_column(new SetupChecker()->check($this->vendor, $this->project), 'state')),
        );
    }

    #[Test]
    public function it_does_nothing_when_every_file_is_the_same(): void
    {
        $done = new SetupUpdater()->update($this->vendor, $this->project);

        $this->assertSame([], $done['created']);
        $this->assertSame([], $done['replaced']);
        $this->assertNull($done['backup']);
    }

    #[Test]
    public function it_leaves_a_symbolic_link_alone_and_reports_it(): void
    {
        $shared = $this->project . '/shared-insights.php';
        $link   = $this->project . '/config/dev-tools/phpinsights.php';

        file_put_contents($shared, '<?php // shared');
        PlainFile::delete($link);
        symlink($shared, $link);

        $done = new SetupUpdater()->update($this->vendor, $this->project);

        $this->assertSame(['config/dev-tools/phpinsights.php'], $done['kept']);
        $this->assertSame([], $done['replaced']);
        $this->assertTrue(is_link($link));
        $this->assertSame('<?php // shared', file_get_contents($shared));
        $this->assertNull($done['backup']);
    }

    #[Test]
    public function it_publishes_a_makefile_that_is_missing(): void
    {
        PlainFile::delete($this->project . '/Makefile');

        $done = new SetupUpdater()->update($this->vendor, $this->project);

        $this->assertSame(['Makefile'], $done['created']);
        $this->assertFileExists($this->project . '/Makefile');
    }

    #[Test]
    public function it_writes_nothing_in_a_dry_run(): void
    {
        file_put_contents($this->project . '/config/dev-tools/phpinsights.php', '<?php // mine');

        $done = new SetupUpdater()->update($this->vendor, $this->project, true);

        $this->assertSame(['config/dev-tools/phpinsights.php'], $done['replaced']);
        $this->assertNull($done['backup']);
        $this->assertSame('<?php // mine', file_get_contents($this->project . '/config/dev-tools/phpinsights.php'));
        $this->assertDirectoryDoesNotExist($this->project . '/.dev-tools-backup');
    }
}
