<?php

namespace MoodleAnalysis\Console\Command\Worker;

use Exception;
use MoodleAnalysis\Component\CoreComponentBridge;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\FindingVisitor;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
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

    public function run(string $moodleRoot, string $outputFile, LoggerInterface $logger): int {
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

        $classesFinder = new Finder();
        $classesFinder->in($moodleRoot)->name('*.php')->files()
            ->exclude(['node_modules', 'vendor'])->contains('(class|interface|trait|enum)');

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $classNodeFinder = new FindingVisitor(fn(Node $node): bool => $node instanceof ClassLike);
        $traverser = new NodeTraverser(new NameResolver(), $classNodeFinder);

        $classMap = [];

        foreach ($classesFinder as $file) {
            try {
                $nodes = $parser->parse($file->getContents());
            } catch (\PhpParser\Error $e) {
                $logger->error("Unable to parse {$file->getRelativePathname()}: {$e->getMessage()}");
                continue;
            }

            if ($nodes === null) {
                $logger->error("Unable to parse {$file->getRelativePathname()}: null returned");
                continue;
            }

            $traverser->traverse($nodes);
            $classes = $classNodeFinder->getFoundNodes();
            if ($classes === []) {
                continue;
            }

            foreach ($classes as $class) {

                if (!property_exists($class, 'namespacedName')) {
                    throw new Exception("Namespaced name not found");
                }

                if (!$class->namespacedName instanceof Node\Name) {
                    continue;
                }

                $className = $class->namespacedName->name;

                if (CoreComponentBridge::canAutoloadSymbol($className)) {
                    continue;
                }

                $logger->debug("Adding class $className from {$file->getRelativePathname()}");
                $classMap[$className] = $file->getRelativePathname();
            }
        }

        $includedByLegacyLoader = [];
        spl_autoload_register(function($classname) use ($classMap, $moodleRoot, &$includedByLegacyLoader): void {
            // Do not remove - this is used by some requires.
            global $CFG;
            if (array_key_exists($classname, $classMap)) {
                $includedByLegacyLoader[$classMap[$classname]] = 1;
                require_once $moodleRoot . '/' . $classMap[$classname];
            }
        });

        // Insert composer autoloader before any others.
        // This is required to ensure that any classes that are autoloaded by Moodle are the correct versions
        // and not the ones from this project (in particular PHPUnit).
        // Note: must be done after the parsing above as Moodle's dependencies contain an older version of php-parser.
        $loadedAutoloaders = spl_autoload_functions();
        foreach ($loadedAutoloaders as $autoloader) {
            spl_autoload_unregister($autoloader);
        }
        require_once $moodleRoot . '/vendor/autoload.php';
        foreach ($loadedAutoloaders as $autoloader) {
            spl_autoload_register($autoloader);
        }

        $autoloadClasses = CoreComponentBridge::getClassMap();

        foreach (array_keys($autoloadClasses) as $name) {
            $logger->info("Checking class $name");
            class_exists($name);
        }

        $out = fopen($outputFile, 'w');
        if ($out === false) {
            $logger->error("Unable to open $outputFile for writing");
            return Command::FAILURE;
        }

        fwrite($out, "<?php \nglobal \$CFG;\n");
        foreach (array_keys($includedByLegacyLoader) as $include) {
            fwrite($out, "require_once \$CFG->dirroot . \"/" . $include . "\";\n");
        }

        return Command::SUCCESS;
    }

}