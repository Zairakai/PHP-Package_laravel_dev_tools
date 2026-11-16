<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Update;

use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * The merge requests of the update: the ones that are open, the new ones, and their merge when the pipeline succeeds.
 */
final readonly class MergeRequests
{
    public const string BRANCH_PREFIX = 'chore/update-dependencies';

    public const string COMMIT_SUBJECT = 'chore(deps): update the dependencies';

    public function __construct(
        private GitLabApi $api,
        private Shell $shell,
    ) {}

    public function hasOpenUpdate(): bool
    {
        return array_any(
            $this->opened(),
            fn (array $request): bool => str_starts_with(Json::text($request, 'source_branch'), self::BRANCH_PREFIX),
        );
    }

    /**
     * Merge the request when its pipeline succeeds. GitLab needs a moment to create the pipeline of a new request.
     */
    public function mergeWhenGreen(int $iid): void
    {
        for ($attempt = 0; 18 > $attempt; $attempt++) {
            $result = $this->api->call('PUT', '/projects/' . getenv('CI_PROJECT_ID') . "/merge_requests/{$iid}/merge", [
                'merge_when_pipeline_succeeds' => true,
                'should_remove_source_branch'  => true,
            ]);

            if ($result['ok']) {
                $this->shell->log("Merge request !{$iid}: it is merged when its pipeline succeeds.");

                return;
            }

            Sleep::sleep(10);
        }

        $this->shell->log("Merge request !{$iid}: it could not be set to merge by itself, it stays open.");
    }

    public function open(
        string $source,
        string $target,
        string $description,
        bool $removeSource,
        string $title = self::COMMIT_SUBJECT,
    ): void {
        $request = $this->api->call('POST', '/projects/' . getenv('CI_PROJECT_ID') . '/merge_requests', [
            'source_branch'        => $source,
            'target_branch'        => $target,
            'title'                => $title,
            'description'          => $description,
            'remove_source_branch' => $removeSource,
            ...$this->options(),
        ]);

        if (! $request['ok']) {
            throw new RuntimeException(
                'The merge request could not be created: ' . $this->shell->mask((string) json_encode($request['data'])),
            );
        }

        /** @var array{iid: int} $data */
        $data = $request['data'];
        $this->shell->log("Merge request !{$data['iid']} created.");
        $this->mergeWhenGreen($data['iid']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function opened(string $query = ''): array
    {
        $result = $this->api->call(
            'GET',
            '/projects/' . getenv('CI_PROJECT_ID') . '/merge_requests?state=opened&per_page=100' . $query,
        );

        return Json::rows($result['data']);
    }

    /**
     * The assignee and the reviewer (the user of the token), the labels and the milestone of the request.
     *
     * @return array<string, mixed>
     */
    private function options(): array
    {
        $user       = $this->api->call('GET', '/user');
        $milestones = $this->api->call('GET', '/projects/' . getenv('CI_PROJECT_ID') . '/milestones?state=active');
        $list       = Json::rows($milestones['data']);
        $milestone  = null;

        foreach ($list as $item) {
            if (Json::text($item, 'title') === 'Maintenance') {
                $milestone = $item;
            }
        }

        $milestone ??= $list[0] ?? null;

        $account = Json::int(Json::map($user['data']), 'id');

        return [
            'assignee_id'  => $account,
            'reviewer_ids' => [
                $account,
            ],
            'labels'       => 'Kind::Chore',
            ...(null !== $milestone ? [
                'milestone_id' => Json::int($milestone, 'id'),
            ] : []),
        ];
    }
}
