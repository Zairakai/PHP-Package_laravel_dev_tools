<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Update;

/**
 * The requests that are not GitLab: Packagist, and the push notification.
 */
final readonly class WebNetwork implements Network
{
    /**
     * A request that can fail: the failure is the value false, never a warning.
     *
     * @param resource|null $context
     */
    public function download(string $url, $context = null): string|false
    {
        set_error_handler(static fn (): bool => true);

        try {
            return file_get_contents(
                $url,
                false,
                $context,
            );
        }
        finally {
            restore_error_handler();
        }
    }

    /**
     * A push notification through ntfy, when NTFY_URL is set. A failure never stops the audit.
     */
    public function notify(string $title, string $message): void
    {
        $url = (string) getenv('NTFY_URL');

        if ('' === $url) {
            return;
        }

        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Title: {$title}\r\nContent-Type: text/plain",
                'content'       => $message,
                'timeout'       => 15,
                'ignore_errors' => true,
            ],
        ]);
        $this->download($url, $context);
    }

    public function onPackagist(string $name, string $version): bool
    {
        $json = $this->download("https://repo.packagist.org/p2/{$name}.json");

        if (false === $json) {
            return false;
        }

        /** @var array{packages?: array<string, list<array{version?: string}>>} $data */
        $data = json_decode($json, true) ?? [];

        foreach ($data['packages'][$name] ?? [] as $release) {
            if (($release['version'] ?? null) === $version) {
                return true;
            }
        }

        return false;
    }

    /**
     * The releases of a package on Packagist (metadata p2, as listed), or an empty list.
     *
     * @return list<array<string, mixed>>
     */
    public function packagistReleases(string $name): array
    {
        $json = $this->download("https://repo.packagist.org/p2/{$name}.json", stream_context_create([
            'http' => [
                'timeout' => 20,
            ],
        ]));

        if (false === $json) {
            return [];
        }

        $data = Json::map(json_decode($json, true));

        return Json::rows(Json::map($data['packages'] ?? null)[$name] ?? []);
    }
}
