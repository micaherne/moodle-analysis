<?php

namespace MoodleAnalysis\Analyse\Provider;

/**
 * A utility class to simplify access to the data output by the analyse:codebase
 * command.
 */
class MainAnalysisProvider
{

    /**
     * @throws MainAnalysisNotReadableException
     */
    public function getAnalysisForTag(string $tag): array {
        $file = $this->getAnalysisFileForTag($tag);
        if (!is_file($file)) {
            throw new MainAnalysisNotReadableException("Analysis file not found: $file");
        }
        $result = json_decode(file_get_contents($file), true);
        if ($result === null) {
            throw new MainAnalysisNotReadableException("Unable to decode file: $file");
        }
        return $result;
    }

    /**
     * Get the location for the analysis file for a given tag.
     *
     * Note that this does not check whether the file exists.
     *
     * @param string $tag
     * @return string
     */
    public function getAnalysisFileForTag(string $tag): string {
        return dirname(__DIR__, 3) . '/resources/main-analysis/' . $tag . '.json';
    }

    public function analysisExistsForTag(string $tag): bool {
        $file = $this->getAnalysisFileForTag($tag);
        return is_file($file) && is_readable($file);
    }

}