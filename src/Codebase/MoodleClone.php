<?php

namespace MoodleAnalysis\Codebase;

use Composer\Semver\Comparator;
use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use Exception;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

readonly class MoodleClone
{
    public function __construct(private string $path)
    {
        assert(self::isClone($this->path), "The path provided is not a Moodle clone");
    }

    public static function isClone(string $path): bool
    {
        // First is full clone, second is bare.
        return self::isStandardClone($path) || self::isBareClone($path);
    }

    /**
     * @param string $path
     * @return bool
     */
    public static function isStandardClone(string $path): bool
    {
        return is_dir($path) && is_dir($path . '/.git') && file_exists($path . '/lib/components.json');
    }

    /**
     * @param string $path
     * @return bool
     */
    public static function isBareClone(string $path): bool
    {
        return is_dir($path . '/refs');
    }

    /**
     * @param string|null $from the earliest tag we are interested in
     * @param bool $stableOnly whether to include stable tags only
     * @return array<string>
     */
    public function getTags(?string $from = null, bool $stableOnly = false): array
    {
        $tagsProcess = new Process(['git', 'tag', '-l']);
        $tagsProcess->setWorkingDirectory($this->path)->mustRun();
        $allTags = explode("\n", trim($tagsProcess->getOutput()));
        $result = $allTags;

        if ($from !== null) {
            $result = array_filter($result, fn($tag): bool => Comparator::greaterThanOrEqualTo($tag, $from));
        }

        if ($stableOnly) {
            $result = array_filter($result, fn($tag): bool => VersionParser::parseStability($tag) === 'stable');
        }

        // Sort for much faster sequential checkout
        return Semver::sort($result);
    }

    public function checkout(string $ref): void
    {
        if (self::isBareClone($this->path)) {
            throw new Exception("Cannot checkout in a bare clone");
        }
        $process = new Process(['git', 'checkout', $ref]);
        $process->setWorkingDirectory($this->path)->mustRun();
    }

    public function clean(): void
    {
        if (self::isBareClone($this->path)) {
            throw new Exception("Cannot clean a bare clone");
        }

        (new Process(['git', 'reset', '--hard']))->setWorkingDirectory($this->path)->mustRun();
        (new Process(['git', 'clean', '-fdx']))->setWorkingDirectory($this->path)->mustRun();
    }

    public function delete(): void
    {
        $fs = new Filesystem();
        $fs->remove($this->path);
    }

    public function getPath(): string
    {
        return $this->path;
    }
}