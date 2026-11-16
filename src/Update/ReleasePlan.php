<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Update;

use InvalidArgumentException;

/**
 * The tag to create after an update, and who signs it.
 */
final class ReleasePlan
{
    private const string DEVELOPMENT_ONLY = 'only the development requirements changed: '
        . 'the published archive is the same';

    /**
     * What `tag` has to do: the version to create (or null) and why.
     *
     * @param array{tag: array{when: string, level: string}} $config
     * @param list<string>                                   $commits
     * @param array<string, mixed>|null                      $before
     * @param array<string, mixed>                           $after
     *
     * @return array{version: ?string, reason: string}
     */
    public static function planTag(
        array $config,
        ?string $last,
        array $commits,
        ?array $before,
        array $after,
    ): array {
        $skip = self::skipReason($config, $last, $commits, $before, $after);

        return null === $skip
            ? [
                'version' => Versions::nextVersion((string) $last, $config['tag']['level']),
                'reason'  => 'a published range changed',
            ]
            : [
                'version' => null,
                'reason'  => $skip,
            ];
    }

    /**
     * The identity and the key used to sign, from the group variables. It fails when one is missing: no unsigned tag.
     *
     * @param array<string, string> $env
     *
     * @return array{name: string, email: string, key: string}
     */
    public static function signerIdentity(array $env): array
    {
        $missing = array_values(array_filter(
            [
                'GPG_PRIVATE_KEY_B64',
                'RELEASE_SIGNER_NAME',
                'RELEASE_SIGNER_EMAIL',
            ],
            static fn (string $name): bool => ! isset($env[$name]) || '' === $env[$name],
        ));

        if ([] !== $missing) {
            throw new InvalidArgumentException(
                implode(
                    ', ',
                    $missing,
                ) . ' not set: the commits and the tags are signed, see the handbook (versioning).',
            );
        }

        return [
            'name'  => $env['RELEASE_SIGNER_NAME'],
            'email' => $env['RELEASE_SIGNER_EMAIL'],
            'key'   => (string) base64_decode($env['GPG_PRIVATE_KEY_B64'], true),
        ];
    }

    /**
     * @param array{tag: array{when: string, level: string}} $config
     * @param array<string, mixed>|null                      $before
     * @param array<string, mixed>                           $after
     */
    private static function onlyDevelopment(array $config, ?array $before, array $after): bool
    {
        return 'runtime' === $config['tag']['when']
            && ! Manifest::runtimeChanged($before, $after);
    }

    /**
     * Why no tag is created, or null when one is.
     *
     * @param array{tag: array{when: string, level: string}} $config
     * @param list<string>                                   $commits
     * @param array<string, mixed>|null                      $before
     * @param array<string, mixed>                           $after
     */
    private static function skipReason(
        array $config,
        ?string $last,
        array $commits,
        ?array $before,
        array $after,
    ): ?string {
        return match (true) {
            'never' === $config['tag']['when']              => 'the project is never tagged by the update',
            null    === $last                               => 'there is no tag yet',
            []      === $commits                            => 'no update since the last tag',
            self::onlyDevelopment($config, $before, $after) => self::DEVELOPMENT_ONLY,
            default                                         => null,
        };
    }
}
