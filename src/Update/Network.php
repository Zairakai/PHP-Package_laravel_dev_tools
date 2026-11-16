<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Update;

/**
 * The requests that are not GitLab: Packagist, and the push notification.
 */
interface Network
{
    /**
     * A request that can fail: the failure is the value false, never a warning.
     *
     * @param resource|null $context
     */
    public function download(string $url, $context = null): string|false;

    /**
     * A push notification through ntfy, when NTFY_URL is set. A failure never stops the audit.
     */
    public function notify(string $title, string $message): void;

    public function onPackagist(string $name, string $version): bool;

    /**
     * The releases of a package on Packagist (metadata p2, as listed), or an empty list.
     *
     * @return list<array<string, mixed>>
     */
    public function packagistReleases(string $name): array;
}
