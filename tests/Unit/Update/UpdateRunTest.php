<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Tests\Unit\Update;

use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Zairakai\LaravelDevTools\Tests\Unit\Update\Support\UpdateTestCase;
use Zairakai\LaravelDevTools\Update\MergeRequests;
use Zairakai\LaravelDevTools\Update\Signing;
use Zairakai\LaravelDevTools\Update\UpdateRun;

final class UpdateRunTest extends UpdateTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->writeManifest([
            'name'    => 'acme/package',
            'require' => [
                'a/a' => '^1.1',
            ],
        ]);
        $this->writeLock([
            'a/a' => '1.0.0',
            'b/b' => '2.0.0',
        ]);
        $this->servePolicy([]);

        $this->shell->answer('git show HEAD:composer.json', (string) json_encode([
            'name'    => 'acme/package',
            'require' => [
                'a/a' => '^1.0',
            ],
        ]));
        $this->gitlab
            ->reply('GET /projects/42/merge_requests?state=opened', [])
            ->reply('GET /user', [
                'id' => 7,
            ])
            ->reply('GET /projects/42/milestones', [])
            ->reply('POST /projects/42/merge_requests', [
                'iid' => 5,
            ], 201)
            ->reply('PUT /projects/42/merge_requests/5/merge', []);
    }

    #[Test]
    public function it_commits_pushes_and_opens_a_merge_request_that_merges_when_green(): void
    {
        $this->shell->answer('git status --porcelain', ' M composer.lock');

        $this->runUpdate();

        $branch = MergeRequests::BRANCH_PREFIX . '-' . date('Ymd');

        $this->assertTrue($this->shell->ran("git switch -C {$branch}"));
        $this->assertTrue($this->shell->ran('composer update -W --no-interaction --no-progress'));
        $this->assertTrue($this->shell->ran('composer bump --no-interaction'));
        $this->assertTrue($this->shell->ran('git add composer.json composer.lock'));
        $this->assertTrue($this->shell->ran('git commit -m ' . MergeRequests::COMMIT_SUBJECT . ' -m Minor and patch updates.'));
        $this->assertTrue($this->shell->ran("git push https://oauth2:token@gitlab.test/group/project.git HEAD:refs/heads/{$branch}"));

        $created = $this->gitlab->callsTo('POST /projects/42/merge_requests')[0]['body'] ?? [];

        $this->assertSame($branch, $created['source_branch'] ?? null);
        $this->assertSame('develop', $created['target_branch'] ?? null);
        $this->assertSame(MergeRequests::COMMIT_SUBJECT, $created['title'] ?? null);
        $this->assertStringContainsString('| require | a/a | ^1.0 | ^1.1 |', (string) ($created['description'] ?? ''));
        $this->assertCount(1, $this->gitlab->callsTo('PUT /projects/42/merge_requests/5/merge'));
    }

    #[Test]
    public function it_commits_the_pipeline_file_when_only_its_ref_changed(): void
    {
        file_put_contents($this->directory . '/.gitlab-ci.yml', "ref: 1.0.0\n");
        $this->shell->answer('git diff -U0 -- .gitlab-ci.yml', "--- a/.gitlab-ci.yml\n+++ b/.gitlab-ci.yml\n-    ref: 1.0.0\n+    ref: 1.1.0");
        $this->shell->answer('git status --porcelain', ' M .gitlab-ci.yml');

        $this->runUpdate();

        $this->assertTrue($this->shell->ran('git add composer.json composer.lock .gitlab-ci.yml'));
    }

    #[Test]
    public function it_does_nothing_when_an_update_is_already_open(): void
    {
        $this->gitlab->reply('GET /projects/42/merge_requests?state=opened', [
            [
                'source_branch' => 'chore/update-dependencies-20261115',
            ],
        ]);

        $this->runUpdate();

        $this->assertTrue($this->shell->logged('An update is already open'));
        $this->assertSame([], $this->shell->commands);
    }

    #[Test]
    public function it_goes_on_without_the_normalization_when_the_plugin_is_missing(): void
    {
        $this->shell->answer('git status --porcelain', ' M composer.lock');
        $this->shell->answer('composer normalize', new RuntimeException('Command "normalize" is not defined.'));

        $this->runUpdate();

        $this->assertTrue($this->shell->logged('composer normalize is not available: skipped.'));
        $this->assertTrue($this->shell->ran('git commit'));
    }

    #[Test]
    public function it_keeps_the_locked_version_of_a_package_of_the_policy(): void
    {
        $this->servePolicy(['a/a']);
        $this->shell->answer('git status --porcelain', ' M composer.lock');

        $this->runUpdate();

        $update = $this->shell->commandsStartingWith('composer update')[0] ?? '';

        $this->assertStringContainsString('--with a/a:==1.0.0', $update);
        // The other packages are named, so that the held one is not updated with them
        $this->assertStringContainsString(' b/b', $update);
        $this->assertStringNotContainsString(' a/a ', $update . ' ');
    }

    #[Test]
    public function it_leaves_the_pipeline_file_alone_when_more_than_its_ref_changed(): void
    {
        file_put_contents($this->directory . '/.gitlab-ci.yml', "ref: 1.0.0\n");
        $this->shell->answer('git diff -U0 -- .gitlab-ci.yml', "-    ref: 1.0.0\n+    ref: 1.1.0\n+  new: line");
        $this->shell->answer('git status --porcelain', ' M composer.lock');

        $this->runUpdate();

        $this->assertTrue($this->shell->ran('git add composer.json composer.lock'));
        $this->assertFalse($this->shell->ran('git add composer.json composer.lock .gitlab-ci.yml'));
    }

    #[Test]
    public function it_refuses_an_update_that_changes_a_package_of_the_policy(): void
    {
        $this->servePolicy(['a/a']);
        $this->shell->then('composer update', fn () => $this->writeLock([
            'a/a' => '1.0.1',
            'b/b' => '2.0.0',
        ]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('a/a is held back by the dependency policy but the update changed it');

        $this->runUpdate();
    }

    #[Test]
    public function it_signs_before_it_commits(): void
    {
        $this->shell->answer('git status --porcelain', ' M composer.lock');

        $this->runUpdate();

        $commands = $this->shell->commands;
        $signing  = array_search('gpg --batch --import', $commands, true);
        $commit   = array_key_first(array_filter($commands, static fn (string $line): bool => str_starts_with($line, 'git commit')));

        $this->assertNotFalse($signing);
        $this->assertLessThan($commit, $signing);
    }

    #[Test]
    public function it_stops_when_the_files_did_not_change(): void
    {
        $this->shell->answer('git status --porcelain', '');

        $this->runUpdate();

        $this->assertTrue($this->shell->logged('Everything is up to date.'));
        $this->assertFalse($this->shell->ran('git commit'));
        $this->assertSame([], $this->gitlab->callsTo('POST /projects/42/merge_requests'));
    }

    private function runUpdate(): void
    {
        $mergeRequests = new MergeRequests($this->gitlab, $this->shell);

        new UpdateRun(
            $this->shell,
            $this->project(),
            new Signing($this->shell),
            $this->policy(),
            $mergeRequests,
        )->run();
    }
}
