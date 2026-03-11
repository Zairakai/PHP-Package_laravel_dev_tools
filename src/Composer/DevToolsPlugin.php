<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Composer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Zairakai\LaravelDevTools\Services\GitlabCiSynchronizer;

/**
 * Composer Plugin that automatically configures laravel-dev-tools after install/update.
 *
 * Responsibilities:
 *   - Ensure the plugin itself is declared in config.allow-plugins
 *   - Ensure the setup-dev-tools script exists in composer.json scripts
 *   - Ensure @setup-dev-tools is registered in post-update-cmd
 *
 * Only runs in dev mode (composer install/update without --no-dev).
 */
/**
 * @codeCoverageIgnore Composer plugin — executes in Composer process, not testable via PHPUnit.
 */
final class DevToolsPlugin implements EventSubscriberInterface, PluginInterface
{
    private const string PLUGIN_NAME = 'zairakai/laravel-dev-tools';

    /**
     * Script event name for post-update — used to decide warn vs auto-fix.
     */
    private const string POST_UPDATE_EVENT = 'post-update-cmd';

    private const string POST_UPDATE_SCRIPT = '@setup-dev-tools';

    /**
     * Scripts that must exist in the project composer.json.
     *
     * @var array<string, string>
     */
    private const array REQUIRED_SCRIPTS = [
        'setup-dev-tools' => 'bash vendor/zairakai/laravel-dev-tools/scripts/setup-package.sh',
    ];

    private ?Composer $composer = null;

    private ?IOInterface $io = null;

    /**
     * {@inheritDoc}
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => ['onPostInstallOrUpdate', 10],
            ScriptEvents::POST_UPDATE_CMD  => ['onPostInstallOrUpdate', 10],
        ];
    }

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io       = $io;
    }

    /**
     * Required by PluginInterface - nothing to deactivate.
     */
    public function deactivate(Composer $composer, IOInterface $io): void
    {
        // Nothing to do
    }

    public function onPostInstallOrUpdate(Event $event): void
    {
        if (
            ! $event->isDevMode()
            || ! $this->composer instanceof Composer
            || ! $this->io instanceof IOInterface
        ) {
            return;
        }

        $this->ensureScriptsConfigured();
        $this->synchronizeGitlabCi($event);
    }

    /**
     * Required by PluginInterface - nothing to uninstall.
     */
    public function uninstall(Composer $composer, IOInterface $io): void
    {
        // Nothing to do
    }

    // =========================================================================
    // Private — composer.json mutation helpers
    // =========================================================================

    /**
     * Ensure the plugin is declared in config.allow-plugins.
     *
     * @param array<string, mixed> $json
     */
    private function ensurePluginAllowed(array &$json): bool
    {
        /** @var array<string, mixed> $config */
        $config       = $json['config'] ?? [];

        /** @var array<string, bool> $allowPlugins */
        $allowPlugins = $config['allow-plugins'] ?? [];

        if (
            ! isset($allowPlugins[self::PLUGIN_NAME])
            || ! $allowPlugins[self::PLUGIN_NAME]
        ) {
            $allowPlugins[self::PLUGIN_NAME] = true;
            $config['allow-plugins']         = $allowPlugins;
            $json['config']                  = $config;

            $this->io?->write('<info>dev-tools:</info> Added plugin to <comment>allow-plugins</comment> config');

            return true;
        }

        return false;
    }

    /**
     * Ensure @setup-dev-tools is registered in post-update-cmd.
     *
     * @param array<string, mixed> $json
     */
    private function ensurePostUpdateHook(array &$json): bool
    {
        /** @var array<string, mixed> $scripts */
        $scripts = $json['scripts'] ?? [];

        /** @var array<string>|string $current */
        $current = $scripts['post-update-cmd'] ?? [];

        if (is_string($current)) {
            $current = [$current];
        }

        if (in_array(self::POST_UPDATE_SCRIPT, $current, true)) {
            return false;
        }

        $current[]                  = self::POST_UPDATE_SCRIPT;
        $scripts['post-update-cmd'] = $current;
        $json['scripts']            = $scripts;

        $this->io?->write('<info>dev-tools:</info> Added <comment>@setup-dev-tools</comment> to post-update-cmd');

        return true;
    }

    /**
     * Ensure all required scripts exist in composer.json.
     *
     * Existing scripts are never overwritten to preserve custom values.
     *
     * @param array<string, mixed>               $json
     * @param array<string, string|list<string>> $scriptsToEnsure
     */
    private function ensureScripts(array &$json, array $scriptsToEnsure): bool
    {
        $modified = false;

        /** @var array<string, mixed> $scripts */
        $scripts  = $json['scripts'] ?? [];

        foreach ($scriptsToEnsure as $name => $command) {
            if (isset($scripts[$name])) {
                continue;
            }

            $scripts[$name] = $command;
            $modified       = true;

            $this->io?->write(sprintf(
                '<info>laravel-dev-tools:</info> Added script <comment>%s</comment>',
                $name,
            ));
        }

        if ($modified) {
            $json['scripts'] = $scripts;
        }

        return $modified;
    }

    /**
     * Read the project composer.json, apply all required mutations, and persist if changed.
     */
    private function ensureScriptsConfigured(): void
    {
        $path = $this->getComposerJsonPath();

        if (null === $path || ! file_exists($path)) {
            return;
        }

        $json = $this->readComposerJson($path);

        if (null === $json) {
            return;
        }

        $modified  = false;
        $modified |= (int) $this->ensurePluginAllowed($json);
        $modified |= (int) $this->ensureScripts($json, self::REQUIRED_SCRIPTS);
        $modified |= (int) $this->ensurePostUpdateHook($json);

        if (0 !== $modified) {
            $this->writeComposerJson($path, $json);
        }
    }

    /**
     * Resolve the absolute path to the project composer.json from Composer's vendor-dir.
     *
     * @return string|null Null when Composer instance is unavailable or vendor-dir is unresolvable
     */
    private function getComposerJsonPath(): ?string
    {
        $vendorDir = $this->composer?->getConfig()->get('vendor-dir');

        if (! is_string($vendorDir)) {
            return null;
        }

        return dirname($vendorDir) . '/composer.json';
    }

    /**
     * Read and decode composer.json.
     *
     * @return array<string, mixed>|null
     */
    private function readComposerJson(string $path): ?array
    {
        $content = file_get_contents($path);

        if (false === $content) {
            return null;
        }

        /** @var array<string, mixed>|null $json */
        $json = json_decode($content, true);

        if (! is_array($json)) {
            return null;
        }

        return $json;
    }

    // =========================================================================
    // Private — GitLab CI synchronization
    // =========================================================================

    /**
     * Delegate GitLab CI ref synchronization to GitlabCiSynchronizer.
     *
     * post-install-cmd → warn only (never modify files without explicit intent)
     * post-update-cmd  → auto-fix (user explicitly asked to update the package)
     */
    private function synchronizeGitlabCi(Event $event): void
    {
        $vendorDir = $this->composer?->getConfig()->get('vendor-dir');

        if (! is_string($vendorDir)) {
            return;
        }

        $projectRoot = dirname($vendorDir);

        /** @var IOInterface $io */
        $io = $this->io;

        $gitlabCiSynchronizer = new GitlabCiSynchronizer(
            io: $io,
            projectRoot: $projectRoot,
        );

        $autoFix = $event->getName() === self::POST_UPDATE_EVENT;

        $gitlabCiSynchronizer->synchronize($autoFix);
    }

    /**
     * Encode and write the mutated composer.json back to disk.
     *
     * Uses JSON_PRETTY_PRINT which outputs 4-space indentation (PHP 7.1+).
     * No post-processing is applied — the output is stable and deterministic.
     *
     * @param array<string, mixed> $json
     */
    private function writeComposerJson(string $path, array $json): void
    {
        $content = json_encode(
            $json,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        if (false === $content) {
            // @codeCoverageIgnoreStart
            $this->io?->writeError('<error>dev-tools: Failed to encode composer.json</error>');

            return;
            // @codeCoverageIgnoreEnd
        }

        file_put_contents($path, $content . "\n");

        $this->io?->write('<info>laravel-dev-tools:</info> Updated composer.json');
    }
}
