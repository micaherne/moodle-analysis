<?php

namespace MoodleAnalysis\Codebase;

use InvalidArgumentException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class MoodleCloneProvider
{

    public function getClone(string $clonePath): MoodleClone {
        if (MoodleClone::isClone($clonePath)) {
            return new MoodleClone($clonePath);
        } else {
            throw new InvalidArgumentException("The path provided is not a Moodle clone");
        }
    }

    public function cloneMoodle(bool $bare = false): MoodleClone
    {

        $fs = new Filesystem();

        // This is not strictly thread-safe, but it's good enough for our purposes.
        $tempDir = $fs->tempnam(sys_get_temp_dir(), 'moodle-analysis');
        $fs->remove($tempDir);

        $command = ['git', 'clone'];
        if ($bare) {
            $command[] = '--bare';
        }
        $command[] = 'git://git.moodle.org/moodle.git';
        $command[] = $tempDir;

        $process = new Process(
            $command
        );

        $process->setTimeout(600)->mustRun();

        return $this->getClone($tempDir);
    }


}