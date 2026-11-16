<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Update;

use RuntimeException;
use Throwable;

/**
 * The dependency policy of the group (repository zairakai/dependency-policy, php.json).
 */
final readonly class Policy
{
    public const string FILE = 'php.json';

    private const string URL = 'https://gitlab.com/zairakai/dependency-policy/-/raw/main';

    public function __construct(
        private Shell $shell,
        private Network $network,
    ) {}

    /**
     * The policy of the group. Anything wrong stops the update, with an alert.
     *
     * @return array<string, mixed>
     */
    public function read(): array
    {
        $base = rtrim($this->shell->env('DEPENDENCY_POLICY_URL', self::URL), '/');
        $url  = "{$base}/php.json";

        try {
            $doc = $this->fetch($url);
        }
        catch (Throwable $throwable) {
            $message = "The dependency policy could not be read ({$url}): " . $throwable->getMessage();
            $this->network->notify('Dependency policy unreachable', getenv('CI_PROJECT_PATH') . ': ' . $message);

            throw new RuntimeException(
                $message,
                0,
                $throwable,
            );
        }

        $held = PolicyDocument::policyPackages($doc);

        $list = [] === $held
            ? 'nothing is held back'
            : implode(', ', $held);

        $this->shell->log('Dependency policy: ' . $list . '.');

        return $doc;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(string $url): array
    {
        $text = $this->network->download($url, stream_context_create([
            'http' => [
                'timeout' => 20,
            ],
        ]));

        if (false === $text) {
            throw new RuntimeException('no answer');
        }

        $doc    = Json::map(json_decode(
            $text,
            true,
            512,
            JSON_THROW_ON_ERROR,
        ));
        $errors = PolicyDocument::validatePolicy($doc);

        if ([] !== $errors) {
            throw new RuntimeException('not valid: ' . implode(' ', $errors));
        }

        return $doc;
    }
}
