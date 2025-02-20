<?php

namespace MoodleAnalysis\Analyse;

use Symfony\Component\Process\Process;

readonly class ServerProcess
{
    public function __construct(
        public string $serviceAddress,
        public Process $process
    ) {
    }
}