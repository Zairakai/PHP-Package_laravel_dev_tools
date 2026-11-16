<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Update;

/**
 * The configuration of the automatic update of a project.
 */
final class UpdateConfig
{
    /**
     * The file of the configuration, at the root of the project.
     */
    public const string FILE = '.dependency-update.json';

    /**
     * The configuration of a project, completed with the defaults.
     *
     * @param array<string, mixed> $user
     *
     * @return array{reject: list<string>, cooldown: string, tag: array{when: string, level: string}, cascade: ?array{groups: list<string>}}
     */
    public static function config(array $user = []): array
    {
        $tag     = Json::map($user['tag'] ?? []);
        $cascade = $user['cascade'] ?? null;

        return [
            'reject'   => Json::strings($user['reject'] ?? []),
            'cooldown' => Json::textOr(
                $user,
                'cooldown',
                '3d',
            ),
            'tag'      => [
                'when'  => Json::textOr(
                    $tag,
                    'when',
                    'runtime',
                ),
                'level' => Json::textOr(
                    $tag,
                    'level',
                    'patch',
                ),
            ],
            'cascade'  => null === $cascade ? null : [
                'groups' => Json::strings(Json::map($cascade)['groups'] ?? []),
            ],
        ];
    }
}
