<?php
declare(strict_types=1);

namespace MoodleAnalysisUtils\Component;

use Exception;

use function simplexml_load_file;

/**
 * A class to read the thirdpartylibs.xml file in a component directory.
 */
class ThirdPartyLibsReader
{

    private const string THIRDPARTYLIBS_XML = 'thirdpartylibs.xml';

    /**
     * @param string $componentDirectory full path to the component directory
     * @return array{ files: array<string>, dirs: array<string> }
     * @throws Exception
     */
    public function getLocationsRelative(string $componentDirectory): array {
        $result = ['files' => [], 'dirs' => []];
        $thirdPartyLibFile = $componentDirectory . '/' . self::THIRDPARTYLIBS_XML;

        if (!file_exists($thirdPartyLibFile)) {
            return $result;
        }

        $xml = simplexml_load_file($thirdPartyLibFile);
        if ($xml === false) {
            throw new Exception("Unable to read $thirdPartyLibFile as XML");
        }

        // It may be a single SimpleXMLElement or an array of them.
        foreach ($xml->library as $library) {
            $location = $library->location;
            if (is_dir($componentDirectory . '/' . $location)) {
                $result['dirs'][] = (string) $location;
            } else {
                $result['files'][] = (string) $location;
            }

        }
        return $result;
    }

}