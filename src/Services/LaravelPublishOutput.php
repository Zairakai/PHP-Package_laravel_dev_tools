<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Services;

use Illuminate\Console\View\Components\Factory;
use Zairakai\LaravelDevTools\Contracts\PublishOutput;

/**
 * The output of the publication in an Artisan command: it passes everything to the components of the console.
 */
final readonly class LaravelPublishOutput implements PublishOutput
{
    public function __construct(private Factory $components) {}

    public function error(string $message): void
    {
        $this->components->error($message);
    }

    public function task(string $description): void
    {
        $this->components->task($description);
    }

    public function twoColumnDetail(string $first, string $second = ''): void
    {
        $this->components->twoColumnDetail($first, $second);
    }

    public function warn(string $message): void
    {
        $this->components->warn($message);
    }
}
