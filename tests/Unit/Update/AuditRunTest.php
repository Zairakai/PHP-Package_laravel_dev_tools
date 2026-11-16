<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Tests\Unit\Update;

use PHPUnit\Framework\Attributes\Test;
use Zairakai\LaravelDevTools\Tests\Unit\Update\Support\UpdateTestCase;
use Zairakai\LaravelDevTools\Update\AuditRun;
use Zairakai\LaravelDevTools\Update\ComposerAudit;
use Zairakai\LaravelDevTools\Update\Issues;
use Zairakai\LaravelDevTools\Update\MergeRequests;

final class AuditRunTest extends UpdateTestCase
{
    private const string EXISTS = 'GET /projects/42/issues?state=opened&search=';

    private const string RESOLVED = 'GET /projects/42/issues?state=opened&labels=Automated';

    protected function setUp(): void
    {
        parent::setUp();

        $this->env('AUTO_FIX', 'true');
        $this->env('CI_PIPELINE_URL', 'https://gitlab.test/pipelines/1');
        $this->writeManifest([
            'name' => 'acme/package',
        ]);
        $this->writeLock([
            'vendor/pkg' => '1.0.0',
        ]);
        $this->advisory('high');
        $this->gitlab
            ->reply(self::EXISTS, [])
            ->reply(self::RESOLVED, [])
            ->reply('GET /user', [
                'id' => 7,
            ])
            ->reply('POST /projects/42/issues', [
                'web_url' => 'https://gitlab.test/issues/1',
            ], 201)
            ->reply('GET /projects/42/merge_requests?state=opened', []);
    }

    #[Test]
    public function it_closes_the_issues_of_the_advisories_that_are_gone(): void
    {
        $this->shell->answer('composer audit', '{"advisories": {}}');
        $this->gitlab->reply(self::RESOLVED, [
            [
                'iid'   => 8,
                'title' => 'Security advisory: gone/pkg (PKSA-0)',
            ],
        ]);

        $this->runAudit();

        $this->assertCount(1, $this->gitlab->callsTo('PUT /projects/42/issues/8'));
        $this->assertCount(1, $this->gitlab->callsTo('POST /projects/42/issues/8/notes'));
        $this->assertSame([], array_filter(
            $this->gitlab->calls,
            static fn (array $call): bool => 'POST' === $call['method'] && '/projects/42/issues' === $call['path'],
        ), 'no new issue');
        $this->assertTrue($this->shell->logged('0 security advisory(ies) found.'));
    }

    #[Test]
    public function it_does_not_open_a_second_issue_for_an_advisory(): void
    {
        $this->gitlab->reply(self::EXISTS, [
            [
                'title' => 'Security advisory: vendor/pkg (PKSA-1)',
            ],
        ]);

        $this->runAudit();

        $this->assertSame([], $this->gitlab->callsTo('POST /projects/42/issues'));
        $this->assertTrue($this->shell->logged('Issue already open: Security advisory: vendor/pkg (PKSA-1)'));
        $this->assertSame([], $this->network->notifications);
    }

    #[Test]
    public function it_follows_an_advisory_that_a_package_of_the_group_brings_in_that_package(): void
    {
        $this->writeManifest([
            'name'    => 'acme/package',
            'require' => [
                'zairakai/lib' => '^1.0',
            ],
        ]);
        file_put_contents($this->directory . '/composer.lock', (string) json_encode([
            'packages'     => [
                [
                    'name'    => 'zairakai/lib',
                    'require' => [
                        'vendor/pkg' => '*',
                    ],
                ],
                [
                    'name' => 'vendor/pkg',
                ],
            ],
            'packages-dev' => [],
        ]));

        $this->runAudit();

        $this->assertSame([], $this->gitlab->callsTo('POST /projects/42/issues'));
        $this->assertTrue($this->shell->logged('Inherited from the group'));
    }

    #[Test]
    public function it_leaves_a_low_severity_advisory_to_the_weekly_update(): void
    {
        $this->advisory('low');

        $this->runAudit();

        $this->assertSame([], $this->gitlab->updatesStarted);
        $this->assertTrue($this->shell->logged('Nothing urgent: the weekly update takes the rest.'));
        $this->assertSame([], $this->network->notifications, 'a low severity gets its issue, not a notification');
    }

    #[Test]
    public function it_opens_an_issue_and_starts_the_update_for_an_urgent_advisory(): void
    {
        $this->runAudit();

        $issue = $this->gitlab->callsTo('POST /projects/42/issues')[0] ?? [];

        $this->assertSame('Security advisory: vendor/pkg (PKSA-1)', $issue['body']['title'] ?? null);
        $this->assertStringContainsString('Priority::High', (string) ($issue['body']['labels'] ?? ''));
        $this->assertStringContainsString('Automatic fix: started', (string) ($issue['body']['description'] ?? ''));
        $this->assertSame([[
            'project' => '42',
            'token'   => 'secret',
        ]], $this->gitlab->updatesStarted);
        $this->assertTrue($this->shell->logged('Update started.'));
    }

    #[Test]
    public function it_says_when_the_update_could_not_be_started(): void
    {
        $this->gitlab->failToStart();

        $this->runAudit();

        $this->assertTrue($this->shell->logged('The update could not be started (500).'));
    }

    #[Test]
    public function it_sends_one_notification_for_the_audit(): void
    {
        $this->runAudit();

        $this->assertCount(1, $this->network->notifications);
        $this->assertSame('Security advisories: 1 new in group/project', $this->network->notifications[0]['title']);
        $this->assertStringContainsString('https://gitlab.test/issues/1', $this->network->notifications[0]['message']);
    }

    #[Test]
    public function it_starts_no_second_update_when_one_is_open(): void
    {
        $this->gitlab->reply('GET /projects/42/merge_requests?state=opened', [
            [
                'source_branch' => 'chore/update-dependencies-20261115',
            ],
        ]);

        $this->runAudit();

        $this->assertSame([], $this->gitlab->updatesStarted);
        $this->assertTrue($this->shell->logged('An update is already open: nothing to start.'));
    }

    #[Test]
    public function it_starts_no_update_when_the_fix_is_off(): void
    {
        $this->env('AUTO_FIX', 'false');

        $this->runAudit();

        $this->assertCount(1, $this->gitlab->callsTo('POST /projects/42/issues'));
        $this->assertSame([], $this->gitlab->updatesStarted);
        $this->assertTrue($this->shell->logged('AUTO_FIX is off: update by hand.'));
    }

    #[Test]
    public function it_uses_the_token_of_the_freshness_job_when_it_is_set(): void
    {
        $shell = new Support\FakeShell(['GITLAB_TOKEN' => 'secret', 'FRESHNESS_TOKEN' => 'fresh']);
        $shell->answer('composer audit', $this->auditJson('high'));

        $this->runAudit($shell);

        $this->assertSame('fresh', $this->gitlab->callsTo('POST /projects/42/issues')[0]['token'] ?? null);
    }

    private function advisory(string $severity): void
    {
        $this->shell->answer('composer audit', $this->auditJson($severity));
    }

    private function auditJson(string $severity): string
    {
        return (string) json_encode([
            'advisories' => [
                'vendor/pkg' => [
                    [
                        'advisoryId'       => 'PKSA-1',
                        'packageName'      => 'vendor/pkg',
                        'title'            => 'A flaw',
                        'link'             => 'https://example.test/PKSA-1',
                        'cve'              => 'CVE-2026-1',
                        'severity'         => $severity,
                        'affectedVersions' => '<1.1.0',
                    ],
                ],
            ],
        ]);
    }

    private function runAudit(?Support\FakeShell $shell = null): void
    {
        $shell ??= $this->shell;

        new AuditRun(
            $shell,
            $this->project(),
            new ComposerAudit($shell),
            new Issues($this->gitlab, $shell),
            new MergeRequests($this->gitlab, $shell),
            $this->gitlab,
            $this->network,
        )->run();
    }
}
