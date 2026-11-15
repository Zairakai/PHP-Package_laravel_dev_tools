<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StanislasPoisson\DevTools\Pint;

/**
 * The files and the contents that must stay, or be corrected with the test that names them. They run against the
 * source tree, not against an installed package. This is the copy of the test of php-dev-tools, with the checks of the
 * files of this repository.
 */
final class RepositoryStructureTest extends TestCase
{
    /**
     * The scripts that every zairakai PHP repository has (the ones of php-dev-tools).
     */
    private const array SCRIPTS_OF_THE_OTHER_REPOSITORIES = [
        'cs', 'cs:fix', 'cs-fix', 'analyse', 'rector', 'rector:fix', 'rector-fix', 'insights', 'markdown', 'quality',
        'quality:fix', 'quality-fix', 'test', 'test:coverage',
    ];

    /**
     * @return iterable<string, array{string}>
     */
    public static function baseFiles(): iterable
    {
        foreach ([
            'pint.json',
            'phpstan.neon',
            'phpinsights.php',
            'rector.php',
            'phpunit.xml',
            '.markdownlint.json',
        ] as $file) {
            yield $file => [
                $file,
            ];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function hooks(): iterable
    {
        foreach ([
            'commit-msg',
            'prepare-commit-msg',
            'pre-commit',
            'pre-push',
        ] as $hook) {
            yield $hook => [
                $hook,
            ];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rootFiles(): iterable
    {
        foreach ([
            'LICENSE', 'README.md', 'SECURITY.md', 'CONTRIBUTING.md', 'CODE_OF_CONDUCT.md', 'composer.json',
            '.editorconfig', '.gitattributes', '.gitignore', '.gitlab-ci.yml', '.markdownlint.json',
            '.markdownlintignore', 'phpunit.xml',
        ] as $file) {
            yield $file => [
                $file,
            ];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function thinFiles(): iterable
    {
        foreach ([
            'pint.json', 'phpstan.neon', 'rector.php', 'phpinsights.php', 'baseline.neon', '.markdownlint.json',
            '.markdownlintignore',
        ] as $file) {
            yield $file => [
                $file,
            ];
        }
    }

    #[Test]
    public function it_asks_for_a_subject_of_50_characters_at_most_in_the_contributing_guide(): void
    {
        $contributing = (string) file_get_contents($this->root('CONTRIBUTING.md'));

        self::assertStringContainsString('50 characters at most', $contributing);
    }

    #[Test]
    public function it_chains_the_markdownlint_files_project_then_laravel_then_php_dev_tools(): void
    {
        $stub   = (string) file_get_contents($this->root('stubs/.markdownlint.json'));
        $config = (string) file_get_contents($this->root('config/.markdownlint.json'));

        self::assertStringContainsString('./vendor/zairakai/laravel-dev-tools/config/.markdownlint.json', $stub);
        self::assertStringContainsString('../../../stanislas-poisson/php-dev-tools/config/.markdownlint.json', $config);
    }

    #[Test]
    public function it_folds_the_examples_of_the_readme_in_details(): void
    {
        $readme = (string) file_get_contents($this->root('README.md'));

        self::assertStringContainsString('<details>', $readme);
        self::assertStringContainsString('<summary>', $readme);
    }

    #[Test]
    public function it_gives_the_projects_the_command_that_spreads_the_layout(): void
    {
        self::assertArrayHasKey('spread:fix', $this->scripts('composer.json'));
        self::assertArrayHasKey('spread:fix', $this->scripts('stubs/composer-scripts.json'));
        self::assertArrayNotHasKey('insights:fix', $this->scripts('composer.json'));
    }

    #[Test]
    public function it_gives_the_projects_the_scripts_of_the_other_repositories_and_the_ones_of_the_hooks(): void
    {
        $stub = $this->scripts('stubs/composer-scripts.json');

        foreach ([
            ...self::SCRIPTS_OF_THE_OTHER_REPOSITORIES,
            'quality:fast',
        ] as $name) {
            self::assertArrayHasKey($name, $stub);
        }
    }

    // Writing rules
    // ================

    #[Test]
    public function it_has_no_long_dash_in_the_files_of_the_repository(): void
    {
        // The long dashes are written with their bytes: this file does not hold them.
        $em = "\xe2\x80\x94";
        $en = "\xe2\x80\x93";

        $git   = 'git ls-files --cached --others --exclude-standard 2>/dev/null';
        $files = [];

        foreach (explode("\n", (string) shell_exec('cd ' . escapeshellarg($this->root()) . ' && ' . $git)) as $file) {
            if ('' !== $file && is_file($this->root($file)) && 'composer.lock' !== $file) {
                $files[] = $file;
            }
        }

        if ([] === $files) {
            self::markTestSkipped('The list of the files needs git.');
        }

        $found = [];

        foreach ($files as $file) {
            $content = (string) file_get_contents($this->root($file));

            if (str_contains($content, "\0")) {
                continue;
            }

            if ($this->hasLongDash($file, $content, [
                $em,
                $en,
            ])) {
                $found[] = $file;
            }
        }

        self::assertSame([], $found);
    }

    #[Test]
    public function it_has_no_template_of_merge_request_or_issue(): void
    {
        self::assertDirectoryDoesNotExist($this->root('.gitlab/merge_request_templates'));
        self::assertDirectoryDoesNotExist($this->root('.gitlab/issue_templates'));
        self::assertDirectoryDoesNotExist($this->root('.github'));
    }

    // Root
    // ================

    #[Test]
    #[DataProvider('rootFiles')]
    public function it_has_the_files_of_the_repository_at_the_root(string $file): void
    {
        self::assertFileExists($this->root($file));
    }

    #[Test]
    public function it_has_the_scripts_of_the_other_repositories(): void
    {
        $composer = $this->scripts('composer.json');

        foreach (self::SCRIPTS_OF_THE_OTHER_REPOSITORIES as $name) {
            self::assertArrayHasKey($name, $composer);
        }
    }

    #[Test]
    public function it_has_the_scripts_that_the_git_hooks_run(): void
    {
        foreach ([
            'composer.json',
            'stubs/composer-scripts.json',
        ] as $file) {
            self::assertArrayHasKey('quality:fast', $this->scripts($file));
            self::assertArrayHasKey('quality', $this->scripts($file));
        }
    }

    // config/ and stubs/
    // ================

    #[Test]
    #[DataProvider('baseFiles')]
    public function it_holds_the_base_of_each_tool_in_config_under_its_name(string $file): void
    {
        self::assertFileExists($this->root('config/' . $file));
    }

    #[Test]
    public function it_holds_the_files_of_the_tools_at_the_root_for_the_editor(): void
    {
        foreach ([
            'phpstan.neon',
            'rector.php',
            'phpinsights.php',
            'pint.json',
        ] as $file) {
            self::assertFileExists($this->root($file));
        }
    }

    // README, CONTRIBUTING and assets
    // ================

    #[Test]
    public function it_holds_the_icon_and_the_banner_in_both_formats(): void
    {
        foreach ([
            'icon.png',
            'icon.svg',
            'banner.png',
            'banner.svg',
        ] as $file) {
            self::assertFileExists($this->root('assets/' . $file));
        }
    }

    #[Test]
    public function it_holds_the_settings_of_composer_that_the_other_repositories_have(): void
    {
        $json     = (string) file_get_contents($this->root('composer.json'));
        $composer = json_decode(
            $json,
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($composer);
        self::assertIsArray($composer['config']);
        self::assertSame(0, $composer['config']['process-timeout']);
        self::assertSame([
            'php' => '8.4.0',
        ], $composer['config']['platform']);
        self::assertSame([
            'ignore-severity' => [
                'low',
                'medium',
            ],
        ], $composer['config']['audit']);
        self::assertIsArray($composer['support']);
        self::assertArrayHasKey('security', $composer['support']);
    }

    #[Test]
    #[DataProvider('thinFiles')]
    public function it_holds_the_thin_files_in_stubs_under_the_name_of_the_tool(string $file): void
    {
        self::assertFileExists($this->root('stubs/' . $file));
    }

    #[Test]
    #[DataProvider('thinFiles')]
    public function it_keeps_no_stub_suffix_on_the_thin_files(string $file): void
    {
        self::assertFileDoesNotExist($this->root('stubs/' . $file . '.stub'));
    }

    #[Test]
    public function it_keeps_the_editorconfig_and_the_scripts_of_the_pipelines_in_the_archive(): void
    {
        foreach ([
            '.editorconfig',
            '.gitlab',
            '.gitlab/scripts',
        ] as $path) {
            self::assertFalse($this->isLeftOutOfTheArchive($path), $path . ' must stay in the archive');
        }
    }

    #[Test]
    public function it_keeps_the_markdownlintignore_stub_identical_to_the_one_of_php_dev_tools(): void
    {
        self::assertSame(
            (string) file_get_contents($this->root('vendor/stanislas-poisson/php-dev-tools/config/.markdownlintignore')),
            (string) file_get_contents($this->root('stubs/.markdownlintignore')),
        );
    }

    #[Test]
    public function it_keeps_the_old_names_of_the_base_files_out_of_config(): void
    {
        foreach ([
            'base.neon',
            'insights.base.php',
            'insights.php',
            'rector.base.php',
        ] as $file) {
            self::assertFileDoesNotExist($this->root('config/' . $file));
        }
    }

    #[Test]
    public function it_keeps_the_pint_file_of_the_root_equal_to_the_merge_of_the_bases(): void
    {
        $read = fn (string $path): array => (array) json_decode(
            (string) file_get_contents($this->root($path)),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        // Pint cannot extend a file and the editor reads only this one: it is the rules of php-dev-tools and the
        // exclusions of Laravel, as scripts/pint-config.php merges them.
        $merged = Pint::merge($read('vendor/stanislas-poisson/php-dev-tools/config/pint.json'), $read('config/pint.json'));

        self::assertSame($merged, $read('pint.json'));
    }

    #[Test]
    public function it_keeps_the_pint_stub_identical_to_the_file_of_the_root(): void
    {
        self::assertSame(
            (string) file_get_contents($this->root('pint.json')),
            (string) file_get_contents($this->root('stubs/pint.json')),
        );
    }

    // Git
    // ================

    #[Test]
    public function it_leaves_the_repository_files_out_of_the_archive(): void
    {
        $attributes = (string) file_get_contents($this->root('.gitattributes'));

        foreach ([
            '/.gitattributes', '/.gitignore', '/.gitlab-ci.yml', '/.markdownlint.json', '/SECURITY.md', '/assets', '/docs',
            '/tests', '/phpstan.neon', '/phpinsights.php', '/rector.php', '/pint.json',
        ] as $entry) {
            self::assertStringContainsString($entry . ' export-ignore', $attributes);
        }
    }

    #[Test]
    public function it_normalizes_the_line_endings_and_keeps_the_markdown_diffs(): void
    {
        $attributes = (string) file_get_contents($this->root('.gitattributes'));

        foreach ([
            '* text=auto eol=lf',
            '*.md diff=markdown',
            '*.png binary',
        ] as $line) {
            self::assertStringContainsString($line, $attributes);
        }
    }

    // Settings that must stay
    // ================

    #[Test]
    public function it_sets_four_spaces_for_php_and_no_line_length_for_markdown(): void
    {
        $editorconfig = (string) file_get_contents($this->root('.editorconfig'));

        self::assertStringContainsString("[*.php]\nindent_size = 4", $editorconfig);

        preg_match(
            '/\[\*\.md\]\n(?<rules>[^\[]*)/',
            $editorconfig,
            $markdown,
        );

        self::assertArrayHasKey('rules', $markdown);
        self::assertStringNotContainsString('max_line_length', $markdown['rules']);
    }

    // The archive of the package
    // ================

    #[Test]
    public function it_ships_every_file_that_the_code_reads_in_vendor(): void
    {
        $missing = [];
        $ignored = [];

        foreach ($this->pathsReadInVendor() as $path) {
            if (! file_exists($this->root($path))) {
                $missing[] = $path;
            }

            if ($this->isLeftOutOfTheArchive($path)) {
                $ignored[] = $path;
            }
        }

        self::assertSame(
            [],
            $missing,
            'a path that the code reads does not exist',
        );
        self::assertSame(
            [],
            $ignored,
            'a path that the code reads is marked export-ignore',
        );
    }

    // Git hooks
    // ================

    #[Test]
    #[DataProvider('hooks')]
    public function it_ships_the_git_hooks_in_stubs(string $hook): void
    {
        self::assertFileExists($this->root('stubs/githooks/' . $hook));
        self::assertTrue(is_executable($this->root('stubs/githooks/' . $hook)));
    }

    #[Test]
    public function it_shows_the_icon_in_the_readme_and_not_the_banner(): void
    {
        $readme = (string) file_get_contents($this->root('README.md'));

        self::assertStringContainsString('assets/icon.png', $readme);
        self::assertStringNotContainsString('banner', $readme);
    }

    #[Test]
    public function it_spreads_the_ternaries_with_phpcbf_and_alerts_on_them_in_phpinsights(): void
    {
        $phpcs    = (string) file_get_contents($this->root('config/phpcs.xml'));
        $insights = (string) file_get_contents($this->root('config/phpinsights.php'));

        self::assertStringContainsString('RequireMultiLineTernaryOperator', $phpcs);
        self::assertStringContainsString('RequireMultiLineTernaryOperatorSniff::class', $insights);

        // The same limit in both files: the alert and the fix agree.
        self::assertStringContainsString('name="lineLengthLimit" value="0"', $phpcs);
        self::assertMatchesRegularExpression("/RequireMultiLineTernaryOperatorSniff::class => \\[\\s*'lineLengthLimit' => 0,/", $insights);
    }

    #[Test]
    public function it_starts_the_gitignore_with_the_standard_block_of_the_handbook(): void
    {
        $gitignore = (string) file_get_contents($this->root('.gitignore'));

        foreach ([
            '# JetBrains (PhpStorm, WebStorm)', '# Visual Studio Code and derivatives', '# Other editors',
            '# AI assistants', 'AGENTS.md', 'CLAUDE.md', 'GEMINI.md', '*.local.md',
        ] as $text) {
            self::assertStringContainsString($text, $gitignore);
        }
    }

    /**
     * @return list<string>
     */
    private function filesToScan(): array
    {
        $git   = 'git ls-files --cached --others --exclude-standard -- src scripts tools stubs config bin .gitlab Makefile 2>/dev/null';
        $files = array_filter(explode("\n", (string) shell_exec('cd ' . escapeshellarg($this->root()) . ' && ' . $git)));

        if ([] === $files) {
            self::markTestSkipped('The list of the files needs git.');
        }

        return array_values(array_filter($files, fn (string $file): bool => is_file($this->root($file))));
    }

    /**
     * The only long dashes that are allowed: the titles of the sections of a Makefile (`## -- title --`, with two long
     * dashes on each side), which `make help` recognizes.
     *
     * @param list<string> $dashes
     */
    private function hasLongDash(
        string $file,
        string $content,
        array $dashes,
    ): bool {
        $lines = explode("\n", $content);

        if (str_ends_with($file, '.mk')) {
            $lines = array_filter($lines, static fn (string $line): bool => ! str_starts_with($line, '## ' . $dashes[0] . $dashes[0]));
        }

        $text = implode("\n", $lines);

        return str_contains($text, $dashes[0]) || str_contains($text, $dashes[1]);
    }

    /**
     * A file is left out of the archive when it, or a folder above it, has the attribute export-ignore.
     */
    private function isLeftOutOfTheArchive(string $path): bool
    {
        $parts   = explode('/', $path);
        $current = '';

        foreach ($parts as $part) {
            $current .= ('' === $current ? '' : '/') . $part;
            $output = (string) shell_exec(
                'cd ' . escapeshellarg($this->root()) . ' && git check-attr export-ignore -- ' . escapeshellarg($current) . ' 2>/dev/null',
            );

            if (str_contains($output, 'export-ignore: set')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The paths that the code of the package reads in vendor/zairakai/laravel-dev-tools: the Composer plugin, the Artisan
     * commands, the scripts, the Makefile fragments and the pipeline templates name them.
     *
     * @return list<string>
     */
    private function pathsReadInVendor(): array
    {
        $patterns = [
            '#vendor/zairakai/laravel-dev-tools/([A-Za-z0-9_./-]+)#',
            '#\$\{?VENDOR_PATH\}?/([A-Za-z0-9_./-]+)#',
            '#\$vendorPath\s*\.\s*\'/([A-Za-z0-9_./-]+)\'#',
            '#\$\{?DEV_TOOLS_ROOT\}?/([A-Za-z0-9_./-]+)#',
        ];
        $paths = [];

        foreach ($this->filesToScan() as $file) {
            $content = (string) file_get_contents($this->root($file));

            foreach ($patterns as $pattern) {
                preg_match_all(
                    $pattern,
                    $content,
                    $matches,
                );

                foreach ($matches[1] as $path) {
                    $paths[rtrim($path, '.,:;)/')] = true;
                }
            }
        }

        return array_values(array_filter(array_keys($paths), static fn (string $path): bool => '' !== $path));
    }

    private function root(string $path = ''): string
    {
        return rtrim(__DIR__ . '/../../' . $path, '/');
    }

    /**
     * @return array<string, mixed>
     */
    private function scripts(string $file): array
    {
        $json = json_decode(
            (string) file_get_contents($this->root($file)),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($json);

        $scripts = 'composer.json' === $file
            ? ($json['scripts'] ?? null)
            : $json;

        self::assertIsArray($scripts);

        return $scripts;
    }
}
