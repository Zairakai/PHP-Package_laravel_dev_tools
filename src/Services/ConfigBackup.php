<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Services;

/**
 * Keeps a copy of a file before a forced publication writes over it.
 *
 * The copies of a run go in `.dev-tools-backup/<UTC date and time>/`, under their path from the root of the project (the
 * same folder and layout as `backup_file` of the shell scripts). The folder is created by the first copy.
 */
final class ConfigBackup
{
    public const string FOLDER = '.dev-tools-backup';

    private ?string $directory = null;

    public function __construct(
        private readonly string $root,
        private readonly string $timestamp = '',
    ) {}

    /**
     * The folder of the copies of this run, or null when nothing was saved.
     */
    public function directoryUsed(): ?string
    {
        return $this->directory;
    }

    /**
     * Copies the file, if it exists. A symbolic link is not copied.
     *
     * @return bool true when a copy was made
     */
    public function save(string $file): bool
    {
        if (! is_file($file) || is_link($file)) {
            return false;
        }

        $relative = ltrim(substr($file, strlen(rtrim($this->root, '/'))), '/');
        $target   = $this->directory() . '/' . $relative;

        if (! is_dir(dirname($target))) {
            mkdir(
                dirname($target),
                0755,
                true,
            );
        }

        $this->ignoreTheFolder();

        return copy($file, $target);
    }

    private function directory(): string
    {
        if (null === $this->directory) {
            $stamp           = '' === $this->timestamp
                ? gmdate('Ymd-His')
                : $this->timestamp;
            $this->directory = rtrim($this->root, '/') . '/' . self::FOLDER . '/' . $stamp;
        }

        return $this->directory;
    }

    /**
     * The folder ignores itself for git (a .gitignore with `*` in it): the copies are never committed by mistake, also
     * when the .gitignore of the project does not name the folder.
     */
    private function ignoreTheFolder(): void
    {
        $ignore = rtrim($this->root, '/') . '/' . self::FOLDER . '/.gitignore';

        if (! is_file($ignore)) {
            file_put_contents($ignore, "*\n");
        }
    }
}
