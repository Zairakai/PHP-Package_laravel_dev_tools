<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Update;

use RuntimeException;

/**
 * What the update runs on the machine: commands in the project, the environment, the log, and the masking of the tokens.
 */
final readonly class ProcessShell implements Shell
{
    public function __construct(private string $directory) {}

    /**
     * An environment variable, or the default one when it is not set or empty.
     */
    public function env(string $name, string $default = ''): string
    {
        $value = getenv($name);

        return false === $value || '' === $value
            ? $default
            : $value;
    }

    public function error(string $message): void
    {
        fwrite(STDERR, $message . "\n");
    }

    /**
     * @param list<string> $command
     * @param list<int>    $allowed The exit statuses that are not a failure.
     */
    public function exec(array $command, ?string $input = null, array $allowed = [
        0,
    ]): string
    {
        $process = proc_open($command, [
            0 => [
                'pipe',
                'r',
            ],
            1 => [
                'pipe',
                'w',
            ],
            2 => [
                'pipe',
                'w',
            ],
        ], $pipes, $this->directory);

        if (! is_resource($process)) {
            throw new RuntimeException('Cannot run ' . $command[0] . '.');
        }

        fwrite($pipes[0], $input ?? '');
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        $error  = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        if (! in_array(
            proc_close($process),
            $allowed,
            true,
        )) {
            throw new RuntimeException($this->mask(implode(' ', $command) . ' failed: ' . $error));
        }

        return trim($output);
    }

    public function log(string $message): void
    {
        fwrite(STDOUT, $message . "\n");
    }

    public function mask(string $text): string
    {
        foreach ([
            'GITLAB_TOKEN',
            'FRESHNESS_TOKEN',
        ] as $name) {
            $token = (string) getenv($name);
            $text  = '' === $token
                ? $text
                : str_replace(
                    $token,
                    '***',
                    $text,
                );
        }

        return $text;
    }

    /**
     * The URL of the project with the token, to push.
     */
    public function remoteUrl(): string
    {
        return sprintf(
            'https://oauth2:%s@%s/%s.git',
            getenv('GITLAB_TOKEN'),
            getenv('CI_SERVER_HOST'),
            getenv('CI_PROJECT_PATH'),
        );
    }
}
