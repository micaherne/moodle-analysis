<?php

namespace MoodleAnalysis\Version;

use Composer\Semver\Comparator;
use Composer\Semver\Semver;
use Composer\Semver\VersionParser;

class VersionUtil
{

    /**
     * Find the closest version to the given version from the list of options.
     *
     * This returns the exact version if it is in the list, otherwise it returns
     * the closest previous version.
     *
     * This is intended for where we have some kind of include files or whatever
     * for different Moodle versions and don't want to keep one for consecutive
     * versions where they don't change.
     *
     * The version string can be a full version number, a partial version number,
     * or a Composer compatible version string (e.g. ^4.2.0, 4.2.x, 4.2.*)
     *
     * Simple major.minor versions are treated as major.minor.0
     *
     * @param array<string> $options
     */
    public function findClosest(string $string, array $options): ?string
    {
         return array_reduce($options, function ($carry, $item) use ($string) {
             if ($item === $string) {
                 return $item;
             } elseif (Semver::satisfies($item, $string) && ($carry === null || Comparator::greaterThan($item, $carry))) {
                 return $item;
             } else {
                 return $carry;
             }
         });
    }

    /**
     * Find the latest version that is compatible with the given version string.
     *
     * This is similar to {@see findClosest}, but it will return the latest version that
     * is compatible in the case of a simple major.minor version (e.g. 4.2 will return 4.2.5,
     * not 4.2.0, if both are available options).
     *
     * @param array<string> $options
     */
    public function findLatestCompatible(string $string, array $options): ?string
    {
        if (substr_count($string, '.') === 1) {
            $string .= '.*';
        }

        return array_reduce($options, function ($carry, $item) use ($string) {
            if (Semver::satisfies($item, $string) && ($carry === null || Comparator::greaterThan($item, $carry))) {
                return $item;
            } else {
                return $carry;
            }
        });
    }

    /**
     * Given a directory of PHP files named after Moodle versions, return an array of the versions.
     *
     * @return array<string>
     */
    public function versionsInDirectory(string $directory): array
    {
        return array_map(fn($file): string => basename($file, '.php'), glob($directory . '/*.php') ?: []);
    }
}