<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Update;

/**
 * The signature of the commits and of the tags of the update.
 */
final readonly class Signing
{
    public function __construct(private Shell $shell) {}

    /**
     * Import the signing key and make Git sign the commits and the tags with it.
     */
    public function configure(): void
    {
        $signer = ReleasePlan::signerIdentity(getenv());

        $this->shell->exec([
            'gpg',
            '--batch',
            '--import',
        ], $signer['key']);

        $fingerprint = $this->fingerprint();

        foreach ([
            ['user.name', $signer['name']],
            ['user.email', $signer['email']],
            ['user.signingkey', $fingerprint],
            ['commit.gpgsign', 'true'],
            ['tag.gpgsign', 'true'],
        ] as [$key, $value]) {
            $this->shell->exec([
                'git',
                'config',
                $key,
                $value,
            ]);
        }
    }

    /**
     * The fingerprint of the first secret key, or an empty string.
     */
    private function fingerprint(): string
    {
        foreach (explode("\n", $this->shell->exec([
            'gpg',
            '--list-secret-keys',
            '--with-colons',
        ])) as $line) {
            if (str_starts_with($line, 'fpr')) {
                return explode(':', $line)[9];
            }
        }

        return '';
    }
}
