<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Update;

/**
 * The advisories that `composer audit` finds in the lock file of the project.
 */
final readonly class ComposerAudit
{
    public function __construct(private Shell $shell) {}

    /**
     * @return list<array{id: string, package: string, title: string, link: string, cve: string, severity: string, range: string}>
     */
    public function advisories(): array
    {
        $report = json_decode(
            $this->shell->exec(
                [
                    'composer',
                    'audit',
                    '--locked',
                    '--format=json',
                    '--no-interaction',
                ],
                null,
                [
                    0,
                    1,
                    2,
                    3,
                ],
            ),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        return Advisories::advisories(Json::map($report));
    }
}
