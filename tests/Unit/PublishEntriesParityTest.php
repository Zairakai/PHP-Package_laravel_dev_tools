<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Tests\Unit;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Zairakai\LaravelDevTools\Tests\TestCase;

/**
 * The two entries of the publication give the same files: `php artisan dev-tools:publish` and `scripts/publish.php`,
 * which `scripts/setup-package.sh` calls.
 */
final class PublishEntriesParityTest extends TestCase
{
    /**
     * What a publication can write at the root of the project, besides config/dev-tools/.
     */
    private const array ROOT_FILES = [
        '.gitlab-ci.yml',
        '.markdownlint.json',
        '.markdownlintignore',
        'CODE_OF_CONDUCT.md',
        'CONTRIBUTING.md',
        'phpstan.neon',
        'phpunit.xml',
        'pint.json',
        'SECURITY.md',
    ];

    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = $this->app->basePath();
        $this->clean();
    }

    protected function tearDown(): void
    {
        $this->clean();

        parent::tearDown();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function targets(): array
    {
        return [
            'quality'    => ['quality'],
            'style'      => ['style'],
            'testing'    => ['testing'],
            'governance' => ['governance'],
            'gitlab-ci'  => ['gitlab-ci'],
        ];
    }

    #[Test]
    #[DataProvider('targets')]
    public function artisan_and_the_setup_script_publish_the_same_files(string $target): void
    {
        Artisan::call('dev-tools:publish', [
            '--publish'        => $target,
            '--no-interaction' => true,
        ]);

        $fromArtisan = $this->published();

        $this->clean();

        $root = dirname(__DIR__, 2);

        exec(
            sprintf(
                'php %s %s %s %s 2>&1',
                escapeshellarg($root . '/scripts/publish.php'),
                escapeshellarg($this->basePath),
                escapeshellarg($root),
                escapeshellarg($target),
            ),
            $lines,
            $exitCode,
        );

        $this->assertSame(0, $exitCode, implode("\n", $lines));
        $this->assertNotEmpty($fromArtisan, 'Artisan published nothing for ' . $target);
        $this->assertSame($fromArtisan, $this->published());
    }

    #[Test]
    public function both_entries_refuse_an_unknown_target(): void
    {
        $root = dirname(__DIR__, 2);

        exec(
            sprintf(
                'php %s %s %s unknown 2>&1',
                escapeshellarg($root . '/scripts/publish.php'),
                escapeshellarg($this->basePath),
                escapeshellarg($root),
            ),
            $lines,
            $exitCode,
        );

        $this->assertSame(1, $exitCode);
        $this->assertSame(1, Artisan::call('dev-tools:publish', [
            '--publish'        => 'unknown',
            '--no-interaction' => true,
        ]));
    }

    private function clean(): void
    {
        foreach (self::ROOT_FILES as $name) {
            File::delete($this->basePath . '/' . $name);
        }

        File::deleteDirectory($this->basePath . '/config/dev-tools');
        File::deleteDirectory($this->basePath . '/.dev-tools-backup');
        File::makeDirectory($this->basePath . '/config/dev-tools', 0755, true, true);
    }

    /**
     * The files of config/dev-tools/ and of the root, with the hash of their content.
     *
     * @return array<string, string>
     */
    private function published(): array
    {
        $files = [];

        foreach (File::allFiles($this->basePath . '/config/dev-tools', true) as $file) {
            $files['config/dev-tools/' . $file->getRelativePathname()] = hash_file('sha256', $file->getPathname());
        }

        foreach (self::ROOT_FILES as $name) {
            if (is_file($this->basePath . '/' . $name)) {
                $files[$name] = hash_file('sha256', $this->basePath . '/' . $name);
            }
        }

        ksort($files);

        return $files;
    }
}
