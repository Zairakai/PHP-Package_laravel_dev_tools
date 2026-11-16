<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Tests\Unit\Update;

use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Zairakai\LaravelDevTools\Tests\Unit\Update\Support\UpdateTestCase;
use Zairakai\LaravelDevTools\Update\ComposerAudit;
use Zairakai\LaravelDevTools\Update\Issues;
use Zairakai\LaravelDevTools\Update\MergeRequests;
use Zairakai\LaravelDevTools\Update\ReleaseRun;

final class ReleaseRunTest extends UpdateTestCase
{
    private const string OPENED = 'GET /projects/42/merge_requests?state=opened&per_page=100&source_branch=develop&target_branch=main';

    protected function setUp(): void
    {
        parent::setUp();

        $this->shell->answer('composer audit', '{"advisories": {}}');
        $this->gitlab
            ->reply(self::OPENED, [])
            ->reply('GET /user', [
                'id' => 7,
            ])
            ->reply('GET /projects/42/milestones', [])
            ->reply('POST /projects/42/merge_requests', [
                'iid' => 11,
            ], 201);
    }

    #[Test]
    public function it_closes_the_issues_whose_advisory_is_gone(): void
    {
        $this->gitlab->reply('GET /projects/42/issues?state=opened&labels=Automated', [
            [
                'iid'   => 3,
                'title' => 'Security advisory: old/pkg (PKSA-9)',
            ],
            [
                'iid'   => 4,
                'title' => 'Something else that is automated',
            ],
        ]);

        $this->runRelease();

        $this->assertCount(1, $this->gitlab->callsTo('PUT /projects/42/issues/3'));
        $this->assertSame([], $this->gitlab->callsTo('PUT /projects/42/issues/4'));
        $this->assertTrue($this->shell->logged('Issue closed: Security advisory: old/pkg (PKSA-9)'));
    }

    #[Test]
    public function it_goes_on_when_the_audit_cannot_be_read(): void
    {
        $this->shell->answer('composer audit', new RuntimeException("composer audit failed: no network\nsecond line"));

        $this->runRelease();

        $this->assertTrue($this->shell->logged('The obsolete issues could not be checked: composer audit failed: no network'));
        $this->assertFalse($this->shell->logged('second line'));
        $this->assertCount(1, $this->gitlab->callsTo('POST /projects/42/merge_requests'));
    }

    #[Test]
    public function it_merges_the_request_that_is_already_open_instead_of_opening_another(): void
    {
        $this->gitlab->reply(self::OPENED, [
            [
                'iid' => 9,
            ],
        ]);

        $this->runRelease();

        $this->assertSame([], $this->gitlab->callsTo('POST /projects/42/merge_requests'));
        $this->assertCount(1, $this->gitlab->callsTo('PUT /projects/42/merge_requests/9/merge'));
    }

    #[Test]
    public function it_opens_the_merge_request_of_develop_into_main(): void
    {
        $this->runRelease();

        $body = $this->gitlab->callsTo('POST /projects/42/merge_requests')[0]['body'] ?? [];

        $this->assertSame('develop', $body['source_branch'] ?? null);
        $this->assertSame('main', $body['target_branch'] ?? null);
        $this->assertFalse($body['remove_source_branch'] ?? true, 'develop is kept');
        $this->assertCount(1, $this->gitlab->callsTo('PUT /projects/42/merge_requests/11/merge'));
    }

    private function runRelease(): void
    {
        new ReleaseRun(
            $this->shell,
            new ComposerAudit($this->shell),
            new Issues($this->gitlab, $this->shell),
            new MergeRequests($this->gitlab, $this->shell),
        )->run();
    }
}
