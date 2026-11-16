<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Tests\Unit\Update\Support;

use Zairakai\LaravelDevTools\Update\GitLabApi;

/**
 * An API of GitLab that calls nothing: it keeps the calls and answers what the test scripted.
 */
final class FakeGitLab implements GitLabApi
{
    /**
     * @var list<array{method: string, path: string, body: array<string, mixed>|null, token: string|null}>
     */
    public array $calls = [];

    /**
     * @var list<array{project: string, token: string|null}>
     */
    public array $updatesStarted = [];

    /**
     * @var array<string, array{status: int, data: mixed}> the answer of the calls "METHOD path" that start with the key
     */
    private array $replies = [];

    private bool $startFails = false;

    public function call(string $method, string $path, ?array $body = null, ?string $token = null): array
    {
        $this->calls[] = [
            'method' => $method,
            'path'   => $path,
            'body'   => $body,
            'token'  => $token,
        ];

        $best  = '';
        $reply = [
            'status' => 200,
            'data'   => [],
        ];

        foreach ($this->replies as $start => $candidate) {
            if (str_starts_with("{$method} {$path}", $start) && strlen($start) >= strlen($best)) {
                $best  = $start;
                $reply = $candidate;
            }
        }

        return [
            'status' => $reply['status'],
            'ok'     => 200 <= $reply['status'] && 300 > $reply['status'],
            'data'   => $reply['data'],
        ];
    }

    /**
     * The calls whose "METHOD path" starts with the text.
     *
     * @return list<array{method: string, path: string, body: array<string, mixed>|null, token: string|null}>
     */
    public function callsTo(string $request): array
    {
        return array_values(array_filter(
            $this->calls,
            static fn (array $call): bool => str_starts_with("{$call['method']} {$call['path']}", $request),
        ));
    }

    public function failToStart(): self
    {
        $this->startFails = true;

        return $this;
    }

    public function reply(string $request, mixed $data, int $status = 200): self
    {
        $this->replies[$request] = [
            'status' => $status,
            'data'   => $data,
        ];

        return $this;
    }

    public function startUpdate(string $projectId, ?string $token = null): array
    {
        $this->updatesStarted[] = [
            'project' => $projectId,
            'token'   => $token,
        ];

        return [
            'status' => $this->startFails ? 500 : 201,
            'ok'     => ! $this->startFails,
            'data'   => [],
        ];
    }
}
