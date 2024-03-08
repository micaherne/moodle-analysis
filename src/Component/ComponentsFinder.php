<?php

namespace MoodleAnalysisUtils\Component;

use DirectoryIterator;
use Exception;
use Generator;
use InvalidArgumentException;
use stdClass;

/**
 * Finds all the components in a Moodle codebase. This is a simplistic implementation of what
 * core_component does and is intended for static analysis where it's not appropriate to try and load
 * core_component.
 */
class ComponentsFinder
{

    public function __construct(private readonly string $moodleRoot)
    {
        if (!is_dir($this->moodleRoot)) {
            throw new InvalidArgumentException("Moodle root directory not found: $this->moodleRoot");
        }
        if (!file_exists($this->moodleRoot . '/lib/components.json')) {
            throw new InvalidArgumentException("Components file not found in $this->moodleRoot/lib/components.json");
        }
    }

    /**
     * Directories to ignore when looking for components.
     *
     * Taken from core_component.
     *
     * @var array<string, true>
     */
    private static array $ignoreddirs = [
        'CVS' => true,
        '_vti_cnf' => true,
        'amd' => true,
        'classes' => true,
        'db' => true,
        'fonts' => true,
        'lang' => true,
        'pix' => true,
        'simpletest' => true,
        'templates' => true,
        'tests' => true,
        'yui' => true,
    ];

    /**
     * Plugin types that support subplugins.
     *
     * Also taken from core_component.
     *
     * @var array<string>
     */
    protected static array $supportsubplugins = ['mod', 'editor', 'tool', 'local'];

    /**
     * Get all the components in a Moodle codebase.
     *
     * @return Generator<string, string> A generator that yields the component name and the directory it's in.
     *
     * @throws Exception if unable to find or read the components.json files
     */
    public function getComponents(): Generator
    {
        $mainComponentsFile = $this->moodleRoot . '/lib/components.json';
        if (!is_file($mainComponentsFile)) {
            throw new InvalidArgumentException("Components file not found in {$this->moodleRoot}/lib/components.json");
        }

        // Core is a special case.
        yield 'core' => 'lib';

        $components = $this->readComponentsJsonFile($mainComponentsFile);
        foreach ($components->subsystems as $subsystem => $subsystemDirectory) {
            if (!is_null($subsystemDirectory)) {
                yield 'core_' . $subsystem => $subsystemDirectory;
            }
        }

        $pathsWithSubPlugins = [];
        foreach ($components->plugintypes as $plugintype => $plugintypeDirectory) {
            $pluginTypeDirAbsolute = $this->moodleRoot . '/' . $plugintypeDirectory;
            foreach (new DirectoryIterator($pluginTypeDirAbsolute) as $directory) {
                if ($directory->isDot()) {
                    continue;
                }
                if (!$directory->isDir()) {
                    continue;
                }
                $directoryName = $directory->getFilename();
                if (array_key_exists(
                        $directoryName,
                        self::$ignoreddirs
                    ) && !($plugintype === 'auth' && $directoryName === 'db')) {
                    continue;
                }
                $pluginDirectory = $plugintypeDirectory . '/' . $directoryName;
                yield $plugintype . '_' . $directoryName => $pluginDirectory;
                $subpluginsJsonPath = $pluginTypeDirAbsolute . '/' . $directoryName . '/db/subplugins.json';
                if (in_array($plugintype, self::$supportsubplugins) && is_file($subpluginsJsonPath)) {
                    $pathsWithSubPlugins[] = $pluginDirectory;
                }
            }
        }

        foreach ($pathsWithSubPlugins as $pathWithSubPlugins) {
            foreach ($this->getSubplugins($pathWithSubPlugins) as $subplugin => $subpluginDir) {
                yield $subplugin => $subpluginDir;
            }
        }
    }

    /**
     * @return Generator<string, string> A generator that yields the subplugin name and the directory it's in.
     * @throws Exception
     */
    public function getSubplugins(string $pathWithSubPlugins): \Generator
    {
        $subpluginsJsonFile = $this->moodleRoot . '/' . $pathWithSubPlugins . '/db/subplugins.json';
        if (!is_file($subpluginsJsonFile)) {
            return;
        }
        $components = $this->readComponentsJsonFile($subpluginsJsonFile);
        foreach ($components->plugintypes as $pluginType => $pluginTypeDirectory) {
            $pluginTypeDirAbsolute = $this->moodleRoot . '/' . $pluginTypeDirectory;
            foreach (new DirectoryIterator($pluginTypeDirAbsolute) as $directory) {
                if ($directory->isDot()) {
                    continue;
                }
                if (!$directory->isDir()) {
                    continue;
                }
                $directoryName = $directory->getFilename();
                if (array_key_exists(
                        $directoryName,
                        self::$ignoreddirs
                    ) && !($pluginType === 'auth' && $directoryName === 'db')) {
                    continue;
                }
                yield $pluginType . '_' . $directoryName => $pluginTypeDirectory . '/' . $directoryName;
            }
        }
    }

    public function getComponentsWithPath(string $path): Generator
    {
        foreach ($this->getComponents() as $component => $componentPath) {
            if (file_exists($this->moodleRoot . '/' . $componentPath . '/' . ltrim($path, '/'))) {
                yield $component => $path . '/' . $componentPath;
            }
        }
    }

    /**
     * @throws Exception
     */
    private function readComponentsJsonFile(string $componentsFile): stdClass
    {
        $mainComponentsFileContents = file_get_contents($componentsFile);
        if ($mainComponentsFileContents === false) {
            throw new Exception("Unable to read $componentsFile");
        }
        $components = json_decode($mainComponentsFileContents, false, 512, JSON_THROW_ON_ERROR);
        return (object)$components;
    }

}