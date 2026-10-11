<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Update;

use RuntimeException;

/**
 * Automatic updates of the requirements of a PHP package of the zairakai group, in GitLab CI.
 *
 * - update: update Composer, open a merge request into develop and merge it when its pipeline succeeds.
 * - release: open the merge request of develop into main after an update.
 * - tag: tag main when a published range changed since the last tag.
 * - cascade: after the publication of this package, start the update of the projects that use it.
 *
 * It reads `.dependency-update.json` at the root of the project, like the JavaScript tool of the group.
 */
final class Updater
{
    private const BRANCH_PREFIX = 'chore/update-dependencies';

    private const COMMIT_SUBJECT = 'chore(deps): update the dependencies';

    private const CONFIG_FILE = '.dependency-update.json';

    /**
     * @var array{reject: list<string>, cooldown: string, tag: array{when: string, level: string}, cascade: ?array{groups?: list<string>}}
     */
    private array $config;

    public function __construct(private readonly string $directory)
    {
        $user = [];

        if (is_file($this->directory . '/' . self::CONFIG_FILE)) {
            /** @var array<string, mixed> $user */
            $user = json_decode((string) file_get_contents($this->directory . '/' . self::CONFIG_FILE), true, 512, JSON_THROW_ON_ERROR);
        }

        $this->config = Planner::config($user);

        // Composer has no cooling period or exclusion: refuse to ignore a setting silently.
        if ([] !== $this->config['reject']) {
            throw new RuntimeException('"reject" is not supported for a Composer project: remove it from ' . self::CONFIG_FILE . '.');
        }
    }

    public function run(string $mode): void
    {
        $this->assertEnvironment();

        match ($mode) {
            'update'  => $this->update(),
            'release' => $this->release(),
            'tag'     => $this->tag(),
            'cascade' => $this->cascade(),
            default   => throw new RuntimeException('Usage: update-dependencies update | release | tag | cascade'),
        };
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array{status: int, ok: bool, data: mixed}
     */
    private function api(string $method, string $path, ?array $body = null): array
    {
        $handle = curl_init((getenv('CI_API_V4_URL') ?: 'https://gitlab.com/api/v4') . $path);
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['PRIVATE-TOKEN: ' . getenv('GITLAB_TOKEN'), 'Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 60,
            ...(null === $body ? [] : [CURLOPT_POSTFIELDS => json_encode($body)]),
        ]);
        $text   = (string) curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return ['status' => $status, 'ok' => 200 <= $status && 300 > $status, 'data' => json_decode($text, true) ?? $text];
    }

    private function assertEnvironment(): void
    {
        foreach (['GITLAB_TOKEN', 'CI_PROJECT_ID', 'CI_PROJECT_PATH', 'CI_SERVER_HOST'] as $name) {
            if (false === getenv($name) || '' === getenv($name)) {
                throw new RuntimeException("{$name} is not set: run this in GitLab CI, with the group variable GITLAB_TOKEN.");
            }
        }

        // The runner clones the project with another user than the one of the job: Git refuses it otherwise.
        $this->exec(['git', 'config', '--global', '--add', 'safe.directory', $this->directory]);
    }

    private function cascade(): void
    {
        if (null === $this->config['cascade']) {
            $this->log('No cascade configured.');

            return;
        }

        $name    = Json::text($this->manifest(), 'name');
        $version = (string) getenv('CI_COMMIT_TAG');

        if ('' === $version) {
            throw new RuntimeException('The cascade runs on the pipeline of a tag.');
        }

        // Packagist needs a few minutes to list a new version: wait for it instead of waiting for a fixed hour.
        $available = false;

        for ($attempt = 0; 60 > $attempt && ! $available; $attempt++) {
            $available = $this->onPackagist($name, $version);

            if (! $available) {
                sleep(30);
            }
        }

        if (! $available) {
            throw new RuntimeException("{$name}@{$version} is not on Packagist after 30 minutes.");
        }

        foreach ($this->config['cascade']['groups'] ?? [] as $group) {
            $projects = $this->api('GET', '/groups/' . rawurlencode($group) . '/projects?include_subgroups=true&archived=false&per_page=100');

            foreach (Json::rows($projects['data']) as $project) {
                $id   = Json::text($project, 'id');
                $path = Json::text($project, 'path_with_namespace');

                if ((string) getenv('CI_PROJECT_ID') === $id) {
                    continue;
                }

                // Opt-in: a project is updated only if it has the same file.
                $file = $this->api('GET', "/projects/{$id}/repository/files/" . rawurlencode(self::CONFIG_FILE) . '?ref=develop');

                if (! $file['ok']) {
                    $this->log("{$path}: no " . self::CONFIG_FILE . ', skipped.');

                    continue;
                }

                $started = $this->api('POST', "/projects/{$id}/pipeline", ['ref' => 'develop', 'variables' => [['key' => 'UPDATE_DEPENDENCIES', 'value' => 'true']]]);
                $this->log("{$path}: " . ($started['ok'] ? 'update started' : "not started ({$started['status']})") . '.');
            }
        }
    }

    /**
     * Import the signing key and make Git sign the commits and the tags with it.
     */
    private function configureSigning(): void
    {
        $signer = Planner::signerIdentity(getenv());

        $this->exec(['gpg', '--batch', '--import'], $signer['key']);
        $fingerprint = '';

        foreach (explode("\n", $this->exec(['gpg', '--list-secret-keys', '--with-colons'])) as $line) {
            if (str_starts_with($line, 'fpr')) {
                $fingerprint = explode(':', $line)[9];

                break;
            }
        }

        $this->exec(['git', 'config', 'user.name', $signer['name']]);
        $this->exec(['git', 'config', 'user.email', $signer['email']]);
        $this->exec(['git', 'config', 'user.signingkey', $fingerprint]);
        $this->exec(['git', 'config', 'commit.gpgsign', 'true']);
        $this->exec(['git', 'config', 'tag.gpgsign', 'true']);
    }

    /**
     * @param list<string> $command
     */
    private function exec(array $command, ?string $input = null): string
    {
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->directory);

        if (! is_resource($process)) {
            throw new RuntimeException('Cannot run ' . $command[0] . '.');
        }

        fwrite($pipes[0], $input ?? '');
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        $error  = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        if (0 !== proc_close($process)) {
            throw new RuntimeException($this->mask(implode(' ', $command) . ' failed: ' . $error));
        }

        return trim($output);
    }

    private function hasOpenUpdate(): bool
    {
        foreach ($this->openMergeRequests() as $request) {
            if (str_starts_with(Json::text($request, 'source_branch'), self::BRANCH_PREFIX)) {
                return true;
            }
        }

        return false;
    }

    private function log(string $message): void
    {
        fwrite(STDOUT, $message . "\n");
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(?string $ref = null): array
    {
        $json = null === $ref ? (string) file_get_contents($this->directory . '/composer.json') : $this->exec(['git', 'show', "{$ref}:composer.json"]);

        /** @var array<string, mixed> */
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    private function mask(string $text): string
    {
        $token = (string) getenv('GITLAB_TOKEN');

        return '' === $token ? $text : str_replace($token, '***', $text);
    }

    /**
     * @return array<string, mixed>
     */
    private function mergeRequestOptions(): array
    {
        $user       = $this->api('GET', '/user');
        $milestones = $this->api('GET', '/projects/' . getenv('CI_PROJECT_ID') . '/milestones?state=active');
        $list       = Json::rows($milestones['data']);
        $milestone  = null;

        foreach ($list as $item) {
            if ('Maintenance' === Json::text($item, 'title')) {
                $milestone = $item;
            }
        }

        $milestone ??= $list[0] ?? null;

        $account = Json::int(Json::map($user['data']), 'id');

        return [
            'assignee_id'  => $account,
            'reviewer_ids' => [$account],
            'labels'       => 'Kind::Chore',
            ...(null !== $milestone ? ['milestone_id' => Json::int($milestone, 'id')] : []),
        ];
    }

    /**
     * Merge the request when its pipeline succeeds. GitLab needs a moment to create the pipeline of a new request.
     */
    private function mergeWhenGreen(int $iid): void
    {
        for ($attempt = 0; 18 > $attempt; $attempt++) {
            $result = $this->api('PUT', '/projects/' . getenv('CI_PROJECT_ID') . "/merge_requests/{$iid}/merge", ['merge_when_pipeline_succeeds' => true, 'should_remove_source_branch' => true]);

            if ($result['ok']) {
                $this->log("Merge request !{$iid}: it is merged when its pipeline succeeds.");

                return;
            }

            sleep(10);
        }

        $this->log("Merge request !{$iid}: it could not be set to merge by itself, it stays open.");
    }

    private function onPackagist(string $name, string $version): bool
    {
        $json = @file_get_contents("https://repo.packagist.org/p2/{$name}.json");

        if (false === $json) {
            return false;
        }

        /** @var array{packages?: array<string, list<array{version?: string}>>} $data */
        $data = json_decode($json, true) ?? [];

        foreach ($data['packages'][$name] ?? [] as $release) {
            if (($release['version'] ?? null) === $version) {
                return true;
            }
        }

        return false;
    }

    private function openMergeRequest(string $source, string $target, string $description, bool $removeSource): void
    {
        $request = $this->api('POST', '/projects/' . getenv('CI_PROJECT_ID') . '/merge_requests', [
            'source_branch'        => $source,
            'target_branch'        => $target,
            'title'                => self::COMMIT_SUBJECT,
            'description'          => $description,
            'remove_source_branch' => $removeSource,
            ...$this->mergeRequestOptions(),
        ]);

        if (! $request['ok']) {
            throw new RuntimeException('The merge request could not be created: ' . $this->mask((string) json_encode($request['data'])));
        }

        /** @var array{iid: int} $data */
        $data = $request['data'];
        $this->log("Merge request !{$data['iid']} created.");
        $this->mergeWhenGreen($data['iid']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function openMergeRequests(string $query = ''): array
    {
        $result = $this->api('GET', '/projects/' . getenv('CI_PROJECT_ID') . '/merge_requests?state=opened&per_page=100' . $query);

        return Json::rows($result['data']);
    }

    private function release(): void
    {
        $existing = $this->openMergeRequests('&source_branch=develop&target_branch=main');

        if ([] !== $existing) {
            $this->mergeWhenGreen(Json::int($existing[0], 'iid'));

            return;
        }

        $this->openMergeRequest('develop', 'main', 'Brings the automatic update of the dependencies into main. It is merged when its pipeline succeeds.', false);
    }

    private function remoteUrl(): string
    {
        return sprintf('https://oauth2:%s@%s/%s.git', getenv('GITLAB_TOKEN'), getenv('CI_SERVER_HOST'), getenv('CI_PROJECT_PATH'));
    }

    private function tag(): void
    {
        $this->exec(['git', 'fetch', '--tags', '--quiet']);
        $last    = Planner::latestTag(array_values(array_filter(explode("\n", $this->exec(['git', 'tag', '--list'])))));
        $commits = [];

        if (null !== $last) {
            $subject = str_replace(['(', ')'], ['\\(', '\\)'], self::COMMIT_SUBJECT);
            $commits = array_values(array_filter(explode("\n", $this->exec(['git', 'log', "{$last}..HEAD", '--format=%s', "--grep=^{$subject}"]))));
        }

        $before = null !== $last ? $this->manifest($last) : null;
        $plan   = Planner::planTag($this->config, $last, $commits, $before, $this->manifest());

        if (null === $plan['version']) {
            $this->log("No tag: {$plan['reason']}.");

            return;
        }

        $this->configureSigning();
        $this->exec(['git', 'tag', '-s', $plan['version'], '-m', $plan['version'], (string) getenv('CI_COMMIT_SHA')]);
        $this->exec(['git', 'push', $this->remoteUrl(), "refs/tags/{$plan['version']}"]);

        $this->log("Tag {$plan['version']} signed and pushed (last tag {$last}): {$plan['reason']}. Its pipeline publishes the package.");
    }

    private function update(): void
    {
        if ($this->hasOpenUpdate()) {
            $this->log('An update is already open: nothing to do.');

            return;
        }

        $this->configureSigning();
        $before = $this->manifest('HEAD');
        $branch = self::BRANCH_PREFIX . '-' . date('Ymd');
        $this->exec(['git', 'switch', '-C', $branch]);

        $this->exec(['composer', 'update', '-W', '--no-interaction', '--no-progress']);
        $this->exec(['composer', 'bump', '--no-interaction']);

        // The normalization is optional: not every project has the plugin.
        try {
            $this->exec(['composer', 'normalize', '--no-interaction']);
        }
        catch (RuntimeException) {
            $this->log('composer normalize is not available: skipped.');
        }

        $rows = Planner::diffDependencies($before, $this->manifest());

        if ('' === $this->exec(['git', 'status', '--porcelain'])) {
            $this->log('Everything is up to date.');

            return;
        }

        $table = Planner::formatChanges($rows);
        $this->exec(['git', 'add', '-A']);
        $this->exec(['git', 'commit', '-m', self::COMMIT_SUBJECT, '-m', "Minor and patch updates.\n\n{$table}"]);
        $this->exec(['git', 'push', $this->remoteUrl(), "HEAD:refs/heads/{$branch}"]);

        $this->openMergeRequest($branch, 'develop', "Automatic update. The pipeline of this merge request is the gate: it is merged when it succeeds, and stays open otherwise. Major versions are never applied here.\n\n{$table}", true);
    }
}
