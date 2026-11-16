<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Tests\Unit\Update;

use PHPUnit\Framework\Attributes\Test;
use Zairakai\LaravelDevTools\Tests\Unit\Update\Support\UpdateTestCase;
use Zairakai\LaravelDevTools\Update\MergeRequests;
use Zairakai\LaravelDevTools\Update\Signing;
use Zairakai\LaravelDevTools\Update\TagRun;

final class TagRunTest extends UpdateTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->env('CI_COMMIT_SHA', 'abc123');
        $this->writeManifest([
            'name'    => 'acme/package',
            'require' => [
                'a/a' => '^1.1',
            ],
        ]);
        $this->shell
            ->answer('git tag --list', "0.9.0\n1.0.0\n1.1.0\nnot-a-version")
            ->answer('git log 1.1.0..HEAD', MergeRequests::COMMIT_SUBJECT)
            ->answer('git show 1.1.0:composer.json', (string) json_encode([
                'name'    => 'acme/package',
                'require' => [
                    'a/a' => '^1.0',
                ],
            ]));
    }

    #[Test]
    public function it_does_not_tag_when_no_update_was_made_since_the_last_tag(): void
    {
        $this->shell->answer('git log 1.1.0..HEAD', '');

        $this->runTag('runtime');

        $this->assertTrue($this->shell->logged('No tag: no update since the last tag.'));
    }

    #[Test]
    public function it_does_not_tag_when_only_the_development_requirements_changed(): void
    {
        $this->writeManifest([
            'name'        => 'acme/package',
            'require'     => [
                'a/a' => '^1.0',
            ],
            'require-dev' => [
                'tool/x' => '^2.0',
            ],
        ]);

        $this->runTag('runtime');

        $this->assertTrue($this->shell->logged('No tag: only the development requirements changed'));
        $this->assertFalse($this->shell->ran('git tag -s'));
    }

    #[Test]
    public function it_does_not_tag_when_the_project_is_never_tagged(): void
    {
        $this->runTag('never');

        $this->assertTrue($this->shell->logged('No tag: the project is never tagged by the update.'));
        $this->assertFalse($this->shell->ran('git push'));
    }

    #[Test]
    public function it_does_not_tag_when_there_is_no_tag_yet(): void
    {
        $this->shell->answer('git tag --list', '');

        $this->runTag('runtime');

        $this->assertTrue($this->shell->logged('No tag: there is no tag yet.'));
        $this->assertFalse($this->shell->ran('git tag -s'));
    }

    #[Test]
    public function it_signs_and_pushes_the_next_patch_when_a_published_range_changed(): void
    {
        $this->runTag('runtime');

        $this->assertTrue($this->shell->ran('git fetch --tags --quiet'));
        $this->assertTrue($this->shell->ran('git log 1.1.0..HEAD --format=%s --grep=^chore(deps): update the dependencies'));
        $this->assertTrue($this->shell->ran('git tag -s 1.1.1 -m 1.1.1 abc123'));
        $this->assertTrue($this->shell->ran('git push https://oauth2:token@gitlab.test/group/project.git refs/tags/1.1.1'));
        $this->assertTrue($this->shell->logged('Tag 1.1.1 signed and pushed (last tag 1.1.0): a published range changed.'));
    }

    #[Test]
    public function it_signs_before_it_tags(): void
    {
        $this->runTag('runtime');

        $commands = $this->shell->commands;
        $signing  = array_search('gpg --batch --import', $commands, true);
        $tag      = array_key_first(array_filter($commands, static fn (string $line): bool => str_starts_with($line, 'git tag -s')));

        $this->assertNotFalse($signing);
        $this->assertLessThan($tag, $signing);
    }

    #[Test]
    public function it_tags_a_change_of_the_development_requirements_when_the_project_asks_for_it(): void
    {
        $this->writeManifest([
            'name'        => 'acme/package',
            'require'     => [
                'a/a' => '^1.0',
            ],
            'require-dev' => [
                'tool/x' => '^2.0',
            ],
        ]);

        $this->runTag('always');

        $this->assertTrue($this->shell->ran('git tag -s 1.1.1'));
    }

    #[Test]
    public function it_takes_the_level_of_the_configuration(): void
    {
        $this->runTag('runtime', 'minor');

        $this->assertTrue($this->shell->ran('git tag -s 1.2.0 -m 1.2.0 abc123'));
    }

    private function runTag(string $when, string $level = 'patch'): void
    {
        new TagRun(
            $this->shell,
            $this->project(),
            new Signing($this->shell),
            [
                'tag' => [
                    'when'  => $when,
                    'level' => $level,
                ],
            ],
        )->run();
    }
}
