<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Zairakai\LaravelDevTools\Services\ConfigStubPublisher;
use Zairakai\LaravelDevTools\Services\PlainFile;
use Zairakai\LaravelDevTools\Services\SetupChecker;
use Zairakai\LaravelDevTools\Services\SilentPublishOutput;

final class SetupCheckerTest extends TestCase
{
    private string $project;

    private string $vendor;

    protected function setUp(): void
    {
        $this->vendor  = dirname(__DIR__, 2);
        $this->project = sys_get_temp_dir() . '/ldt-checker-' . bin2hex(random_bytes(6));

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
    public function a_composer_script_that_the_project_lacks_is_named(): void
    {
        $file     = $this->project . '/composer.json';
        $composer = json_decode((string) file_get_contents($file), true);

        unset($composer['scripts']['quality']);
        file_put_contents($file, json_encode($composer));

        $result = $this->resultOf('composer.json');

        $this->assertSame(SetupChecker::DIFFERENT, $result['state']);
        $this->assertStringContainsString('only in the package: scripts.quality', $result['detail']);
    }

    #[Test]
    public function a_file_that_the_project_changed_is_different_and_the_check_counts_the_lines(): void
    {
        file_put_contents($this->project . '/config/dev-tools/phpinsights.php', "\n// mine\n", FILE_APPEND);

        $result = $this->resultOf('config/dev-tools/phpinsights.php');

        $this->assertSame(SetupChecker::DIFFERENT, $result['state']);
        $this->assertStringContainsString('only in the project', $result['detail']);
    }

    #[Test]
    public function a_file_that_the_project_does_not_have_is_missing(): void
    {
        PlainFile::delete($this->project . '/config/dev-tools/rector.php');

        $this->assertSame(SetupChecker::MISSING, $this->stateOf('config/dev-tools/rector.php'));
    }

    #[Test]
    public function a_project_that_published_everything_has_only_the_same_files(): void
    {
        $states = array_unique(array_column(new SetupChecker()->check($this->vendor, $this->project), 'state'));

        $this->assertSame([SetupChecker::SAME], $states);
    }

    #[Test]
    public function a_project_without_gitignore_is_not_asked_for_one(): void
    {
        PlainFile::delete($this->project . '/.gitignore');

        $files = array_column(new SetupChecker()->check($this->vendor, $this->project), 'file');

        $this->assertNotContains('.gitignore', $files);
    }

    #[Test]
    public function a_project_without_the_block_of_gitignore_is_told(): void
    {
        file_put_contents($this->project . '/.gitignore', "vendor/\n");

        $this->assertSame(SetupChecker::DIFFERENT, $this->stateOf('.gitignore'));
    }

    #[Test]
    public function pint_json_names_the_rules_of_the_project_and_the_rules_that_it_lacks(): void
    {
        $file  = $this->project . '/config/dev-tools/pint.json';
        $pint  = json_decode((string) file_get_contents($file), true);
        $first = array_key_first($pint['rules']);

        unset($pint['rules'][$first]);

        $pint['rules']['my_own_rule'] = true;

        file_put_contents($file, json_encode($pint));

        $result = $this->resultOf('config/dev-tools/pint.json');

        $this->assertSame(SetupChecker::DIFFERENT, $result['state']);
        $this->assertStringContainsString('only in the project: rules.my_own_rule', $result['detail']);
        $this->assertStringContainsString('only in the package: rules.' . $first, $result['detail']);
    }

    #[Test]
    public function the_baseline_of_the_project_is_never_different(): void
    {
        file_put_contents($this->project . '/config/dev-tools/baseline.neon', "parameters:\n  ignoreErrors: []\n# mine\n");

        $this->assertSame(SetupChecker::SAME, $this->stateOf('config/dev-tools/baseline.neon'));
    }

    #[Test]
    public function the_check_writes_nothing_in_the_project(): void
    {
        PlainFile::delete($this->project . '/config/dev-tools/rector.php');

        $before = $this->tree();

        new SetupChecker()->check($this->vendor, $this->project);

        $this->assertSame($before, $this->tree());
    }

    #[Test]
    public function the_makefile_and_the_editorconfig_are_checked(): void
    {
        PlainFile::delete($this->project . '/Makefile');
        file_put_contents($this->project . '/.editorconfig', "root = true\n");

        $this->assertSame(SetupChecker::MISSING, $this->stateOf('Makefile'));
        $this->assertSame(SetupChecker::DIFFERENT, $this->stateOf('.editorconfig'));
    }

    /**
     * @return array{file: string, state: string, detail: string}
     */
    private function resultOf(string $file): array
    {
        foreach (new SetupChecker()->check($this->vendor, $this->project) as $result) {
            if ($file === $result['file']) {
                return $result;
            }
        }

        $this->fail('The check does not know ' . $file);
    }

    private function stateOf(string $file): string
    {
        return $this->resultOf($file)['state'];
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
