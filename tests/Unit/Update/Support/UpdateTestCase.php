<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Tests\Unit\Update\Support;

use Illuminate\Support\Sleep;
use PHPUnit\Framework\TestCase;
use Zairakai\LaravelDevTools\Update\Policy;
use Zairakai\LaravelDevTools\Update\Project;

/**
 * The base of the tests of the update: a project in a temporary folder, the CI variables that a test sets, and the fakes.
 */
abstract class UpdateTestCase extends TestCase
{
    /**
     * The variables of the environment that a test may set. They are put back after each test.
     */
    private const array VARIABLES = [
        'AUTO_FIX',
        'CI_COMMIT_SHA',
        'CI_COMMIT_TAG',
        'CI_PIPELINE_URL',
        'CI_PROJECT_ID',
        'CI_PROJECT_PATH',
        'FRESHNESS_TOKEN',
        'GITLAB_TOKEN',
        'GPG_PRIVATE_KEY_B64',
        'ISSUE_ASSIGNEE_ID',
        'NTFY_URL',
        'RELEASE_SIGNER_EMAIL',
        'RELEASE_SIGNER_NAME',
    ];

    protected string $directory;

    protected FakeGitLab $gitlab;

    protected FakeNetwork $network;

    protected FakeShell $shell;

    /**
     * @var array<string, string|false>
     */
    private array $saved = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/ldt-update-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0755, true);

        $this->shell   = new FakeShell(['GITLAB_TOKEN' => 'secret']);
        $this->gitlab  = new FakeGitLab();
        $this->network = new FakeNetwork();

        foreach (self::VARIABLES as $name) {
            $this->saved[$name] = getenv($name);
            putenv($name);
        }

        $this->env('CI_PROJECT_ID', '42');
        $this->env('CI_PROJECT_PATH', 'group/project');
        $this->env('GPG_PRIVATE_KEY_B64', base64_encode('a key'));
        $this->env('RELEASE_SIGNER_NAME', 'Release Bot');
        $this->env('RELEASE_SIGNER_EMAIL', 'bot@example.test');

        Sleep::fake();
    }

    protected function tearDown(): void
    {
        Sleep::fake(false);

        foreach ($this->saved as $name => $value) {
            false === $value
                ? putenv($name)
                : putenv("{$name}={$value}");
        }

        exec('rm -rf ' . escapeshellarg($this->directory));
    }

    protected function env(string $name, string $value): void
    {
        putenv("{$name}={$value}");
    }

    protected function policy(): Policy
    {
        return new Policy($this->shell, $this->network);
    }

    protected function project(): Project
    {
        return new Project($this->shell, $this->directory);
    }

    /**
     * A policy that holds back the packages that are given, served at the address that the code reads.
     *
     * @param list<string> $held
     */
    protected function servePolicy(array $held): void
    {
        $entries = array_map(static fn (string $name): array => [
            'package' => $name,
            'reason'  => 'a reason',
            'since'   => '2026-01-01',
        ], $held);

        $this->network->page(
            'https://gitlab.com/zairakai/dependency-policy/-/raw/main/php.json',
            (string) json_encode([
                'entries' => $entries,
            ]),
        );
    }

    /**
     * @param array<string, string> $versions
     */
    protected function writeLock(array $versions): void
    {
        $packages = [];

        foreach ($versions as $name => $version) {
            $packages[] = [
                'name'    => $name,
                'version' => $version,
            ];
        }

        file_put_contents($this->directory . '/composer.lock', (string) json_encode([
            'packages'     => $packages,
            'packages-dev' => [],
        ]));
    }

    /**
     * @param array<string, mixed> $manifest
     */
    protected function writeManifest(array $manifest): void
    {
        file_put_contents($this->directory . '/composer.json', (string) json_encode($manifest));
    }
}
