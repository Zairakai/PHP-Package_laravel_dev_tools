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
    public function accepts_only_the_move_of_the_ref_of_the_shared_pipeline(): void
    {
        $header = "--- a/.gitlab-ci.yml\n+++ b/.gitlab-ci.yml\n@@ -6 +6 @@\n";

        $this->assertTrue(Planner::isRefOnlyChange($header . "-    ref: 4.3.0\n+    ref: 4.3.1"));
        $this->assertFalse(Planner::isRefOnlyChange($header . "-    file: \"a.yml\"\n+    file: \"b.yml\""));
        $this->assertFalse(Planner::isRefOnlyChange($header . "-    ref: 4.3.0\n+    ref: 4.3.1\n+  extra: true"));
        $this->assertFalse(Planner::isRefOnlyChange(''));
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
    public function refuses_to_sign_without_the_group_variables(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Planner::signerIdentity(['RELEASE_SIGNER_NAME' => 'x']);
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
}
