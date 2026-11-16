<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Tests\Unit\Update\Support;

use Zairakai\LaravelDevTools\Update\Network;

/**
 * A network that reaches nothing: Packagist, the policy and the notifications are what the test scripted.
 */
final class FakeNetwork implements Network
{
    /**
     * @var list<array{title: string, message: string}>
     */
    public array $notifications = [];

    public int $packagistChecks = 0;

    /**
     * @var list<string> the packages "name@version" that are on Packagist
     */
    private array $listed = [];

    /**
     * @var array<string, string|false> the content of the addresses
     */
    private array $pages = [];

    /**
     * @var array<string, list<array<string, mixed>>>
     */
    private array $releases = [];

    public function download(string $url, $context = null): string|false
    {
        return $this->pages[$url] ?? false;
    }

    public function listOnPackagist(string $name, string $version): self
    {
        $this->listed[] = "{$name}@{$version}";

        return $this;
    }

    public function notify(string $title, string $message): void
    {
        $this->notifications[] = [
            'title'   => $title,
            'message' => $message,
        ];
    }

    public function onPackagist(string $name, string $version): bool
    {
        $this->packagistChecks++;

        return in_array("{$name}@{$version}", $this->listed, true);
    }

    public function packagistReleases(string $name): array
    {
        return $this->releases[$name] ?? [];
    }

    public function page(string $url, string|false $content): self
    {
        $this->pages[$url] = $content;

        return $this;
    }

    /**
     * @param list<array<string, mixed>> $releases
     */
    public function releases(string $name, array $releases): self
    {
        $this->releases[$name] = $releases;

        return $this;
    }
}
