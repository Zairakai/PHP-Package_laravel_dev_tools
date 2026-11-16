<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Update;

/**
 * The manifest and the lock file of the project that is updated.
 */
final readonly class Project
{
    public function __construct(
        private Shell $shell,
        private string $directory,
    ) {}

    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * The lock file, or an empty list when the project has none.
     *
     * @return array<string, mixed>
     */
    public function lock(): array
    {
        $file = $this->directory . '/composer.lock';

        return is_file($file)
            ? Json::map(json_decode((string) file_get_contents($file), true))
            : [];
    }

    /**
     * @return array<string, string>
     */
    public function lockVersions(): array
    {
        $file = $this->directory . '/composer.lock';

        return is_file($file) ? Manifest::lockVersions(
            Json::map(json_decode((string) file_get_contents($file), true)),
        ) : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function manifest(?string $ref = null): array
    {
        $json = null === $ref
            ? (string) file_get_contents($this->directory . '/composer.json')
            : $this->shell->exec([
                'git',
                'show',
                "{$ref}:composer.json",
            ]);

        return Json::map(json_decode(
            $json,
            true,
            512,
            JSON_THROW_ON_ERROR,
        ));
    }
}
