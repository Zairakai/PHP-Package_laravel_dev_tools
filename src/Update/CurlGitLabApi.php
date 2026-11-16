<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Update;

/**
 * A call of the API of GitLab.
 */
final readonly class CurlGitLabApi implements GitLabApi
{
    public function __construct(private Shell $shell) {}

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
    ): array {
        $handle = curl_init($this->shell->env('CI_API_V4_URL', 'https://gitlab.com/api/v4') . $path);

        curl_setopt(
            $handle,
            CURLOPT_CUSTOMREQUEST,
            $method,
        );

        curl_setopt(
            $handle,
            CURLOPT_RETURNTRANSFER,
            true,
        );

        curl_setopt($handle, CURLOPT_HTTPHEADER, [
            'PRIVATE-TOKEN: ' . ($token ?? getenv('GITLAB_TOKEN')),
            'Content-Type: application/json',
        ]);

        curl_setopt(
            $handle,
            CURLOPT_TIMEOUT,
            60,
        );

        if (null !== $body) {
            curl_setopt(
                $handle,
                CURLOPT_POSTFIELDS,
                json_encode($body, JSON_THROW_ON_ERROR),
            );
        }

        $text   = (string) curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        curl_close($handle);

        return [
            'status' => $status,
            'ok'     => 200 <= $status && 300 > $status,
            'data'   => json_decode($text, true) ?? $text,
        ];
    }

    /**
     * Starts the update of the dependencies in a project: a pipeline of `develop` with UPDATE_DEPENDENCIES.
     *
     * @return array{status: int, ok: bool, data: mixed}
     */
    public function startUpdate(string $projectId, ?string $token = null): array
    {
        return $this->call('POST', "/projects/{$projectId}/pipeline", [
            'ref'       => 'develop',
            'variables' => [
                [
                    'key'   => 'UPDATE_DEPENDENCIES',
                    'value' => 'true',
                ],
            ],
        ], $token);
    }
}
