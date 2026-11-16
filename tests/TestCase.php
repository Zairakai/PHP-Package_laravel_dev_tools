<?php

namespace Zairakai\LaravelDevTools\Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;
use Zairakai\LaravelDevTools\LaravelDevToolsServiceProvider;
use Zairakai\LaravelDevTools\Services\PlainFile;

abstract class TestCase extends Orchestra
{
    /**
     * The files of the test application that the default setup writes or edits, and what they held.
     */
    private const array FILES = [
        '.editorconfig',
        '.gitignore',
        'Makefile',
        'composer.json',
        'phpstan.neon',
    ];

    /**
     * The folders that the default setup creates.
     */
    private const array FOLDERS = [
        '.dev-tools-backup',
        'config/dev-tools',
    ];

    /**
     * @var array<string, string|null>
     */
    private array $files = [];

    /**
     * @var list<string> the folders that did not exist before the test
     */
    private array $newFolders = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::FILES as $file) {
            $path = $this->app->basePath($file);

            $this->files[$path] = is_file($path)
                ? (string) file_get_contents($path)
                : null;
        }

        $this->newFolders = array_values(array_filter(
            array_map(fn (string $folder): string => $this->app->basePath($folder), self::FOLDERS),
            static fn (string $path): bool => ! is_dir($path),
        ));
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $path => $content) {
            null === $content
                ? PlainFile::delete($path)
                : file_put_contents($path, $content);
        }

        foreach ($this->newFolders as $folder) {
            PlainFile::deleteDirectory($folder);
        }

        parent::tearDown();
    }

    /**
     * Define environment setup.
     *
     * @param Application $app
     */
    protected function getEnvironmentSetUp($app): void
    {
        // Setup default database to use sqlite :memory:
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
    }

    /**
     * Get package providers.
     *
     * @param Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            LaravelDevToolsServiceProvider::class,
        ];
    }
}
