<?php

namespace MoodleAnalysis\Console\Command\Worker;

use Exception;
use MoodleAnalysis\Analyse\Provider\MainAnalysisNotReadableException;
use MoodleAnalysis\Analyse\Provider\MainAnalysisProvider;
use MoodleAnalysis\Component\CoreComponentBridge;
use PhpParser\BuilderFactory;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\FindingVisitor;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;
use PhpParser\PrettyPrinter\Standard;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;

/**
 * Generates a list of all the files that need to be included for the Moodle classloader to
 * load all the classes in its class map cleanly. This was used to work out how to implement
 * the {@see CoreComponentBridge::fixClassloader} method.
 *
 * The basic process is:
 * * Parse all files that may contain classes, interfaces, traits or enums.
 * * Ignore those that can be autoloaded by the Moodle classloader.
 * * Construct a crappy temporary classloader to load the rest.
 * * Go through all the classes in the Moodle core_component class map and load them.
 * * Write out a list of all the files that were included by the temporary classloader.
 *
 * This is not a perfect solution as there is no guarantee that there aren't dependencies
 * between the files to be included, but it's enough information to work out what needs to be
 * done in practice.
 */
class GenerateClassloaderBootstrapWorker
{

    /**
     * @param string $moodleRoot
     * @param LoggerInterface $logger
     * @param string $tag
     * @return int
     * @throws MainAnalysisNotReadableException
     * @throws \ReflectionException
     */
    public function run(string $moodleRoot, LoggerInterface $logger, string $tag, bool $fixClassloader): int
    {
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
        if ($fixClassloader) {
            CoreComponentBridge::fixClassloader();
        }

        $analysisProvider = new MainAnalysisProvider();

        if (!$analysisProvider->analysisExistsForTag($tag)) {
            // This should already have been checked so shouldn't happen.
            throw new MainAnalysisNotReadableException("Analysis not found for $tag");
        }

        $classMap = [];
        $classAliases = [];
        $analysis = $analysisProvider->getAnalysisForTag($tag);
        foreach ($analysis as $path => $data) {
            foreach ($data['classlikes'] as $classlike) {
                $classMap[$classlike] = $path;
            }
            $aliasesWithFile = array_map(fn($item) => $item + ['file' => $path], $data['class_aliases']);

            $classAliases = [...$classAliases, ...$aliasesWithFile];
        }

        // Create crappy autoloader.
        $includedByLegacyLoader = [];
        spl_autoload_register(function ($classname) use ($classMap, $moodleRoot, &$includedByLegacyLoader): void {
            // Do not remove - this is used by some requires.
            global $CFG;
            if (array_key_exists($classname, $classMap) && is_file($moodleRoot . '/' . $classMap[$classname])) {
                $includedByLegacyLoader[$classMap[$classname]] = 1;
                require_once $moodleRoot . '/' . $classMap[$classname];
            }
        });

        // Insert composer autoloader before any others.
        // This is required to ensure that any classes that are autoloaded by Moodle are the correct versions
        // and not the ones from this project (in particular PHPUnit).
        // Note: must be done after any parsing as Moodle's dev dependencies contain an older version of php-parser.
        $loadedAutoloaders = spl_autoload_functions();
        foreach ($loadedAutoloaders as $autoloader) {
            spl_autoload_unregister($autoloader);
        }
        require_once $moodleRoot . '/vendor/autoload.php';
        foreach ($loadedAutoloaders as $autoloader) {
            spl_autoload_register($autoloader);
        }

        // Work out which files with static class_alias() calls haven't been loaded.
        // This should be done before we try to load any classes otherwise we may
        // exclude some files which are loaded by the classloader.
        $withStaticClassAliasCalls = [];
        $includedFiles = get_included_files();
        $renamedClasses = CoreComponentBridge::getClassMapRenames();

        foreach ($classAliases as $alias) {
            $logger->debug("Checking that {$alias['file']} for {$alias['alias']} has been loaded");
            $realpath = realpath($moodleRoot . '/' . $alias['file']);
            if ($realpath === false) {
                // This shouldn't happen as the list of files has been created from the same version of Moodle.
                $logger->error("File {$alias['file']} does not exist");
                continue;
            }
            if (
                // We allow the use of the classloader here because if it can find them we don't need to include them.
                !$this->classlike_exists($alias['alias'])
                && !in_array($realpath, $includedFiles)
                && !array_key_exists($alias['alias'], $renamedClasses)
            ) {
                $withStaticClassAliasCalls[$alias['file']] = true;
            }
        }

        $autoloadClasses = CoreComponentBridge::getClassMap();

        foreach (array_keys($autoloadClasses) as $name) {
            $logger->debug("Checking class $name");
            $this->classlike_exists($name);
        }

        foreach (array_keys($renamedClasses) as $name) {
            $logger->debug("Checking renamed class $name");
            $this->classlike_exists($name);
        }

        // Make sure that any classes that are the target of static class_alias() calls
        // can be loaded.
        foreach ($classAliases as $alias) {
            $logger->debug("Checking original {$alias['original']}");
            $this->classlike_exists($alias['original']);
        }

        $outputFile = dirname(__DIR__, 4) . '/resources/bootstrap-classloader/'
            . $tag . ($fixClassloader ? '-fixed' : '') . '.php';

        $out = fopen($outputFile, 'w');
        if ($out === false) {
            $logger->error("Unable to open $outputFile for writing");
            return Command::FAILURE;
        }

        fwrite($out, "<?php \nglobal \$CFG;\n");
        foreach (array_keys($includedByLegacyLoader) as $include) {
            fwrite($out, "require_once \$CFG->dirroot . \"/" . $include . "\";\n");
        }
        fclose($out);

        $outputFile = dirname(__DIR__, 4) . '/resources/bootstrap-classloader/'
            . $tag . '-classalias.php';

        // Use PhpParser to create the output so we don't get the old-style array keys var_export() gives.
        $builder = new BuilderFactory();
        $array = $builder->val(array_keys($withStaticClassAliasCalls));
        $aliasPhp = (new Standard(['phpVersion' => PhpVersion::fromComponents(8, 3)]))->prettyPrintFile(
            [new Node\Stmt\Return_($array)]
        );

        file_put_contents($outputFile, $aliasPhp);

        return Command::SUCCESS;
    }

    private function classlike_exists(string $name, bool $autoload = true): bool
    {
        return class_exists($name, $autoload) || interface_exists($name, $autoload)
            || trait_exists($name, $autoload) || enum_exists($name, $autoload);
    }

}