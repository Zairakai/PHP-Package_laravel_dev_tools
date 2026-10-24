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
}
