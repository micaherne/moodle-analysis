<?php

namespace MoodleAnalysis\Console\Process;

class ProcessUtil
{

    /**
     * Get the command to run PHP.
     *
     * Adapted from PHPStan's ProcessHelper.
     *
     * @return array<string> the command to run PHP
     */
    public static function getPhpCommand(): array
    {
        $phpIni = php_ini_loaded_file();
        return $phpIni === false ? [PHP_BINARY] : [PHP_BINARY, '-c', $phpIni];
    }

}