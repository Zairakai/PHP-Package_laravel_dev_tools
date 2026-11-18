<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Services;

use RuntimeException;
use Zairakai\LaravelDevTools\Contracts\PublishOutput;

/**
 * Publishes the configuration files of the tools (PHPStan, Rector, PHP Insights, Pint, Markdownlint, PHPUnit and the
 * baseline) from the stubs of the package into the project.
 *
 * Reached through ConfigStubPublisher, which keeps the groups and the keys.
 */
final readonly class ToolConfigPublisher
{
    public function __construct(
        private PublishOutput $output,
        private StubWriter $writer,
    ) {}

    /**
     * Publish the PHPStan baseline stub.
     * Never force-overwritten - it accumulates user-accepted errors.
     */
    public function publishBaseline(string $vendorPath, string $basePath): bool
    {
        return $this->writer->publish(
            basePath: $basePath,
            stubFile: $vendorPath . '/stubs/baseline.neon',
            targetFile: $basePath . '/config/dev-tools/baseline.neon',
            // Baseline is NEVER overwritten even with --force - it contains accepted errors
            force: false,
            label: 'config/dev-tools/baseline.neon',
        );
    }

    /**
     * Publish the Enlightn configuration stub.
     * Not part of any group - installed automatically on first setup.
     */
    public function publishEnlightn(
        string $vendorPath,
        string $basePath,
        bool $force,
    ): void {
        $this->writer->publish(
            basePath: $basePath,
            stubFile: $vendorPath . '/stubs/enlightn.php',
            targetFile: $basePath . '/config/enlightn.php',
            force: $force,
            label: 'config/enlightn.php',
        );
    }

    /**
     * Publish the PHP Insights configuration of the project into config/dev-tools/. No file at the root: no extension of
     * an editor reads it, the commands do.
     * User is expected to customize this file - protected against force overwrite.
     */
    public function publishInsights(
        string $vendorPath,
        string $basePath,
        bool $force,
    ): bool {
        return $this->writer->publish(
            basePath: $basePath,
            stubFile: $vendorPath . '/stubs/phpinsights.php',
            targetFile: $basePath . '/config/dev-tools/phpinsights.php',
            force: $force,
            label: 'config/dev-tools/phpinsights.php',
        );
    }

    /**
     * Publish the Markdownlint configuration of the project into config/dev-tools/, and the thin file of the root that
     * extends it, where the extension of the editor reads it.
     */
    public function publishMarkdownlint(
        string $vendorPath,
        string $basePath,
        bool $force,
    ): bool {
        $published = $this->writer->publish(
            basePath: $basePath,
            stubFile: $vendorPath . '/stubs/.markdownlint.json',
            targetFile: $basePath . '/config/dev-tools/.markdownlint.json',
            force: $force,
            label: 'config/dev-tools/.markdownlint.json',
        );

        $this->publishRootThin(
            $vendorPath,
            $basePath,
            '.markdownlint.json',
            $force,
        );

        return $published;
    }

    /**
     * Publish the Markdownlint ignore file at the root only: an ignore file cannot extend another file, and the tool
     * reads it from the root.
     */
    public function publishMarkdownlintIgnore(
        string $vendorPath,
        string $basePath,
        bool $force,
    ): bool {
        return $this->writer->publish(
            basePath: $basePath,
            stubFile: $vendorPath . '/stubs/.markdownlintignore',
            targetFile: $basePath . '/.markdownlintignore',
            force: $force,
            label: '.markdownlintignore',
        );
    }

    /**
     * Publish the PHPStan configuration of the project into config/dev-tools/, where it extends the one of this package,
     * and the thin file of the root that includes it, where the editor reads it.
     */
    public function publishPhpstan(
        string $vendorPath,
        string $basePath,
        bool $force,
    ): bool {
        $generated = (string) tempnam(sys_get_temp_dir(), 'phpstan');

        file_put_contents($generated, $this->phpstanContents($vendorPath, $basePath));

        $published = $this->writer->publish(
            basePath: $basePath,
            stubFile: $generated,
            targetFile: $basePath . '/config/dev-tools/phpstan.neon',
            force: $force,
            label: 'config/dev-tools/phpstan.neon',
        );

        PlainFile::delete($generated);

        return $published;
    }

    /**
     * Publish the PHPUnit configuration into config/dev-tools/ only: PHPUnit cannot extend a file, and no editor
     * extension runs the tests, so a copy at the root would be a second file to keep in step.
     * Detects project type to choose between app and package config.
     *
     * PHPUnit reads the paths of its file from the folder of that file: the one of config/dev-tools/ goes up two folders
     * (ConfigCopies).
     */
    public function publishPhpunit(
        string $vendorPath,
        string $basePath,
        bool $force,
    ): bool {
        // Use app config for Laravel applications, library config for packages
        $stubFile = $this->isLaravelApp($basePath)
            ? $vendorPath . '/config/phpunit-app.xml'
            : $vendorPath . '/config/phpunit.xml';

        $converted = (string) tempnam(sys_get_temp_dir(), 'phpunit');

        file_put_contents($converted, ConfigCopies::phpunitForConfig((string) file_get_contents($stubFile)));

        $published = $this->writer->publish(
            basePath: $basePath,
            stubFile: $converted,
            targetFile: $basePath . '/config/dev-tools/phpunit.xml',
            force: $force,
            label: 'config/dev-tools/phpunit.xml',
        );

        PlainFile::delete($converted);

        return $published;
    }

    /**
     * Generate the pint.json of config/dev-tools/ only: Pint cannot extend a file, but the extension of an editor takes
     * the path of the file in its settings (`laravel-pint.configPath`), so a copy at the root would be a second file.
     *
     * Pint cannot extend a file. The file of config/dev-tools/ is the complete one that the project edits: it is
     * generated from the rules of php-dev-tools and the Laravel layer of this package, and written again over the
     * changes of the project only with $force.
     */
    public function publishPint(
        string $vendorPath,
        string $basePath,
        bool $force = false,
    ): bool {
        if (
            is_file($basePath . '/config/dev-tools/pint.json')
            && ! $force
        ) {
            $this->writer->skip('config/dev-tools/pint.json', 'already exists (use --force to generate it again)');
        }
        else {
            $this->writer->saveBeforeOverwriting($basePath, $basePath . '/config/dev-tools/pint.json');

            try {
                PintConfigWriter::write($basePath, [
                    $vendorPath . '/config/pint.json',
                ]);
            }
            catch (RuntimeException $runtimeException) {
                $this->output->error($runtimeException->getMessage());

                return false;
            }

            $this->output->task('Generated: config/dev-tools/pint.json');
        }

        return true;
    }

    /**
     * Publish the Rector configuration of the project into config/dev-tools/. No file at the root: no extension of an
     * editor reads it, the commands do.
     * User is expected to customize this file - protected against force overwrite.
     */
    public function publishRector(
        string $vendorPath,
        string $basePath,
        bool $force,
    ): bool {
        return $this->writer->publish(
            basePath: $basePath,
            stubFile: $vendorPath . '/stubs/rector.php',
            targetFile: $basePath . '/config/dev-tools/rector.php',
            force: $force,
            label: 'config/dev-tools/rector.php',
        );
    }

    /**
     * A Laravel application: an artisan file and laravel/framework in composer.json.
     */
    private function isLaravelApp(string $basePath): bool
    {
        return file_exists($basePath . '/artisan')
            && file_exists($basePath . '/composer.json')
            && str_contains((string) file_get_contents($basePath . '/composer.json'), '"laravel/framework"');
    }

    /**
     * The PHPStan file of the project: the stub (a package), or the one of an application with its own folders.
     */
    private function phpstanContents(string $vendorPath, string $basePath): string
    {
        $stub = (string) file_get_contents($vendorPath . '/stubs/phpstan.neon');

        if (! $this->isLaravelApp($basePath)) {
            return $stub;
        }

        $paths = ['../../app'];

        foreach (['database', 'routes'] as $folder) {
            if (is_dir($basePath . '/' . $folder)) {
                $paths[] = '../../' . $folder;
            }
        }

        return str_replace(
            [
                'library.neon',
                "    - ../../src\n    - ../../tests\n",
            ],
            [
                'app.neon',
                implode('', array_map(static fn (string $path): string => '    - ' . $path . "\n", $paths)),
            ],
            $stub,
        );
    }

    /**
     * The thin file of the root, for the editor: it points at the one of config/dev-tools/.
     */
    private function publishRootThin(
        string $vendorPath,
        string $basePath,
        string $name,
        bool $force,
    ): void {
        $this->writer->publish(
            basePath: $basePath,
            stubFile: $vendorPath . '/stubs/root/' . $name,
            targetFile: $basePath . '/' . $name,
            force: $force,
            label: $name . ' (root)',
        );
    }
}
