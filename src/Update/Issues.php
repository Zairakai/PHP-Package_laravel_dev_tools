<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Update;

use RuntimeException;

/**
 * The issues of the project that the audit opens and closes.
 */
final readonly class Issues
{
    public function __construct(
        private GitLabApi $api,
        private Shell $shell,
    ) {}

    /**
     * Close the open issues of the audit whose advisory is not found any more.
     *
     * @param list<string> $titles The titles of the advisories found today.
     */
    public function closeResolved(array $titles, string $token): void
    {
        $query  = http_build_query([
            'state'    => 'opened',
            'labels'   => 'Automated',
            'search'   => Advisories::ADVISORY_TITLE_PREFIX,
            'in'       => 'title',
            'per_page' => 100,
        ]);
        $issues = $this->api->call(
            'GET',
            '/projects/' . getenv('CI_PROJECT_ID') . "/issues?{$query}",
            null,
            $token,
        );

        foreach (Json::rows($issues['data']) as $issue) {
            $title = Json::text($issue, 'title');

            if (
                str_starts_with($title, Advisories::ADVISORY_TITLE_PREFIX)
                && ! in_array($title, $titles, true)
            ) {
                $this->close($issue, $title, $token);
            }
        }
    }

    public function create(
        string $title,
        string $description,
        string $labels,
        string $token,
    ): string {
        $assignee = $this->assignee($token);
        $issue    = $this->api->call('POST', '/projects/' . getenv('CI_PROJECT_ID') . '/issues', [
            'title'        => $title,
            'description'  => $description,
            'labels'       => $labels,
            'assignee_ids' => 0 < $assignee ? [
                $assignee,
            ] : [],
        ], $token);

        if (! $issue['ok']) {
            throw new RuntimeException(
                'The issue could not be created: ' . $this->shell->mask((string) json_encode($issue['data'])),
            );
        }

        return Json::text(Json::map($issue['data']), 'web_url');
    }

    public function exists(string $title, string $token): bool
    {
        $query  = http_build_query([
            'state'  => 'opened',
            'search' => $title,
            'in'     => 'title',
        ]);
        $issues = $this->api->call(
            'GET',
            '/projects/' . getenv('CI_PROJECT_ID') . "/issues?{$query}",
            null,
            $token,
        );

        return array_any(
            Json::rows($issues['data']),
            fn (array $issue): bool => Json::text($issue, 'title') === $title,
        );
    }

    /**
     * The assignee is given by the variable ISSUE_ASSIGNEE_ID; without it, the user of the token.
     */
    private function assignee(string $token): int
    {
        $assignee = (int) getenv('ISSUE_ASSIGNEE_ID');

        if (0 === $assignee) {
            $assignee = Json::int(Json::map($this->api->call(
                'GET',
                '/user',
                null,
                $token,
            )['data']), 'id');
        }

        return $assignee;
    }

    /**
     * @param array<string, mixed> $issue
     */
    private function close(array $issue, string $title, string $token): void
    {
        $path = '/projects/' . getenv('CI_PROJECT_ID') . '/issues/' . Json::int($issue, 'iid');

        $this->api->call(
            'POST',
            "{$path}/notes",
            [
                'body' => 'The audit of ' . getenv('CI_PIPELINE_URL') . ' no longer finds this advisory.',
            ],
            $token,
        );

        $closed = $this->api->call(
            'PUT',
            $path,
            [
                'state_event' => 'close',
            ],
            $token,
        );

        $this->shell->log(
            $closed['ok']
                ? "Issue closed: {$title}"
                : "Issue not closed ({$closed['status']}): {$title}",
        );
    }
}
