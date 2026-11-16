<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Update;

/**
 * A call of the API of GitLab.
 */
interface GitLabApi
{
    /**
     * @param non-empty-string          $method
     * @param array<string, mixed>|null $body
     * @param string|null               $token  The token of the call, GITLAB_TOKEN when not given.
     *
     * @return array{status: int, ok: bool, data: mixed}
     */
    public function call(
        string $method,
        string $path,
        ?array $body = null,
        ?string $token = null,
    ): array;

    /**
     * Starts the update of the dependencies in a project: a pipeline of `develop` with UPDATE_DEPENDENCIES.
     *
     * @return array{status: int, ok: bool, data: mixed}
     */
    public function startUpdate(string $projectId, ?string $token = null): array;
}
