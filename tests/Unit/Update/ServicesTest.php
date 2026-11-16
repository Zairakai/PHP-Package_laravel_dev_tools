<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Tests\Unit\Update;

use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Zairakai\LaravelDevTools\Tests\Unit\Update\Support\UpdateTestCase;
use Zairakai\LaravelDevTools\Update\Issues;
use Zairakai\LaravelDevTools\Update\MergeRequests;
use Zairakai\LaravelDevTools\Update\ProcessShell;
use Zairakai\LaravelDevTools\Update\Signing;

final class ServicesTest extends UpdateTestCase
{
    #[Test]
    public function a_merge_request_has_the_user_of_the_token_as_assignee_and_reviewer_and_the_maintenance_milestone(): void
    {
        $this->gitlab
            ->reply('GET /user', [
                'id' => 7,
            ])
            ->reply('GET /projects/42/milestones', [
                [
                    'id'    => 1,
                    'title' => 'Other',
                ],
                [
                    'id'    => 2,
                    'title' => 'Maintenance',
                ],
            ])
            ->reply('POST /projects/42/merge_requests', [
                'iid' => 5,
            ], 201);

        new MergeRequests($this->gitlab, $this->shell)->open('feature', 'develop', 'About it', true);

        $body = $this->gitlab->callsTo('POST /projects/42/merge_requests')[0]['body'] ?? [];

        $this->assertSame(7, $body['assignee_id'] ?? null);
        $this->assertSame([7], $body['reviewer_ids'] ?? null);
        $this->assertSame(2, $body['milestone_id'] ?? null);
        $this->assertSame('Kind::Chore', $body['labels'] ?? null);
    }

    // MergeRequests
    // ================

    #[Test]
    public function a_merge_request_is_set_to_merge_when_its_pipeline_succeeds(): void
    {
        $this->gitlab->reply('PUT /projects/42/merge_requests/5/merge', []);

        new MergeRequests($this->gitlab, $this->shell)->mergeWhenGreen(5);

        $body = $this->gitlab->callsTo('PUT /projects/42/merge_requests/5/merge')[0]['body'] ?? [];

        $this->assertTrue($body['merge_when_pipeline_succeeds'] ?? false);
        $this->assertTrue($body['should_remove_source_branch'] ?? false);
        $this->assertTrue($this->shell->logged('Merge request !5: it is merged when its pipeline succeeds.'));
    }

    #[Test]
    public function a_merge_request_takes_the_first_milestone_when_none_is_called_maintenance(): void
    {
        $this->gitlab
            ->reply('GET /user', [
                'id' => 7,
            ])
            ->reply('GET /projects/42/milestones', [
                [
                    'id'    => 9,
                    'title' => 'Other',
                ],
            ])
            ->reply('POST /projects/42/merge_requests', [
                'iid' => 5,
            ], 201);

        new MergeRequests($this->gitlab, $this->shell)->open('feature', 'develop', 'About it', true);

        $this->assertSame(9, $this->gitlab->callsTo('POST /projects/42/merge_requests')[0]['body']['milestone_id'] ?? null);
    }

    #[Test]
    public function a_merge_request_that_cannot_be_created_stops_the_update(): void
    {
        $this->gitlab
            ->reply('GET /user', [
                'id' => 7,
            ])
            ->reply('POST /projects/42/merge_requests', [
                'message' => 'Branch is invalid',
            ], 422);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The merge request could not be created: {"message":"Branch is invalid"}');

        new MergeRequests($this->gitlab, $this->shell)->open('feature', 'develop', 'About it', true);
    }

    #[Test]
    public function a_merge_request_that_cannot_be_set_stays_open_after_the_attempts(): void
    {
        $this->gitlab->reply('PUT /projects/42/merge_requests/5/merge', [], 405);

        new MergeRequests($this->gitlab, $this->shell)->mergeWhenGreen(5);

        $this->assertCount(18, $this->gitlab->callsTo('PUT /projects/42/merge_requests/5/merge'));
        Sleep::assertSleptTimes(18);
        $this->assertTrue($this->shell->logged('Merge request !5: it could not be set to merge by itself, it stays open.'));
    }

    #[Test]
    public function an_issue_is_assigned_to_the_user_of_the_token_without_the_variable(): void
    {
        $this->gitlab
            ->reply('GET /user', [
                'id' => 7,
            ])
            ->reply('POST /projects/42/issues', [
                'web_url' => 'https://gitlab.test/issues/2',
            ], 201);

        new Issues($this->gitlab, $this->shell)->create('Title', 'Text', 'A,B', 'tok');

        $this->assertSame([7], $this->gitlab->callsTo('POST /projects/42/issues')[0]['body']['assignee_ids'] ?? null);
        $this->assertSame('tok', $this->gitlab->callsTo('GET /user')[0]['token'] ?? null);
    }

    // Issues
    // ================

    #[Test]
    public function an_issue_is_assigned_to_the_variable_when_there_is_one(): void
    {
        $this->env('ISSUE_ASSIGNEE_ID', '15');
        $this->gitlab->reply('POST /projects/42/issues', [
            'web_url' => 'https://gitlab.test/issues/1',
        ], 201);

        $url = new Issues($this->gitlab, $this->shell)->create('Title', 'Text', 'A,B', 'tok');

        $this->assertSame('https://gitlab.test/issues/1', $url);
        $this->assertSame([15], $this->gitlab->callsTo('POST /projects/42/issues')[0]['body']['assignee_ids'] ?? null);
        $this->assertSame([], $this->gitlab->callsTo('GET /user'));
    }

    #[Test]
    public function an_issue_that_cannot_be_created_stops_the_audit(): void
    {
        $this->env('ISSUE_ASSIGNEE_ID', '15');
        $this->gitlab->reply('POST /projects/42/issues', [
            'message' => 'No',
        ], 403);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The issue could not be created');

        new Issues($this->gitlab, $this->shell)->create('Title', 'Text', 'A,B', 'tok');
    }

    #[Test]
    public function the_shell_accepts_the_statuses_that_the_caller_allows(): void
    {
        $this->assertSame('', new ProcessShell($this->directory)->exec(['sh', '-c', 'exit 3'], null, [0, 3]));
    }

    #[Test]
    public function the_shell_builds_the_address_to_push_with_the_token(): void
    {
        $this->env('GITLAB_TOKEN', 'tok-a');
        putenv('CI_SERVER_HOST=gitlab.test');

        try {
            $this->assertSame(
                'https://oauth2:tok-a@gitlab.test/group/project.git',
                new ProcessShell($this->directory)->remoteUrl(),
            );
        }
        finally {
            putenv('CI_SERVER_HOST');
        }
    }

    #[Test]
    public function the_shell_fails_on_a_status_that_is_not_allowed_and_keeps_the_tokens_out_of_the_message(): void
    {
        $this->env('GITLAB_TOKEN', 'tok-123');

        try {
            new ProcessShell($this->directory)->exec(['sh', '-c', 'echo tok-123 >&2; exit 3']);
            $this->fail('The command fails');
        }
        catch (RuntimeException $runtimeException) {
            $this->assertStringContainsString('failed:', $runtimeException->getMessage());
            $this->assertStringNotContainsString('tok-123', $runtimeException->getMessage());
            $this->assertStringContainsString('***', $runtimeException->getMessage());
        }
    }

    #[Test]
    public function the_shell_gives_the_default_for_a_variable_that_is_not_set_or_empty(): void
    {
        $shell = new ProcessShell($this->directory);
        $this->env('CI_COMMIT_TAG', '');

        $this->assertSame('fallback', $shell->env('CI_COMMIT_TAG', 'fallback'));

        $this->env('CI_COMMIT_TAG', '1.0.0');

        $this->assertSame('1.0.0', $shell->env('CI_COMMIT_TAG', 'fallback'));
    }

    #[Test]
    public function the_shell_gives_the_input_to_the_command(): void
    {
        $this->assertSame('hello', new ProcessShell($this->directory)->exec(['cat'], 'hello'));
    }

    #[Test]
    public function the_shell_leaves_a_text_alone_when_no_token_is_set(): void
    {
        $this->env('GITLAB_TOKEN', '');

        $this->assertSame('plain text', new ProcessShell($this->directory)->mask('plain text'));
    }

    #[Test]
    public function the_shell_masks_both_tokens(): void
    {
        $this->env('GITLAB_TOKEN', 'tok-a');
        $this->env('FRESHNESS_TOKEN', 'tok-b');

        $this->assertSame('x *** y ***', new ProcessShell($this->directory)->mask('x tok-a y tok-b'));
    }

    // ProcessShell
    // ================

    #[Test]
    public function the_shell_runs_a_command_in_the_project_and_trims_the_output(): void
    {
        $shell = new ProcessShell($this->directory);

        $this->assertSame(realpath($this->directory), $shell->exec(['pwd']));
    }

    #[Test]
    public function the_signing_gives_an_empty_key_when_gpg_lists_none(): void
    {
        new Signing($this->shell)->configure();

        $this->assertTrue($this->shell->ran('git config user.signingkey'));
        $this->assertContains('git config user.signingkey ', $this->shell->commands);
    }

    // Signing
    // ================

    #[Test]
    public function the_signing_imports_the_key_and_tells_git_to_sign_with_its_fingerprint(): void
    {
        $this->shell->answer('gpg --list-secret-keys', "sec:u:255:22:KEYID:::::::scESC:\nfpr:::::::::ABC123DEF456:\n");

        new Signing($this->shell)->configure();

        $this->assertSame([
            'gpg --batch --import',
            'gpg --list-secret-keys --with-colons',
            'git config user.name Release Bot',
            'git config user.email bot@example.test',
            'git config user.signingkey ABC123DEF456',
            'git config commit.gpgsign true',
            'git config tag.gpgsign true',
        ], $this->shell->commands);
    }
}
