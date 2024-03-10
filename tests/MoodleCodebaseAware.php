<?php

namespace MoodleAnalysis;

use RuntimeException;

trait MoodleCodebaseAware
{

    private const string MOODLE_PATH_CONST = 'MOODLE_ANALYSIS_UTILS_MOODLE_433_PATH';

    private function isMoodleCodebaseAvailable(): bool
    {
        return defined(self::MOODLE_PATH_CONST) && constant(self::MOODLE_PATH_CONST) !== 'false'
            && is_dir(constant(self::MOODLE_PATH_CONST));
    }

    private function getMoodleCodebasePath(): string
    {
        if (!$this->isMoodleCodebaseAvailable()) {
            throw new RuntimeException("Moodle path not defined or not found");
        }
        return constant(self::MOODLE_PATH_CONST);
    }

}