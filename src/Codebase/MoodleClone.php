<?php

namespace MoodleAnalysis\Codebase;

use Exception;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class MoodleClone
{


    public function __construct(private readonly string $path)
    {
        assert(is_dir($this->path) && is_dir($this->path . '/.git') && file_exists($this->path . '/lib/components.json'));
    }

    public static function isClone(string $path): bool {
        return is_dir($path) && is_dir($path . '/.git') && file_exists($path . '/lib/components.json');
    }

    /**
     * @return array<string>
     * @throws ProcessFailedException if command fails
     * @throws Exception if clone does not exist
     */
    public function getTags(): array
    {
        $tagsProcess = new Process(['git', 'tag', '-l']);
        $tagsProcess->setWorkingDirectory($this->path)->mustRun();
        return explode("\n", trim($tagsProcess->getOutput()));
    }

    public function checkout(string $ref): void {
        $process = new Process(['git', 'checkout', $ref]);
        $process->setWorkingDirectory($this->path)->mustRun();
    }

    public function clean(): void {
        echo "Cleaning $this->path\n";
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