<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Services;

use Zairakai\LaravelDevTools\Contracts\PublishOutput;

/**
 * An output that says nothing: for a publication that is not shown to the user (the check and the update of the setup).
 */
final readonly class SilentPublishOutput implements PublishOutput
{
    public function error(string $message): void
    {
        // Nothing to show
    }

    public function task(string $description): void
    {
        // Nothing to show
    }

    public function twoColumnDetail(string $first, string $second = ''): void
    {
        // Nothing to show
    }

    public function warn(string $message): void
    {
        // Nothing to show
    }
}
