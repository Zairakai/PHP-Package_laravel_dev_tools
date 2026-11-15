<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Tests\Unit;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Zairakai\LaravelDevTools\Services\ConfigBackup;
use Zairakai\LaravelDevTools\Tests\TestCase;

/**
 * The copy of a file that a forced publication writes over.
 */
final class ConfigBackupTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/config-backup-' . uniqid('', true);

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

    #[Test]
    public function it_keeps_a_copy_under_the_path_of_the_file_in_a_folder_of_the_run(): void
    {
        file_put_contents($this->root . '/config/dev-tools/pint.json', '{"mine": true}');

        $backup = new ConfigBackup($this->root, '20261115-120000');

        self::assertTrue($backup->save($this->root . '/config/dev-tools/pint.json'));
        self::assertSame(
            '{"mine": true}',
            file_get_contents($this->root . '/.dev-tools-backup/20261115-120000/config/dev-tools/pint.json'),
        );
        self::assertSame($this->root . '/.dev-tools-backup/20261115-120000', $backup->directoryUsed());
    }

    #[Test]
    public function it_keeps_nothing_for_a_file_that_does_not_exist_or_a_link(): void
    {
        file_put_contents($this->root . '/real.txt', 'x');
        symlink($this->root . '/real.txt', $this->root . '/link.txt');

        $backup = new ConfigBackup($this->root, '20261115-120000');

        self::assertFalse($backup->save($this->root . '/missing.txt'));
        self::assertFalse($backup->save($this->root . '/link.txt'));
        self::assertNull($backup->directoryUsed(), 'the folder is created by the first copy');
        self::assertDirectoryDoesNotExist($this->root . '/.dev-tools-backup');
    }

    #[Test]
    public function it_names_the_folder_of_the_run_after_the_date_and_time_in_utc(): void
    {
        file_put_contents($this->root . '/phpstan.neon', 'x');

        $backup = new ConfigBackup($this->root);
        $backup->save($this->root . '/phpstan.neon');

        self::assertMatchesRegularExpression(
            '#/\.dev-tools-backup/\d{8}-\d{6}$#',
            (string) $backup->directoryUsed(),
        );
    }
}
