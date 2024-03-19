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

    public function cloneMoodle(): MoodleClone
    {

        $fs = new Filesystem();

        // This is not strictly thread-safe, but it's good enough for our purposes.
        $tempDir = $fs->tempnam(sys_get_temp_dir(), 'moodle-analysis');
        $fs->remove($tempDir);

        $process = new Process(
            ['git', 'clone', 'git://git.moodle.org/moodle.git', $tempDir]
        );

        $process->setTimeout(300)->mustRun();

        return $this->getClone($tempDir);
    }


}