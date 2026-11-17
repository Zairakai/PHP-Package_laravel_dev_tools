<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Tests\Unit\Update;

use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Zairakai\LaravelDevTools\Tests\Unit\Update\Support\UpdateTestCase;
use Zairakai\LaravelDevTools\Update\Updater;

/**
 * The entry of the tool: its configuration, and what it refuses before it reaches git or GitLab.
 */
final class UpdaterTest extends UpdateTestCase
{
    #[Test]
    public function it_asks_for_a_ref_before_the_policy_check_reads_anything(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Usage: update-dependencies policy-check <ref>');

        new Updater($this->directory)->run('policy-check');
    }

    #[Test]
    public function it_names_the_variable_that_is_missing(): void
    {
        $this->env('GITLAB_TOKEN', 'tok');
        $this->env('CI_PROJECT_ID', '');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CI_PROJECT_ID is not set');

        new Updater($this->directory)->run('audit');
    }

    #[Test]
    public function it_needs_the_dependabot_file_for_the_sync(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('.github/dependabot.yml does not exist.');

        new Updater($this->directory)->run('dependabot-sync');
    }

    #[Test]
    public function it_needs_the_variables_of_gitlab_ci_for_the_other_modes(): void
    {
        $this->env('GITLAB_TOKEN', '');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('GITLAB_TOKEN is not set: run this in GitLab CI');

        new Updater($this->directory)->run('update');
    }

    #[Test]
    public function it_reads_the_report_of_the_audit_check_without_a_token(): void
    {
        $this->env('GITLAB_TOKEN', '');
        file_put_contents($this->directory . '/report.json', '{"error": "could not reach packagist"}');

        // The mode is reached and reads the report: the missing token is not what stops it
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('composer audit could not run');

        new Updater($this->directory)->run('audit-check', $this->directory . '/report.json');
    }

    #[Test]
    public function it_refuses_a_setting_that_composer_cannot_honor(): void
    {
        file_put_contents($this->directory . '/.dependency-update.json', '{"reject": ["a/a"]}');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('"reject" is not supported for a Composer project');

        new Updater($this->directory);
    }

    #[Test]
    public function it_starts_without_a_configuration_file(): void
    {
        $this->assertInstanceOf(Updater::class, new Updater($this->directory));
    }
}
