<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Services;

use Zairakai\LaravelDevTools\Contracts\PublishOutput;

/**
 * The output of the publication outside Artisan (the setup script of a project with or without Laravel): plain lines,
 * without the tags of the Symfony console.
 */
final readonly class TextPublishOutput implements PublishOutput
{
    public function error(string $message): void
    {
        fwrite(STDERR, 'ERROR: ' . $message . "\n");
    }

    public function task(string $description): void
    {
        echo $description . "\n";
    }

    public function twoColumnDetail(string $first, string $second = ''): void
    {
        echo trim($first . '  ' . strip_tags($second)) . "\n";
    }

    public function warn(string $message): void
    {
        echo 'WARNING: ' . $message . "\n";
    }
}
