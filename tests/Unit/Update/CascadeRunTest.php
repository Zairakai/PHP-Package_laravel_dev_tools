<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Tests\Unit\Update;

use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Zairakai\LaravelDevTools\Tests\Unit\Update\Support\UpdateTestCase;
use Zairakai\LaravelDevTools\Update\CascadeRun;

final class CascadeRunTest extends UpdateTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->writeManifest([
            'name' => 'acme/package',
        ]);
        $this->env('CI_COMMIT_TAG', '1.2.0');
        $this->gitlab
            ->reply('GET /groups/zairakai/projects', [
                [
                    'id'                  => 42,
                    'path_with_namespace' => 'group/project',
                ],
                [
                    'id'                  => 43,
                    'path_with_namespace' => 'zairakai/uses-it',
                ],
                [
                    'id'                  => 44,
                    'path_with_namespace' => 'zairakai/no-opt-in',
                ],
            ])
            ->reply('GET /projects/44/repository/files/', [], 404);
    }

    #[Test]
    public function it_does_nothing_without_a_cascade_in_the_configuration(): void
    {
        $this->runCascade(null);

        $this->assertTrue($this->shell->logged('No cascade configured.'));
        $this->assertSame([], $this->gitlab->calls);
    }

    #[Test]
    public function it_leaves_the_project_that_runs_it_out(): void
    {
        $this->network->listOnPackagist('acme/package', '1.2.0');

        $this->runCascade([
            'groups' => ['zairakai'],
        ]);

        $this->assertSame([], array_filter(
            $this->gitlab->updatesStarted,
            static fn (array $started): bool => '42' === $started['project'],
        ));
    }

    #[Test]
    public function it_runs_on_the_pipeline_of_a_tag_only(): void
    {
        $this->env('CI_COMMIT_TAG', '');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The cascade runs on the pipeline of a tag.');

        $this->runCascade([
            'groups' => ['zairakai'],
        ]);
    }

    #[Test]
    public function it_says_when_an_update_could_not_be_started(): void
    {
        $this->network->listOnPackagist('acme/package', '1.2.0');
        $this->gitlab->failToStart();

        $this->runCascade([
            'groups' => ['zairakai'],
        ]);

        $this->assertTrue($this->shell->logged('zairakai/uses-it: not started (500).'));
    }

    #[Test]
    public function it_starts_the_update_of_the_projects_that_opted_in(): void
    {
        $this->network->listOnPackagist('acme/package', '1.2.0');

        $this->runCascade([
            'groups' => ['zairakai'],
        ]);

        $this->assertSame([
            [
                'project' => '43',
                'token'   => null,
            ],
        ], $this->gitlab->updatesStarted);
        $this->assertTrue($this->shell->logged('zairakai/uses-it: update started.'));
        $this->assertTrue($this->shell->logged('zairakai/no-opt-in: no .dependency-update.json, skipped.'));
    }

    #[Test]
    public function it_waits_for_packagist_and_gives_up_after_thirty_minutes(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('acme/package@1.2.0 is not on Packagist after 30 minutes.');

        try {
            $this->runCascade([
                'groups' => ['zairakai'],
            ]);
        }
        finally {
            $this->assertSame(60, $this->network->packagistChecks);
            $this->assertSame([], $this->gitlab->updatesStarted);
        }
    }

    /**
     * @param array{groups?: list<string>}|null $cascade
     */
    private function runCascade(?array $cascade): void
    {
        new CascadeRun($this->shell, $this->project(), $this->gitlab, $this->network, $cascade)->run();
    }
}
