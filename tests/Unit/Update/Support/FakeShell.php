<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Tests\Unit\Update\Support;

use Closure;
use Throwable;
use Zairakai\LaravelDevTools\Update\Shell;

/**
 * A shell that runs nothing: it keeps the commands that it was given and answers what the test scripted.
 */
final class FakeShell implements Shell
{
    /**
     * @var list<string> the commands that were run, each one as a line
     */
    public array $commands = [];

    /**
     * @var list<string>
     */
    public array $errors = [];

    /**
     * @var list<string>
     */
    public array $logs = [];

    /**
     * @var array<string, string|Throwable> the answer of the commands that start with the key
     */
    private array $answers = [];

    /**
     * @var array<string, Closure(): void> what a command that starts with the key does to the project, when it runs
     */
    private array $effects = [];

    /**
     * @param array<string, string> $env
     */
    public function __construct(private readonly array $env = []) {}

    public function answer(string $commandStart, string|Throwable $answer): self
    {
        $this->answers[$commandStart] = $answer;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function commandsStartingWith(string $commandStart): array
    {
        return array_values(array_filter(
            $this->commands,
            static fn (string $line): bool => str_starts_with($line, $commandStart),
        ));
    }

    public function env(string $name, string $default = ''): string
    {
        $value = $this->env[$name] ?? '';

        return '' === $value
            ? $default
            : $value;
    }

    public function error(string $message): void
    {
        $this->errors[] = $message;
    }

    public function exec(array $command, ?string $input = null, array $allowed = [0]): string
    {
        $line             = implode(' ', $command);
        $this->commands[] = $line;
        $answer           = $this->answerFor($line);

        foreach ($this->effects as $start => $effect) {
            if (str_starts_with($line, $start)) {
                $effect();
            }
        }

        if ($answer instanceof Throwable) {
            throw $answer;
        }

        return $answer;
    }

    public function log(string $message): void
    {
        $this->logs[] = $message;
    }

    public function logged(string $text): bool
    {
        return array_any($this->logs, static fn (string $line): bool => str_contains($line, $text));
    }

    public function mask(string $text): string
    {
        return $text;
    }

    /**
     * Whether a command that starts with this text was run.
     */
    public function ran(string $commandStart): bool
    {
        return [] !== $this->commandsStartingWith($commandStart);
    }

    public function remoteUrl(): string
    {
        return 'https://oauth2:token@gitlab.test/group/project.git';
    }

    /**
     * @param Closure(): void $effect
     */
    public function then(string $commandStart, Closure $effect): self
    {
        $this->effects[$commandStart] = $effect;

        return $this;
    }

    private function answerFor(string $line): string|Throwable
    {
        $best  = '';
        $found = '';

        foreach ($this->answers as $start => $answer) {
            if (str_starts_with($line, $start) && strlen($start) >= strlen($best)) {
                $best  = $start;
                $found = $answer;
            }
        }

        return $found;
    }
}
