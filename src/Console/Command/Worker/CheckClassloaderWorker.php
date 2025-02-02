<?php

namespace MoodleAnalysis\Console\Command\Worker;

use MoodleAnalysis\Analyse\Provider\MainAnalysisProvider;
use MoodleAnalysis\Component\CoreComponentBridge;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Process\Process;

class CheckClassloaderWorker
{

    public function run(string $moodleRoot, LoggerInterface $logger, string $tag): int {
        global $CFG;
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
        CoreComponentBridge::addMissingClassAliasDeclarations();

        // We need to check the aliases before we check the classloader in case any files
        // including class_alias() calls happen to be included during that check.
        $mainAnalysisProvider = new MainAnalysisProvider();
        $analysisFile = $mainAnalysisProvider->getAnalysisFileForTag($tag);
        if (is_file($analysisFile)) {
            $analysis = $mainAnalysisProvider->getAnalysisForTag($tag);
            foreach ($analysis as $data) {
                foreach ($data['class_aliases'] as $alias) {
                    if (!$this->classlike_exists($alias['original'])) {
                        $logger->warning("Aliased class {$alias['original']} not found");
                    }
                    if (!$this->classlike_exists($alias['alias'])) {
                        $logger->warning("Alias {$alias['alias']} not found");
                    }
                }
            }
        }

        foreach (array_keys(CoreComponentBridge::getClassMap()) as $class) {
            if (!$this->classlike_exists($class)) {
                // We know that there are hundreds of classes in the core_component
                // class map that do not actually exist. We just need to ensure that
                // all the existing ones can be loaded without failures.
                $logger->debug("Class $class not found in the class map");
            }
        }

        foreach (CoreComponentBridge::getClassMapRenames() as $class) {
            if (!$this->classlike_exists($class)) {
                $logger->warning("Renamed class $class not found in the class map");
            }
        }

        return Command::SUCCESS;
    }

    private function classlike_exists(string $name, bool $autoload = true): bool {
        return class_exists($name, $autoload) || interface_exists($name, $autoload)
            || trait_exists($name, $autoload) || enum_exists($name, $autoload);
    }

}