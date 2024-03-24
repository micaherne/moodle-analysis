<?php

namespace MoodleAnalysis\Console\Command\Worker;

use MoodleAnalysis\Component\CoreComponentBridge;
use MoodlePhpstan\MoodleRootManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Process\Process;

class CheckClassloaderWorker
{

    public function run(string $moodleRoot, LoggerInterface $logger): int {
        $composerInstallProcess = new Process(['composer', 'install'], $moodleRoot);
        $composerInstallProcess->mustRun();

        // This class was deprecated in 3.3 but is still there and conflicts with an alias
        // made in the persistent class file for core_competency.
        if (file_exists($moodleRoot . '/competency/classes/invalid_persistent_exception.php')) {
            unlink($moodleRoot . '/competency/classes/invalid_persistent_exception.php');
        }

        CoreComponentBridge::loadCoreComponent($moodleRoot);
        CoreComponentBridge::registerClassloader();
        CoreComponentBridge::loadStandardLibraries();
        CoreComponentBridge::fixClassloader();

        foreach (array_keys(CoreComponentBridge::getClassMap()) as $class) {
            if (!class_exists($class)) {
                $logger->debug("Class $class not found in the class map");
            }
        }

        foreach (CoreComponentBridge::getClassMapRenames() as $class) {
            if (!class_exists($class)) {
                $logger->debug("Aliased class $class not found in the class map");
            }
        }

        return Command::SUCCESS;
    }

}