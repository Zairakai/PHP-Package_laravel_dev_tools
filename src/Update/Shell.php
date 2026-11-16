<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Update;

/**
 * What the update runs on the machine: commands in the project, the environment, the log, and the masking of the tokens.
 */
interface Shell
{
    /**
     * An environment variable, or the default one when it is not set or empty.
     */
    public function env(string $name, string $default = ''): string;

    /**
     * A line on the error output: what the check found, for the log of the job.
     */
    public function error(string $message): void;

    /**
     * @param list<string> $command
     * @param list<int>    $allowed The exit statuses that are not a failure.
     */
    public function exec(array $command, ?string $input = null, array $allowed = [
        0,
    ]): string;

    public function log(string $message): void;

    public function mask(string $text): string;

    /**
     * The URL of the project with the token, to push.
     */
    public function remoteUrl(): string;
}
