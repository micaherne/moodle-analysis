<?php

namespace MoodleAnalysisUtils\Component;

use ReflectionMethod;
use RuntimeException;

/**
 * Loads the core component class from Moodle.
 *
 * Note that this will execute the actual Moodle code. This should be called only once per process, or it will throw an exception,
 * as the class can only be loaded once.
 *
 * This is quite hacky, but there are no better options that I can find.
 */
final class CoreComponentBridge
{
    /** @phpstan-ignore-next-line core_component is loaded from Moodle */
    private static \ReflectionClass $componentReflection;

    /** @var array<string, string> */
    private static array $classmap;

    /** @var array<string, string> */
    private static array $classmaprenames;
    private static ReflectionMethod $psrClassloader;

    private static string $moodleRoot;

    /**
     * Load the core component class for the given Moodle.
     *
     * This is static because core_component can only be loaded once per process.
     *
     * @throws \ReflectionException
     */
    public static function loadCoreComponent(string $moodleRoot): void
    {
        global $CFG;

        $moodleRootReal = realpath($moodleRoot);
        if ($moodleRootReal === false) {
            throw new RuntimeException("Moodle root $moodleRoot not found");
        }

        // Only load once per process.
        if (isset(self::$moodleRoot)) {
            if (self::$moodleRoot !== $moodleRootReal) {
                throw new RuntimeException('Core component already loaded with different Moodle root');
            }
            if (!class_exists('\core_component', false)) {
                throw new RuntimeException('loadCoreComponent() has been called previously but core_component is not loaded');
            }
            return;
        }

        self::$moodleRoot = $moodleRootReal;

        // It may have been loaded some other way.
        if (class_exists('\core_component', false)) {
            throw new RuntimeException('Core component already loaded');
        }

        defined('CACHE_DISABLE_ALL') || define('CACHE_DISABLE_ALL', true);
        defined('MOODLE_INTERNAL') || define('MOODLE_INTERNAL', true);

        $CFG = (object)[
            'dirroot' => self::$moodleRoot,
            'wwwroot' => 'https://localhost',
            'dataroot' => sys_get_temp_dir(),
            'libdir' => self::$moodleRoot . '/lib',
            'admin' => 'admin',
            'cachedir' => sys_get_temp_dir() . '/cache',
            'debug' => 0,
            'debugdeveloper' => false, // formslib checks this
        ];

        // Set include_path so that PEAR libraries are found.
        ini_set('include_path', $CFG->libdir . '/pear' . PATH_SEPARATOR . ini_get('include_path'));

        // A bunch of properties set by setup.php.

        // Make sure there is some database table prefix.
        if (!isset($CFG->prefix)) {
            $CFG->prefix = '';
        }

        // Allow overriding of tempdir but be backwards compatible
        if (!isset($CFG->tempdir)) {
            $CFG->tempdir = $CFG->dataroot . DIRECTORY_SEPARATOR . "temp";
        }

        // Allow overriding of backuptempdir but be backwards compatible
        if (!isset($CFG->backuptempdir)) {
            $CFG->backuptempdir = "$CFG->tempdir/backup";
        }

        // Allow overriding of cachedir but be backwards compatible
        if (!isset($CFG->cachedir)) {
            $CFG->cachedir = "$CFG->dataroot/cache";
        }

        // Allow overriding of localcachedir.
        if (!isset($CFG->localcachedir)) {
            $CFG->localcachedir = "$CFG->dataroot/localcache";
        }

        // Allow overriding of localrequestdir.
        if (!isset($CFG->localrequestdir)) {
            $CFG->localrequestdir = sys_get_temp_dir() . '/requestdir';
        }

        // Location of all languages except core English pack.
        if (!isset($CFG->langotherroot)) {
            $CFG->langotherroot = $CFG->dataroot . '/lang';
        }

        // Location of local lang pack customisations (dirs with _local suffix).
        if (!isset($CFG->langlocalroot)) {
            $CFG->langlocalroot = $CFG->dataroot . '/lang';
        }

        require_once self::$moodleRoot . '/lib/classes/component.php';

        /** @phpstan-ignore-next-line core_component is loaded from Moodle */
        self::$componentReflection = new \ReflectionClass(\core_component::class);
        self::$componentReflection->getMethod('init')->invoke(null);
        self::$classmap = self::toStringMap(self::$componentReflection->getStaticPropertyValue('classmap'));
        self::$classmaprenames = self::toStringMap(self::$componentReflection->getStaticPropertyValue('classmaprenames'));
        self::$psrClassloader = self::$componentReflection->getMethod('psr_classloader');
    }

    public static function canAutoloadSymbol(string $symbol): bool
    {
        // TODO: Add support for autoloader use, e.g. CAS, Google, etc.
        return array_key_exists($symbol, self::$classmap) || array_key_exists(
                $symbol,
                self::$classmaprenames
            ) || (self::$psrClassloader->invoke(null, $symbol) !== false);
    }

    /**
     * @return array<string> relative directories of the classes directories in Moodle components.
     */
    public static function getClassesDirectories(): array
    {
        $autoloadedDirectories = self::getComponentSubdirectories('classes');

        // TODO: Is it a reasonable assumption that this can be excluded?
        $autoloadedDirectories[] = 'lib/classes';

        return $autoloadedDirectories;
    }

    /**
     * Explicitly convert a map to a string map.
     *
     * This is specifically for use with the getStaticPropertyValue() method of ReflectionClass.
     *
     * @return array<string, string>
     */
    private static function toStringMap(mixed $map): array
    {
        if (!is_array($map)) {
            throw new RuntimeException('Map is not an array');
        }
        $result = [];
        foreach ($map as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                throw new RuntimeException('Map key or value is not a string');
            }
            $result[$key] = $value;
        }
        return $result;
    }

    /**
     * @return array<string>
     */
    private static function getComponentSubdirectories(string $subDirectoryName): array
    {
        $result = [];

        /** @phpstan-ignore-next-line core_component is loaded from Moodle */
        foreach (\core_component::get_component_list() as $componentList) {
            foreach ($componentList as $componentDirectory) {
                if (is_dir($componentDirectory . '/' . $subDirectoryName)) {
                    $realpath = realpath($componentDirectory . '/' . $subDirectoryName);
                    if ($realpath === false) {
                        throw new RuntimeException('Component directory not found');
                    }
                    if (!str_starts_with($realpath, self::$moodleRoot)) {
                        throw new RuntimeException('Component directory does not start with Moodle root');
                    }
                    $result[] = substr($realpath, strlen(self::$moodleRoot) + 1);
                }
            }
        }
        return $result;
    }

    /**
     * @return array<string, string>
     */
    public static function getClassmap(): array
    {
        return self::$classmap;
    }

    /**
     * @return array<string, string>
     */
    public static function getClassmapRenames(): array
    {
        return self::$classmaprenames;
    }



}