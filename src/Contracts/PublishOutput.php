<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Contracts;

/**
 * What the publication of the configuration needs to tell the user.
 *
 * The publisher does not depend on Laravel: the console of Artisan is one implementation, a plain text output for the
 * projects without Laravel is another.
 */
interface PublishOutput
{
    public function error(string $message): void;

    /**
     * An action that was done (copied, generated, backed up).
     */
    public function task(string $description): void;

    /**
     * A line in two columns. The second column may hold the tags of Symfony console (`<fg=yellow>…</>`).
     */
    public function twoColumnDetail(string $first, string $second = ''): void;

    public function warn(string $message): void;
}
