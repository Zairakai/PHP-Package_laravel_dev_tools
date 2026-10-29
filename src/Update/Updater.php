<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Update;

use Illuminate\Support\Sleep;
use RuntimeException;
use Throwable;

/**
 * Automatic updates of the requirements of a PHP package of the zairakai group, in GitLab CI.
 *
 * - update: update Composer, open a merge request into develop and merge it when its pipeline succeeds.
 * - release: open the merge request of develop into main after an update.
 * - tag: tag main when a published range changed since the last tag.
 * - cascade: after the publication of this package, start the update of the projects that use it.
 * - audit: look for security advisories, open one issue per advisory and, with AUTO_FIX=true, start an update to fix them.
 * - policy: in the repository dependency-policy, lift the entries of php.json whose condition is met (merge request).
 * - policy-check: fail when the change since a ref updates a package of the dependency policy (GitLab or GitHub).
 * - dependabot-sync: rewrite the ignore rules of .github/dependabot.yml (between the markers) from the dependency policy.
 *
 * It reads `.dependency-update.json` at the root of the project, like the JavaScript tool of the group.
 */
final class Updater
{
    private const string BRANCH_PREFIX = 'chore/update-dependencies';

    private const string COMMIT_SUBJECT = 'chore(deps): update the dependencies';

    private const string CONFIG_FILE = '.dependency-update.json';

    private const string DEPENDABOT_FILE = '.github/dependabot.yml';

    /**
     * The files an update may change.
     */
    private const array FILES = ['composer.json', 'composer.lock'];

    private const string ISSUE_LABELS = 'Kind::Security,Area::Security,Automated';

    private const string PIPELINE_FILE = '.gitlab-ci.yml';

    private const string POLICY_BRANCH_PREFIX = 'chore/unblock';

    private const string POLICY_FILE = 'php.json';

    private const string POLICY_URL = 'https://gitlab.com/zairakai/dependency-policy/-/raw/main';

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

    public function run(string $mode, string $argument = ''): void
    {
        // The check only reads: it runs anywhere, GitHub included.
        if ('policy-check' === $mode) {
            $this->policyCheck($argument);

            return;
        }

        if ('dependabot-sync' === $mode) {
            $this->dependabotSync();

            return;
        }

        $this->assertEnvironment();

        match ($mode) {
            'update'  => $this->update(),
            'release' => $this->release(),
            'tag'     => $this->tag(),
            'cascade' => $this->cascade(),
            'audit'   => $this->audit(),
            'policy'  => $this->policyWatch(),
            default   => throw new RuntimeException('Usage: update-dependencies update | release | tag | cascade | audit | policy | policy-check <ref> | dependabot-sync'),
        };
    }

    /**
     * @param non-empty-string          $method
     * @param array<string, mixed>|null $body
     * @param string|null               $token  The token of the call, GITLAB_TOKEN when not given.
     *
     * @return array{status: int, ok: bool, data: mixed}
     */
    private function api(string $method, string $path, ?array $body = null, ?string $token = null): array
    {
        $handle  = curl_init((getenv('CI_API_V4_URL') ?: 'https://gitlab.com/api/v4') . $path);
        curl_setopt($handle, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_HTTPHEADER, ['PRIVATE-TOKEN: ' . ($token ?? getenv('GITLAB_TOKEN')), 'Content-Type: application/json']);
        curl_setopt($handle, CURLOPT_TIMEOUT, 60);

        if (null !== $body) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
        }

        $text   = (string) curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
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

    /**
     * Look for security advisories in the lock file. One issue per advisory, never twice, closed when the advisory is gone.
     * With AUTO_FIX=true, an advisory that is high, critical or of unknown severity starts an update of the dependencies.
     */
    private function audit(): void
    {
        $report = json_decode($this->exec(['composer', 'audit', '--locked', '--format=json', '--no-interaction'], null, [0, 1, 2, 3]), true, 512, JSON_THROW_ON_ERROR);
        $found  = Planner::advisories(Json::map($report));
        $auto   = 'true' === getenv('AUTO_FIX');
        $token  = (string) (getenv('FRESHNESS_TOKEN') ?: getenv('GITLAB_TOKEN'));
        $titles = [];
        $opened = [];
        $urgent = false;
        $lock   = is_file($this->directory . '/composer.lock') ? Json::map(json_decode((string) file_get_contents($this->directory . '/composer.lock'), true)) : [];

        $this->log(count($found) . ' security advisory(ies) found.');

        foreach ($found as $advisory) {
            $title    = Planner::advisoryTitle($advisory);
            $titles[] = $title;
            $urgent   = $urgent || Planner::isUrgent($advisory['severity']);

            if ($this->issueExists($title, $token)) {
                $this->log("Issue already open: {$title}");

                continue;
            }

            // Inherited from a package of the group: the issue is in the package that brings it, and the update of this project takes the fix when there is one.
            if (Planner::isInherited($lock, $this->manifest(), $advisory['package'])) {
                $this->log("Inherited from the group, followed in the package that brings it: {$title}");

                continue;
            }

            $url = $this->createIssue($title, Planner::advisoryIssueBody($advisory, (string) getenv('CI_PIPELINE_URL'), $auto), Planner::advisoryLabels($advisory, self::ISSUE_LABELS), $token);
            $this->log("Issue created: {$url}");
            $opened[] = ['advisory' => $advisory, 'url' => $url];
        }

        // One message per audit, whatever the number of new advisories.
        $alert = Planner::auditAlert($opened, (string) getenv('CI_PROJECT_PATH'));

        if (null !== $alert) {
            $this->notify($alert['title'], $alert['message']);
        }

        $this->closeResolved($titles, $token);

        if (! $auto || ! $urgent) {
            $this->log($auto ? 'Nothing urgent: the weekly update takes the rest.' : 'AUTO_FIX is off: update by hand.');

            return;
        }

        if ($this->hasOpenUpdate()) {
            $this->log('An update is already open: nothing to start.');

            return;
        }

        $started = $this->api('POST', '/projects/' . getenv('CI_PROJECT_ID') . '/pipeline', ['ref' => 'develop', 'variables' => [['key' => 'UPDATE_DEPENDENCIES', 'value' => 'true']]], $token);
        $this->log($started['ok'] ? 'Update started.' : "The update could not be started ({$started['status']}).");
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
                Sleep::sleep(30);
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
     * Close the issues whose advisory is gone from the merged tree. It never stops a release.
     */
    private function closeObsolete(): void
    {
        try {
            $report = json_decode($this->exec(['composer', 'audit', '--locked', '--format=json', '--no-interaction'], null, [0, 1, 2, 3]), true, 512, JSON_THROW_ON_ERROR);
            $titles = array_map(Planner::advisoryTitle(...), Planner::advisories(Json::map($report)));

            $this->closeResolved($titles, (string) (getenv('FRESHNESS_TOKEN') ?: getenv('GITLAB_TOKEN')));
        }
        catch (Throwable $throwable) {
            $this->log('The obsolete issues could not be checked: ' . strtok($this->mask($throwable->getMessage()), "\n"));
        }
    }

    /**
     * Close the open issues of the audit whose advisory is not found any more.
     *
     * @param list<string> $titles The titles of the advisories found today.
     */
    private function closeResolved(array $titles, string $token): void
    {
        $query  = http_build_query(['state' => 'opened', 'labels' => 'Automated', 'search' => Planner::ADVISORY_TITLE_PREFIX, 'in' => 'title', 'per_page' => 100]);
        $issues = $this->api('GET', '/projects/' . getenv('CI_PROJECT_ID') . "/issues?{$query}", null, $token);

        foreach (Json::rows($issues['data']) as $issue) {
            $title = Json::text($issue, 'title');

            if (! str_starts_with($title, Planner::ADVISORY_TITLE_PREFIX) || in_array($title, $titles, true)) {
                continue;
            }

            $path = '/projects/' . getenv('CI_PROJECT_ID') . '/issues/' . Json::int($issue, 'iid');
            $this->api('POST', "{$path}/notes", ['body' => 'The audit of ' . getenv('CI_PIPELINE_URL') . ' no longer finds this advisory.'], $token);
            $closed = $this->api('PUT', $path, ['state_event' => 'close'], $token);
            $this->log($closed['ok'] ? "Issue closed: {$title}" : "Issue not closed ({$closed['status']}): {$title}");
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

    private function createIssue(string $title, string $description, string $labels, string $token): string
    {
        // The assignee is given by the variable ISSUE_ASSIGNEE_ID; without it, the user of the token.
        $assignee = (int) getenv('ISSUE_ASSIGNEE_ID');

        if (0 === $assignee) {
            $assignee = Json::int(Json::map($this->api('GET', '/user', null, $token)['data']), 'id');
        }

        $issue = $this->api('POST', '/projects/' . getenv('CI_PROJECT_ID') . '/issues', [
            'title'        => $title,
            'description'  => $description,
            'labels'       => $labels,
            'assignee_ids' => 0 < $assignee ? [$assignee] : [],
        ], $token);

        if (! $issue['ok']) {
            throw new RuntimeException('The issue could not be created: ' . $this->mask((string) json_encode($issue['data'])));
        }

        return Json::text(Json::map($issue['data']), 'web_url');
    }

    /**
     * Rewrite the ignore rules of .github/dependabot.yml from the policy. The workflow of the repository commits the result.
     */
    private function dependabotSync(): void
    {
        $file = $this->directory . '/' . self::DEPENDABOT_FILE;

        if (! is_file($file)) {
            throw new RuntimeException(self::DEPENDABOT_FILE . ' does not exist.');
        }

        $names  = Planner::policyPackages($this->policy());
        $text   = (string) file_get_contents($file);
        $synced = Planner::syncDependabot($text, $names, 'composer');

        if (null === $synced) {
            throw new RuntimeException(self::DEPENDABOT_FILE . ' has no block "# BEGIN dependency-policy (composer)" ... "# END dependency-policy (composer)".');
        }

        if ($synced === $text) {
            $this->log('The ignore rules of Dependabot are in sync with the policy.');

            return;
        }

        file_put_contents($file, $synced);
        $this->log('The ignore rules of Dependabot are updated: ' . (implode(', ', $names) ?: 'none') . '.');
    }

    /**
     * @param list<string> $command
     * @param list<int>    $allowed The exit statuses that are not a failure.
     */
    private function exec(array $command, ?string $input = null, array $allowed = [0]): string
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

        if (! in_array(proc_close($process), $allowed, true)) {
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

    private function issueExists(string $title, string $token): bool
    {
        $query  = http_build_query(['state' => 'opened', 'search' => $title, 'in' => 'title']);
        $issues = $this->api('GET', '/projects/' . getenv('CI_PROJECT_ID') . "/issues?{$query}", null, $token);

        foreach (Json::rows($issues['data']) as $issue) {
            if (Json::text($issue, 'title') === $title) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    private function lockVersions(): array
    {
        $file = $this->directory . '/composer.lock';

        return is_file($file) ? Planner::lockVersions(Json::map(json_decode((string) file_get_contents($file), true))) : [];
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
        foreach (['GITLAB_TOKEN', 'FRESHNESS_TOKEN'] as $name) {
            $token = (string) getenv($name);
            $text  = '' === $token ? $text : str_replace($token, '***', $text);
        }

        return $text;
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

            Sleep::sleep(10);
        }

        $this->log("Merge request !{$iid}: it could not be set to merge by itself, it stays open.");
    }

    /**
     * A push notification through ntfy, when NTFY_URL is set. A failure never stops the audit.
     */
    private function notify(string $title, string $message): void
    {
        $url = (string) getenv('NTFY_URL');

        if ('' === $url) {
            return;
        }

        $context = stream_context_create(['http' => ['method' => 'POST', 'header' => "Title: {$title}\r\nContent-Type: text/plain", 'content' => $message, 'timeout' => 15, 'ignore_errors' => true]]);
        @file_get_contents($url, false, $context);
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

    private function openMergeRequest(string $source, string $target, string $description, bool $removeSource, string $title = self::COMMIT_SUBJECT): void
    {
        $request = $this->api('POST', '/projects/' . getenv('CI_PROJECT_ID') . '/merge_requests', [
            'source_branch'        => $source,
            'target_branch'        => $target,
            'title'                => $title,
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

    /**
     * The releases of a package on Packagist (metadata p2, as listed), or an empty list.
     *
     * @return list<array<string, mixed>>
     */
    private function packagistReleases(string $name): array
    {
        $json = @file_get_contents("https://repo.packagist.org/p2/{$name}.json", false, stream_context_create(['http' => ['timeout' => 20]]));

        if (false === $json) {
            return [];
        }

        $data = Json::map(json_decode($json, true));

        return Json::rows(Json::map($data['packages'] ?? null)[$name] ?? []);
    }

    /**
     * The policy of the group (repository zairakai/dependency-policy, php.json). Anything wrong stops the update, with an alert.
     *
     * @return array<string, mixed>
     */
    private function policy(): array
    {
        $base = rtrim((string) (getenv('DEPENDENCY_POLICY_URL') ?: self::POLICY_URL), '/');
        $url  = "{$base}/php.json";

        try {
            $text = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 20]]));

            if (false === $text) {
                throw new RuntimeException('no answer');
            }

            $doc    = Json::map(json_decode($text, true, 512, JSON_THROW_ON_ERROR));
            $errors = Planner::validatePolicy($doc);

            if ([] !== $errors) {
                throw new RuntimeException('not valid: ' . implode(' ', $errors));
            }
        }
        catch (Throwable $throwable) {
            $message = "The dependency policy could not be read ({$url}): " . $throwable->getMessage();
            $this->notify('Dependency policy unreachable', getenv('CI_PROJECT_PATH') . ': ' . $message);

            throw new RuntimeException($message, 0, $throwable);
        }

        $this->log('Dependency policy: ' . (implode(', ', Planner::policyPackages($doc)) ?: 'nothing is held back') . '.');

        return $doc;
    }

    /**
     * Fail when the working tree updates a package of the policy compared with `$base`: a range of composer.json or a locked version.
     */
    private function policyCheck(string $base): void
    {
        if ('' === $base) {
            throw new RuntimeException('Usage: update-dependencies policy-check <ref>');
        }

        $blocked  = Planner::policyPackages($this->policy());
        $before   = Planner::lockVersions(Json::map(json_decode($this->exec(['git', 'show', "{$base}:composer.lock"], null, [0, 128]) ?: '{}', true)));
        $problems = Planner::policyViolations($blocked, $this->manifest($base), $this->manifest(), $before, $this->lockVersions());

        if ([] !== $problems) {
            foreach ($problems as $problem) {
                fwrite(STDERR, $problem . "\n");
            }

            throw new RuntimeException('The change updates a package that the dependency policy holds back.');
        }

        $this->log('No package of the dependency policy is updated.');
    }

    /**
     * In the repository dependency-policy: lift the entries of php.json whose condition is met, with a merge request into main.
     */
    private function policyWatch(): void
    {
        $file = $this->directory . '/' . self::POLICY_FILE;

        if (! is_file($file)) {
            throw new RuntimeException(self::POLICY_FILE . ' is not in the current directory: run this in the repository dependency-policy.');
        }

        $doc    = Json::map(json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR));
        $errors = Planner::validatePolicy($doc);

        if ([] !== $errors) {
            throw new RuntimeException(self::POLICY_FILE . ' is not valid: ' . implode(' ', $errors));
        }

        $lifted = [];

        foreach (Json::rows($doc['entries'] ?? []) as $entry) {
            $peers  = Json::strings(Json::map($entry['unblock'] ?? null)['peers'] ?? null);
            $ranges = [];

            foreach ($peers as $peer) {
                $ranges[$peer] = Planner::peerRange($this->packagistReleases($peer), Json::text($entry, 'package'));
            }

            if (Planner::isLifted($entry, $ranges)) {
                $lifted[] = $entry;
            }
        }

        if ([] === $lifted) {
            $this->log('No entry can be lifted.');

            return;
        }

        if ([] !== array_filter($this->openMergeRequests(), static fn (array $request): bool => str_starts_with(Json::text($request, 'source_branch'), self::POLICY_BRANCH_PREFIX))) {
            $this->log('A merge request that lifts an entry is already open.');

            return;
        }

        $names   = array_map(static fn (array $entry): string => Json::text($entry, 'package'), $lifted);
        $list    = implode(', ', $names);
        $branch  = self::POLICY_BRANCH_PREFIX . '-' . date('Ymd');
        $subject = "chore(policy): unblock {$list}";
        $details = implode('; ', array_map(static fn (array $entry): string => Json::text($entry, 'package') . ' ' . Json::text(Json::map($entry['unblock'] ?? null), 'version') . ' (' . implode(', ', Json::strings(Json::map($entry['unblock'] ?? null)['peers'] ?? null)) . ')', $lifted));

        $this->configureSigning();
        $this->exec(['git', 'switch', '-C', $branch]);
        file_put_contents($file, Planner::encodePolicy(Planner::withoutEntries($doc, $names)));
        $this->exec(['git', 'add', self::POLICY_FILE]);
        $this->exec(['git', 'commit', '-m', $subject, '-m', 'The ranges of the packages that held it back now accept it.']);
        $this->exec(['git', 'push', $this->remoteUrl(), "HEAD:refs/heads/{$branch}"]);

        $this->openMergeRequest($branch, 'main', "The condition of the entry is met: {$details}. It is merged when its pipeline succeeds, and the update tools take the package at their next run.", true, $subject);
        $this->notify('Dependency policy: block lifted', "{$list}: {$details}");
    }

    private function release(): void
    {
        // The update that was just merged into develop may fix advisories: their issues have no reason to stay open.
        $this->closeObsolete();

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
            // git reads a basic regular expression: the parentheses of the subject are literal.
            $subject = self::COMMIT_SUBJECT;
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

        $blocked = Planner::policyPackages($this->policy());
        $locked  = $this->lockVersions();
        $held    = array_values(array_intersect($blocked, array_keys($locked)));
        $all     = array_values(array_diff(array_keys($locked), $held));

        // A package of the policy keeps its locked version, also when another package requires it: the others are updated by name, with their dependencies.
        $this->exec(['composer', 'update', '-W', '--no-interaction', '--no-progress', ...Planner::heldConstraints($held, $locked), ...([] === $held ? [] : $all)]);

        foreach ($held as $name) {
            if (($this->lockVersions()[$name] ?? '') !== $locked[$name]) {
                throw new RuntimeException("{$name} is held back by the dependency policy but the update changed it: nothing is merged.");
            }
        }

        $this->exec(['composer', 'bump', '--no-interaction']);

        // The normalization is optional: not every project has the plugin.
        try {
            $this->exec(['composer', 'normalize', '--no-interaction']);
        }
        catch (RuntimeException) {
            $this->log('composer normalize is not available: skipped.');
        }

        $rows  = Planner::diffDependencies($before, $this->manifest());
        $files = self::FILES;

        // The one exception: the ref of the shared pipeline follows the installed version of the tools.
        if (is_file($this->directory . '/' . self::PIPELINE_FILE) && Planner::isRefOnlyChange($this->exec(['git', 'diff', '-U0', '--', self::PIPELINE_FILE]))) {
            $files[] = self::PIPELINE_FILE;
        }

        if ('' === $this->exec(['git', 'status', '--porcelain', '--', ...$files])) {
            $this->log('Everything is up to date.');

            return;
        }

        $table = Planner::formatChanges($rows);
        // Only the manifest and the lock file: the plugin of laravel-dev-tools republishes other files during composer update, and the cache of Composer on the runner is not ignored by git.
        $this->exec(['git', 'add', ...$files]);
        $this->exec(['git', 'commit', '-m', self::COMMIT_SUBJECT, '-m', "Minor and patch updates.\n\n{$table}"]);
        $this->exec(['git', 'push', $this->remoteUrl(), "HEAD:refs/heads/{$branch}"]);

        $this->openMergeRequest($branch, 'develop', "Automatic update. The pipeline of this merge request is the gate: it is merged when it succeeds, and stays open otherwise. Major versions are never applied here.\n\n{$table}", true);
    }
}
