<?php

namespace MoodleAnalysis\Console\Command\Worker;

use MoodleAnalysis\Component\CoreComponentBridge;
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
                // We know that there are hundreds of classes in the core_component
                // class map that do not actually exist. We just need to ensure that
                // all the existing ones can be loaded without failures.
                $logger->debug("Class $class not found in the class map");
            }
        }

        foreach (CoreComponentBridge::getClassMapRenames() as $class) {
            if (!class_exists($class)) {
                $logger->warning("Aliased class $class not found in the class map");
            }
        }

        return Command::SUCCESS;
    }

}