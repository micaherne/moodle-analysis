<?php

namespace MoodleAnalysis\Codebase;

use Composer\Semver\Comparator;
use Composer\Semver\VersionParser;
use Exception;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class MoodleClone
{
    public function __construct(private readonly string $path)
    {
        assert(self::isClone($this->path), "The path provided is not a Moodle clone");
    }

    public static function isClone(string $path): bool {
        // First is full clone, second is bare.
        return (is_dir($path) && is_dir($path . '/.git') && file_exists($path . '/lib/components.json'))
                || is_dir($path . '/refs');
    }

    /**
     * @param string|null $from the earliest tag we are interested in
     * @param bool $stableOnly whether to include stable tags only
     * @return array<string>
     */
    public function getTags(string $from = null, bool $stableOnly = false): array
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

        return $result;
    }

    public function checkout(string $ref): void {
        $process = new Process(['git', 'checkout', $ref]);
        $process->setWorkingDirectory($this->path)->mustRun();
    }

    public function clean(): void {
        (new Process(['git', 'reset', '--hard']))->setWorkingDirectory($this->path)->mustRun();
        (new Process(['git', 'clean', '-fdx']))->setWorkingDirectory($this->path)->mustRun();
    }

    public function delete(): void {
        $fs = new Filesystem();
        $fs->remove($this->path);
    }

    public function getPath(): string
    {
        return $this->path;
    }
}