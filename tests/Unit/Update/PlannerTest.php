<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Tests\Unit\Update;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Zairakai\LaravelDevTools\Update\Planner;

final class PlannerTest extends TestCase
{
    #[Test]
    public function accepts_a_valid_policy_and_reports_what_is_wrong(): void
    {
        $entry = ['package' => 'a/b', 'reason' => 'Not accepted by c/d.', 'since' => '2026-10-11', 'unblock' => ['version' => '2.0.0', 'peers' => ['c/d']]];

        $this->assertSame([], Planner::validatePolicy(['entries' => [$entry]]));
        $this->assertSame([], Planner::validatePolicy(['entries' => []]));
        $this->assertSame(['"entries" must be a list.'], Planner::validatePolicy([]));
        $this->assertSame([
            'entries[0]: "package" is missing.',
            'entries[0]: "reason" is missing.',
            'entries[0]: "since" must be a date (YYYY-MM-DD).',
        ], Planner::validatePolicy(['entries' => [[]]]));
        $this->assertSame(['entries[1]: "a/b" is listed twice.'], Planner::validatePolicy(['entries' => [$entry, $entry]]));
        $this->assertSame([
            'entries[0]: "unblock.version" must be a version (X.Y.Z).',
            'entries[0]: "unblock.peers" must be a list of packages.',
        ], Planner::validatePolicy(['entries' => [[...$entry, 'unblock' => ['version' => 'two', 'peers' => []]]]]));
        $this->assertSame(['a/b'], Planner::policyPackages(['entries' => [$entry]]));
    }

    #[Test]
    public function accepts_a_version_through_the_constraint_of_composer(): void
    {
        $this->assertTrue(Planner::acceptsVersion('^7.8 || ^8.0', '8.0.0'));
        $this->assertTrue(Planner::acceptsVersion('^7.8 | ^8.0', '8.0.0'));
        $this->assertTrue(Planner::acceptsVersion('>=8.1, <9.0', '8.4.0'));
        $this->assertTrue(Planner::acceptsVersion('~1.2', '1.9.0'));
        $this->assertTrue(Planner::acceptsVersion('1.2.*', '1.2.7'));
        $this->assertTrue(Planner::acceptsVersion('^1.0@stable', '1.4.0'));
        $this->assertFalse(Planner::acceptsVersion('^7.8', '8.0.0'));
        $this->assertFalse(Planner::acceptsVersion('~1.2.3', '1.3.0'));
        $this->assertFalse(Planner::acceptsVersion('dev-main', '1.0.0'));
        $this->assertFalse(Planner::acceptsVersion('not a constraint', '1.0.0'));
    }

    #[Test]
    public function accepts_only_the_move_of_the_ref_of_the_shared_pipeline(): void
    {
        $header = "--- a/.gitlab-ci.yml\n+++ b/.gitlab-ci.yml\n@@ -6 +6 @@\n";

        $this->assertTrue(Planner::isRefOnlyChange($header . "-    ref: 4.3.0\n+    ref: 4.3.1"));
        $this->assertFalse(Planner::isRefOnlyChange($header . "-    file: \"a.yml\"\n+    file: \"b.yml\""));
        $this->assertFalse(Planner::isRefOnlyChange($header . "-    ref: 4.3.0\n+    ref: 4.3.1\n+  extra: true"));
        $this->assertFalse(Planner::isRefOnlyChange(''));
    }

    #[Test]
    public function completes_the_releases_that_list_only_what_changed(): void
    {
        $full = Planner::expandReleases([
            ['name' => 'a/b', 'version' => '2.1.0', 'description' => 'kept'],
            ['version' => '2.0.0', 'description' => '__unset'],
        ]);

        $this->assertSame(['name' => 'a/b', 'version' => '2.1.0', 'description' => 'kept'], $full[0]);
        $this->assertSame(['name' => 'a/b', 'version' => '2.0.0'], $full[1]);
    }

    #[Test]
    public function computes_the_next_version(): void
    {
        $this->assertSame('1.2.4', Planner::nextVersion('1.2.3', 'patch'));
        $this->assertSame('1.3.0', Planner::nextVersion('1.2.3', 'minor'));
        $this->assertSame('2.0.0', Planner::nextVersion('1.2.3', 'major'));

        $this->expectException(InvalidArgumentException::class);
        Planner::nextVersion('1.2.3', 'huge');
    }

    #[Test]
    public function decodes_the_signing_key(): void
    {
        $signer = Planner::signerIdentity(['GPG_PRIVATE_KEY_B64' => base64_encode('KEY'), 'RELEASE_SIGNER_NAME' => 'N', 'RELEASE_SIGNER_EMAIL' => 'e@x.fr']);

        $this->assertSame(['name' => 'N', 'email' => 'e@x.fr', 'key' => 'KEY'], $signer);
    }

    #[Test]
    public function describes_the_advisory_and_the_automatic_fix_in_the_issue(): void
    {
        $advisory = ['id' => 'PKSA-1', 'package' => 'vendor/a', 'title' => 'Remote code execution', 'link' => 'https://example.test/1', 'cve' => 'CVE-2026-1', 'severity' => 'high', 'range' => '<1.5'];

        $on  = Planner::advisoryIssueBody($advisory, 'https://ci.test/1', true);
        $off = Planner::advisoryIssueBody([...$advisory, 'severity' => 'low'], 'https://ci.test/1', true);

        $this->assertStringContainsString('`vendor/a`', $on);
        $this->assertStringContainsString('CVE-2026-1', $on);
        $this->assertStringContainsString('Automatic fix: started', $on);
        $this->assertStringContainsString('Automatic fix: off', $off);
    }

    #[Test]
    public function does_not_tag_when_only_development_requirements_changed(): void
    {
        $config = Planner::config();
        $before = ['require' => ['php' => '^8.4'], 'require-dev' => ['pint' => '^1.0']];
        $after  = ['require' => ['php' => '^8.4'], 'require-dev' => ['pint' => '^1.1']];

        $this->assertNull(Planner::planTag($config, '1.0.0', ['chore(deps): update the dependencies'], $before, $after)['version']);
        $this->assertNull(Planner::planTag($config, null, ['x'], $before, $after)['version']);
        $this->assertNull(Planner::planTag($config, '1.0.0', [], $before, $after)['version']);
        $this->assertNull(Planner::planTag(Planner::config(['tag' => ['when' => 'never']]), '1.0.0', ['x'], $before, $after)['version']);
    }

    #[Test]
    public function finds_a_package_that_only_the_group_brings(): void
    {
        $lock = [
            'packages'     => [
                ['name' => 'zairakai/laravel-dev-tools', 'require' => ['x/inherited' => '^1.0', 'x/shared' => '^1.0']],
                ['name' => 'x/inherited'],
                ['name' => 'x/shared'],
                ['name' => 'x/own', 'require' => ['x/shared' => '^1.0']],
            ],
            'packages-dev' => [],
        ];
        $manifest = ['require' => ['zairakai/laravel-dev-tools' => '^4.0'], 'require-dev' => ['x/own' => '^1.0']];

        $this->assertTrue(Planner::isInherited($lock, $manifest, 'x/inherited'));
        $this->assertFalse(Planner::isInherited($lock, $manifest, 'x/shared'));
        $this->assertFalse(Planner::isInherited($lock, $manifest, 'x/own'));
        $this->assertFalse(Planner::isInherited([], [], 'x/inherited'));
    }

    #[Test]
    public function finds_the_highest_plain_tag(): void
    {
        $this->assertSame('1.10.0', Planner::latestTag(['1.2.0', '1.10.0', 'v9.0.0', '2.0.0-rc1', 'latest']));
        $this->assertNull(Planner::latestTag(['v1.0.0', 'main']));
    }

    #[Test]
    public function fixes_without_waiting_only_the_serious_or_unknown_severities(): void
    {
        $this->assertTrue(Planner::isUrgent('critical'));
        $this->assertTrue(Planner::isUrgent('high'));
        $this->assertTrue(Planner::isUrgent(''));
        $this->assertFalse(Planner::isUrgent('medium'));
        $this->assertFalse(Planner::isUrgent('low'));
    }

    #[Test]
    public function keeps_the_held_packages_at_their_locked_version(): void
    {
        $locked = ['a/b' => 'v2.7.1', 'c/d' => '1.0.0'];

        $this->assertSame([], Planner::heldConstraints([], $locked));
        $this->assertSame(['--with', 'a/b:==v2.7.1'], Planner::heldConstraints(['a/b'], $locked));
        $this->assertSame(['--with', 'a/b:==v2.7.1', '--with', 'c/d:==1.0.0'], Planner::heldConstraints(['a/b', 'c/d'], $locked));
        $this->assertSame([], Planner::heldConstraints(['x/y'], $locked));
    }

    #[Test]
    public function lifts_an_entry_when_every_peer_accepts_the_version(): void
    {
        $entry = ['package' => 'guzzlehttp/guzzle', 'unblock' => ['version' => '8.0.0', 'peers' => ['a/b', 'c/d']]];

        $this->assertFalse(Planner::isLifted($entry, ['a/b' => '^7.8 || ^8.0', 'c/d' => '^7.8']));
        $this->assertFalse(Planner::isLifted($entry, ['a/b' => '^7.8 || ^8.0', 'c/d' => null]));
        $this->assertFalse(Planner::isLifted($entry, ['a/b' => '^7.8 || ^8.0']));
        $this->assertTrue(Planner::isLifted($entry, ['a/b' => '^7.8 || ^8.0', 'c/d' => '~8.0']));
        $this->assertFalse(Planner::isLifted(['package' => 'x/y'], []));
    }

    #[Test]
    public function lists_and_formats_the_changes(): void
    {
        $rows = Planner::diffDependencies(['require-dev' => ['pint' => '^1.0']], ['require-dev' => ['pint' => '^1.1', 'rector' => '^2.0']]);

        $this->assertSame([
            ['section' => 'require-dev', 'name' => 'pint', 'from' => '^1.0', 'to' => '^1.1'],
            ['section' => 'require-dev', 'name' => 'rector', 'from' => null, 'to' => '^2.0'],
        ], $rows);
        $this->assertStringContainsString('| require-dev | rector | - | ^2.0 |', Planner::formatChanges($rows));
    }

    #[Test]
    public function looks_at_the_published_section_only(): void
    {
        $before = ['require' => ['php' => '^8.4'], 'require-dev' => ['pint' => '^1.0']];

        $this->assertFalse(Planner::runtimeChanged($before, [...$before, 'require-dev' => ['pint' => '^2.0']]));
        $this->assertTrue(Planner::runtimeChanged($before, [...$before, 'require' => ['php' => '^8.4 || ^8.5']]));
        $this->assertFalse(Planner::runtimeChanged(['require' => ['a' => '1', 'b' => '2']], ['require' => ['b' => '2', 'a' => '1']]));
    }

    #[Test]
    public function notifies_from_medium_up_and_for_an_unknown_severity(): void
    {
        foreach (['critical', 'high', 'medium', 'moderate', ''] as $severity) {
            $this->assertTrue(Planner::isNotable($severity));
        }

        $this->assertFalse(Planner::isNotable('low'));
    }

    #[Test]
    public function reads_the_advisories_of_a_composer_audit_report(): void
    {
        $report = ['advisories' => [
            'vendor/b' => [['advisoryId' => 'PKSA-2', 'packageName' => 'vendor/b', 'title' => 'Second', 'link' => 'https://example.test/2', 'cve' => 'CVE-2026-2', 'severity' => 'HIGH', 'affectedVersions' => '<2.0']],
            'vendor/a' => [['advisoryId' => 'PKSA-1', 'title' => 'First', 'affectedVersions' => '>=1.0,<1.5']],
        ]];

        $rows = Planner::advisories($report);

        $this->assertSame(['vendor/a', 'vendor/b'], array_column($rows, 'package'));
        $this->assertSame('', $rows[0]['severity']);
        $this->assertSame('high', $rows[1]['severity']);
        $this->assertSame('CVE-2026-2', $rows[1]['cve']);
        $this->assertSame([], Planner::advisories(['advisories' => []]));
        $this->assertSame([], Planner::advisories([]));
    }

    #[Test]
    public function reads_the_locked_versions(): void
    {
        $lock = ['packages' => [['name' => 'a/b', 'version' => '1.2.3']], 'packages-dev' => [['name' => 'c/d', 'version' => '4.5.6']]];

        $this->assertSame(['a/b' => '1.2.3', 'c/d' => '4.5.6'], Planner::lockVersions($lock));
        $this->assertSame([], Planner::lockVersions([]));
    }

    #[Test]
    public function reads_the_range_in_which_the_latest_stable_release_requires_a_package(): void
    {
        $releases = [
            ['name' => 'a/b', 'version' => '3.0.0-beta.1', 'require' => ['g/g' => '^8.0']],
            ['version' => '2.1.0', 'require' => ['g/g' => '^7.8 || ^8.0']],
            ['version' => '2.0.0', 'require' => ['g/g' => '^7.8']],
            ['version' => 'dev-main'],
        ];

        $this->assertSame('^7.8 || ^8.0', Planner::peerRange($releases, 'g/g'));
        $this->assertNull(Planner::peerRange($releases, 'other/package'));
        $this->assertNull(Planner::peerRange([], 'g/g'));
        $this->assertSame('2.1.0', Planner::latestStable(Planner::expandReleases($releases))['version'] ?? null);
        $this->assertNull(Planner::latestStable([['version' => 'dev-main']]));
    }

    #[Test]
    public function refuses_to_sign_without_the_group_variables(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Planner::signerIdentity(['RELEASE_SIGNER_NAME' => 'x']);
    }

    #[Test]
    public function reports_the_packages_of_the_policy_that_a_change_updates(): void
    {
        $before = ['require' => ['a/a' => '^1.0', 'b/b' => '^1.0'], 'require-dev' => ['c/c' => '^1.0']];
        $after  = ['require' => ['a/a' => '^2.0', 'b/b' => '^1.1', 'd/d' => '^1.0'], 'require-dev' => []];

        $this->assertSame([], Planner::policyViolations([], $before, $after, [], []));
        $this->assertSame(
            ['composer.json: a/a moves from ^1.0 to ^2.0.', 'composer.lock: e/e moves from 1.0.0 to 1.0.1.'],
            Planner::policyViolations(['a/a', 'c/c', 'd/d', 'e/e', 'f/f'], $before, $after, ['e/e' => '1.0.0', 'f/f' => '1.0.0'], ['e/e' => '1.0.1']),
        );
    }

    #[Test]
    public function sends_nothing_when_only_low_advisories_were_opened(): void
    {
        $this->assertNull(Planner::auditAlert([['advisory' => ['package' => 'c/d', 'id' => 'GHSA-2', 'severity' => 'low'], 'url' => 'u']], 'p'));
        $this->assertNull(Planner::auditAlert([], 'p'));
    }

    #[Test]
    public function sends_one_message_for_the_notable_advisories_and_counts_the_low_ones(): void
    {
        $alert = Planner::auditAlert([
            ['advisory' => ['package' => 'a/b', 'id' => 'GHSA-1', 'severity' => 'high'], 'url' => 'https://x.test/1'],
            ['advisory' => ['package' => 'c/d', 'id' => 'GHSA-2', 'severity' => 'low'], 'url' => 'https://x.test/2'],
        ], 'zairakai/php-packages/x');

        $this->assertSame([
            'title'   => 'Security advisories: 1 new in zairakai/php-packages/x',
            'message' => "a/b (GHSA-1, high): https://x.test/1\n1 more of a low severity, issue only.",
        ], $alert);
    }

    #[Test]
    public function sets_the_priority_from_the_severity_and_the_status(): void
    {
        $this->assertSame('Kind::Security,Priority::Critical,Status::Ready', Planner::advisoryLabels(['severity' => 'critical'], 'Kind::Security'));
        $this->assertSame('Kind::Security,Priority::High,Status::Ready', Planner::advisoryLabels(['severity' => 'high'], 'Kind::Security'));
        $this->assertSame('Kind::Security,Priority::Low,Status::Ready', Planner::advisoryLabels(['severity' => 'medium'], 'Kind::Security'));
    }

    #[Test]
    public function syncs_the_ignore_rules_of_dependabot_with_the_policy(): void
    {
        $file = static fn (array $rules): string => implode("\n", [
            'updates:',
            '  - package-ecosystem: composer',
            '    directory: /',
            '    # BEGIN dependency-policy (composer)',
            ...$rules,
            '    # END dependency-policy (composer)',
            '    schedule:',
            '      interval: weekly',
            '',
        ]);

        $this->assertSame(
            $file(['    ignore:', '      - dependency-name: "a/a"', '      - dependency-name: "b/b"']),
            Planner::syncDependabot($file([]), ['a/a', 'b/b'], 'composer'),
        );
        $this->assertSame($file([]), Planner::syncDependabot($file(['    ignore:', '      - dependency-name: "old/old"']), [], 'composer'));
        $this->assertSame($file([]), Planner::syncDependabot($file([]), [], 'composer'));
        $this->assertNull(Planner::syncDependabot($file([]), ['a/a'], 'npm'));
        $this->assertNull(Planner::syncDependabot("updates: []\n", ['a/a'], 'composer'));
    }

    #[Test]
    public function tags_when_a_published_range_changed(): void
    {
        $plan = Planner::planTag(Planner::config(['tag' => ['level' => 'minor']]), '1.2.3', ['x'], ['require' => ['a' => '^1']], ['require' => ['a' => '^2']]);

        $this->assertSame('1.3.0', $plan['version']);
    }

    #[Test]
    public function titles_the_issue_of_an_advisory_with_a_stable_key(): void
    {
        $this->assertSame('Security advisory: vendor/a (PKSA-1)', Planner::advisoryTitle(['id' => 'PKSA-1', 'package' => 'vendor/a']));
        $this->assertStringStartsWith(Planner::ADVISORY_TITLE_PREFIX, Planner::advisoryTitle(['id' => 'x', 'package' => 'y']));
    }

    #[Test]
    public function writes_the_policy_as_the_file_is_and_removes_lifted_entries(): void
    {
        $doc = ['entries' => [
            ['package' => 'a/a', 'reason' => 'r', 'since' => '2026-10-11', 'unblock' => ['version' => '2.0.0', 'peers' => ['b/b']]],
            ['package' => 'c/c', 'reason' => 'r', 'since' => '2026-10-12'],
        ]];

        $this->assertSame("{\n  \"entries\": []\n}\n", Planner::encodePolicy(Planner::withoutEntries($doc, ['a/a', 'c/c'])));
        $this->assertSame(['c/c'], Planner::policyPackages(Planner::withoutEntries($doc, ['a/a'])));
        $this->assertStringContainsString("\n        \"peers\": [\n          \"b/b\"\n        ]", Planner::encodePolicy($doc));
    }
}
