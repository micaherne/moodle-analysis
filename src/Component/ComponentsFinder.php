<?php

namespace MoodleAnalysisUtils\Component;

use DirectoryIterator;
use Exception;
use Generator;
use InvalidArgumentException;
use stdClass;

/**
 * Finds all the components in a Moodle codebase. This is a simplistic implementation of what
 * core_component does and is intended for analysis where it's not appropriate to try and load
 * core_component.
 */
class ComponentsFinder
{

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
     * @throws Exception if unable to find or read the components.json files
     */
    public function getComponents(string $moodleDirectory): \Generator {
        $mainComponentsFile = $moodleDirectory . '/lib/components.json';
        if (!is_file($mainComponentsFile)) {
            throw new InvalidArgumentException("Components file not found in $moodleDirectory/lib/components.json");
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
            $pluginTypeDirAbsolute = $moodleDirectory . '/' . $plugintypeDirectory;
            foreach (new DirectoryIterator($pluginTypeDirAbsolute) as $directory) {
                if ($directory->isDot()) {
                    continue;
                }
                if (!$directory->isDir()) {
                    continue;
                }
                $directoryName = $directory->getFilename();
                if (array_key_exists($directoryName, self::$ignoreddirs) && !($plugintype === 'auth' && $directoryName === 'db')) {
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
            foreach ($this->getSubplugins($pathWithSubPlugins, $moodleDirectory) as $subplugin => $subpluginDir) {
                yield $subplugin => $subpluginDir;
            }
        }
    }

    /**
     * @throws Exception
     */
    public function getSubplugins(string $pathWithSubPlugins, string $moodleDirectory): \Generator
    {
        $subpluginsJsonFile = $moodleDirectory . '/' . $pathWithSubPlugins . '/db/subplugins.json';
        if (!is_file($subpluginsJsonFile)) {
            return;
        }
        $components = $this->readComponentsJsonFile($subpluginsJsonFile);
        foreach ($components->plugintypes as $pluginType => $pluginTypeDirectory) {
            $pluginTypeDirAbsolute = $moodleDirectory . '/' . $pluginTypeDirectory;
            foreach (new DirectoryIterator($pluginTypeDirAbsolute) as $directory) {
                if ($directory->isDot()) {
                    continue;
                }
                if (!$directory->isDir()) {
                    continue;
                }
                $directoryName = $directory->getFilename();
                if (array_key_exists($directoryName, self::$ignoreddirs) && !($pluginType === 'auth' && $directoryName === 'db')) {
                    continue;
                }
                yield $pluginType . '_' . $directoryName => $pluginTypeDirectory . '/' . $directoryName;
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