<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Tests\Unit\Update;

use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Zairakai\LaravelDevTools\Tests\Unit\Update\Support\UpdateTestCase;
use Zairakai\LaravelDevTools\Update\DependabotRun;
use Zairakai\LaravelDevTools\Update\MergeRequests;
use Zairakai\LaravelDevTools\Update\PolicyCheckRun;
use Zairakai\LaravelDevTools\Update\PolicyWatchRun;
use Zairakai\LaravelDevTools\Update\Signing;

final class PolicyRunsTest extends UpdateTestCase
{
    private const string DEPENDABOT = <<<'YAML'
        updates:
          - package-ecosystem: composer
            # BEGIN dependency-policy (composer)
            # END dependency-policy (composer)
        YAML;

    #[Test]
    public function an_unreachable_policy_sends_an_alert(): void
    {
        $this->changeSince('1.0.0', '1.0.0');

        try {
            $this->check('origin/develop');
        }
        catch (RuntimeException) {
            // The failure is the point of the test
        }

        $this->assertSame('Dependency policy unreachable', $this->network->notifications[0]['title'] ?? null);
        $this->assertStringContainsString('group/project:', $this->network->notifications[0]['message'] ?? '');
    }

    // policy-check
    // ================

    #[Test]
    public function the_check_asks_for_a_ref(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Usage: update-dependencies policy-check <ref>');

        $this->check('');
    }

    #[Test]
    public function the_check_fails_when_the_locked_version_of_a_package_of_the_policy_moves(): void
    {
        $this->servePolicy(['a/a']);
        $this->changeSince('1.0.0', '1.0.1');

        try {
            $this->check('origin/develop');
            $this->fail('The check fails');
        }
        catch (RuntimeException $runtimeException) {
            $this->assertSame('The change updates a package that the dependency policy holds back.', $runtimeException->getMessage());
            $this->assertSame(['composer.lock: a/a moves from 1.0.0 to 1.0.1.'], $this->shell->errors);
        }
    }

    #[Test]
    public function the_check_fails_when_the_range_of_a_package_of_the_policy_moves(): void
    {
        $this->servePolicy(['a/a']);
        $this->changeSince('1.0.0', '1.0.0', '^1.0', '^1.1');

        $this->expectException(RuntimeException::class);

        $this->check('origin/develop');
    }

    #[Test]
    public function the_check_passes_when_no_package_of_the_policy_moves(): void
    {
        $this->servePolicy(['a/a']);
        $this->changeSince('1.0.0', '1.0.0');

        $this->check('origin/develop');

        $this->assertTrue($this->shell->logged('No package of the dependency policy is updated.'));
    }

    #[Test]
    public function the_check_reads_a_ref_that_has_no_lock_file(): void
    {
        $this->servePolicy(['a/a']);
        $this->changeSince('1.0.0', '1.0.0');
        $this->shell->answer('git show origin/develop:composer.lock', '');

        $this->check('origin/develop');

        $this->assertTrue($this->shell->logged('No package of the dependency policy is updated.'));
    }

    #[Test]
    public function the_check_stops_when_the_policy_cannot_be_read(): void
    {
        $this->changeSince('1.0.0', '1.0.0');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The dependency policy could not be read');

        $this->check('origin/develop');
    }

    #[Test]
    public function the_sync_changes_nothing_when_the_file_already_says_what_the_policy_says(): void
    {
        $this->servePolicy(['a/a']);
        $this->dependabot(self::DEPENDABOT);
        $this->sync();
        $this->shell->logs = [];

        $this->sync();

        $this->assertTrue($this->shell->logged('The ignore rules of Dependabot are in sync with the policy.'));
    }

    // dependabot-sync
    // ================

    #[Test]
    public function the_sync_needs_the_dependabot_file(): void
    {
        $this->servePolicy([]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('.github/dependabot.yml does not exist.');

        $this->sync();
    }

    #[Test]
    public function the_sync_needs_the_markers_in_the_file(): void
    {
        $this->servePolicy([]);
        $this->dependabot("updates: []\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has no block "# BEGIN dependency-policy (composer)"');

        $this->sync();
    }

    #[Test]
    public function the_sync_removes_the_rules_when_the_policy_holds_nothing_back(): void
    {
        $this->servePolicy(['a/a']);
        $this->dependabot(self::DEPENDABOT);
        $this->sync();
        $this->servePolicy([]);

        $this->sync();

        $this->assertStringNotContainsString('dependency-name', (string) file_get_contents($this->directory . '/.github/dependabot.yml'));
        $this->assertTrue($this->shell->logged('The ignore rules of Dependabot are updated: none.'));
    }

    #[Test]
    public function the_sync_writes_one_ignore_rule_for_each_package_of_the_policy(): void
    {
        $this->servePolicy(['a/a', 'b/b']);
        $this->dependabot(self::DEPENDABOT);

        $this->sync();

        $text = (string) file_get_contents($this->directory . '/.github/dependabot.yml');

        $this->assertStringContainsString('- dependency-name: "a/a"', $text);
        $this->assertStringContainsString('- dependency-name: "b/b"', $text);
        $this->assertTrue($this->shell->logged('The ignore rules of Dependabot are updated: a/a, b/b.'));
    }

    #[Test]
    public function the_watch_lifts_an_entry_when_its_peers_accept_the_version(): void
    {
        $this->writePolicy();
        $this->network->releases('peer/p', $this->peerRelease('^2.0'));
        $this->gitlab
            ->reply('GET /projects/42/merge_requests?state=opened', [])
            ->reply('GET /user', [
                'id' => 7,
            ])
            ->reply('GET /projects/42/milestones', [])
            ->reply('POST /projects/42/merge_requests', [
                'iid' => 12,
            ], 201);

        $this->watch();

        $branch = 'chore/unblock-' . date('Ymd');

        $this->assertTrue($this->shell->ran("git switch -C {$branch}"));
        $this->assertTrue($this->shell->ran('git add php.json'));
        $this->assertTrue($this->shell->ran('git commit -m chore(policy): unblock a/a'));
        $this->assertTrue($this->shell->ran("git push https://oauth2:token@gitlab.test/group/project.git HEAD:refs/heads/{$branch}"));

        $body = $this->gitlab->callsTo('POST /projects/42/merge_requests')[0]['body'] ?? [];

        $this->assertSame('main', $body['target_branch'] ?? null);
        $this->assertSame('chore(policy): unblock a/a', $body['title'] ?? null);
        $this->assertStringContainsString('a/a 2.0.0 (peer/p)', (string) ($body['description'] ?? ''));
        $this->assertSame('Dependency policy: block lifted', $this->network->notifications[0]['title'] ?? null);

        $written = json_decode((string) file_get_contents($this->directory . '/php.json'), true);

        $this->assertSame([], $written['entries'] ?? null, 'the entry is removed from php.json');
    }

    #[Test]
    public function the_watch_lifts_nothing_while_a_peer_still_requires_the_old_range(): void
    {
        $this->writePolicy();
        $this->network->releases('peer/p', $this->peerRelease('^1.0'));

        $this->watch();

        $this->assertTrue($this->shell->logged('No entry can be lifted.'));
        $this->assertFalse($this->shell->ran('git commit'));
    }

    #[Test]
    public function the_watch_opens_one_request_at_a_time(): void
    {
        $this->writePolicy();
        $this->network->releases('peer/p', $this->peerRelease('^2.0'));
        $this->gitlab->reply('GET /projects/42/merge_requests?state=opened', [
            [
                'source_branch' => 'chore/unblock-20261115',
            ],
        ]);

        $this->watch();

        $this->assertTrue($this->shell->logged('A merge request that lifts an entry is already open.'));
        $this->assertFalse($this->shell->ran('git commit'));
    }

    #[Test]
    public function the_watch_refuses_a_policy_that_is_not_valid(): void
    {
        file_put_contents($this->directory . '/php.json', '{"entries": [{"package": "a/a"}]}');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('php.json is not valid');

        $this->watch();
    }

    // policy
    // ================

    #[Test]
    public function the_watch_runs_in_the_repository_of_the_policy(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('php.json is not in the current directory');

        $this->watch();
    }

    private function changeSince(string $before, string $after, string $rangeBefore = '^1.0', string $rangeAfter = '^1.0'): void
    {
        $this->writeManifest([
            'name'    => 'acme/package',
            'require' => [
                'a/a' => $rangeAfter,
            ],
        ]);
        $this->writeLock([
            'a/a' => $after,
        ]);
        $this->shell
            ->answer('git show origin/develop:composer.json', (string) json_encode([
                'name'    => 'acme/package',
                'require' => [
                    'a/a' => $rangeBefore,
                ],
            ]))
            ->answer('git show origin/develop:composer.lock', (string) json_encode([
                'packages'     => [
                    [
                        'name'    => 'a/a',
                        'version' => $before,
                    ],
                ],
                'packages-dev' => [],
            ]));
    }

    private function check(string $base): void
    {
        new PolicyCheckRun($this->shell, $this->project(), $this->policy())->run($base);
    }

    private function dependabot(string $text): void
    {
        mkdir($this->directory . '/.github', 0755, true);
        file_put_contents($this->directory . '/.github/dependabot.yml', $text);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function peerRelease(string $range): array
    {
        return [
            [
                'version' => '3.0.0',
                'require' => [
                    'a/a' => $range,
                ],
            ],
        ];
    }

    private function sync(): void
    {
        new DependabotRun($this->shell, $this->project(), $this->policy())->run();
    }

    private function watch(): void
    {
        new PolicyWatchRun(
            $this->shell,
            $this->project(),
            new Signing($this->shell),
            $this->network,
            new MergeRequests($this->gitlab, $this->shell),
        )->run();
    }

    private function writePolicy(): void
    {
        file_put_contents($this->directory . '/php.json', (string) json_encode([
            'entries' => [
                [
                    'package' => 'a/a',
                    'reason'  => 'peer/p does not accept it yet',
                    'since'   => '2026-01-01',
                    'unblock' => [
                        'version' => '2.0.0',
                        'peers'   => ['peer/p'],
                    ],
                ],
            ],
        ]));
    }
}
