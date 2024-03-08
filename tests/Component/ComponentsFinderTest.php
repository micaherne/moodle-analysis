<?php

namespace Component;

use Composer\Autoload\ClassLoader;
use MoodleAnalysisUtils\Component\ComponentsFinder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

use function PHPUnit\Framework\assertArrayHasKey;
use function PHPUnit\Framework\assertCount;

#[CoversClass(ComponentsFinder::class)]
class ComponentsFinderTest extends TestCase
{
    private ComponentsFinder $componentsFinder;

    private const string MOODLE_PATH_CONST = 'MOODLE_ANALYSIS_UTILS_MOODLE_433_PATH';

    #[\Override]
    protected function setUp(): void
    {
        if (defined(self::MOODLE_PATH_CONST) && constant(self::MOODLE_PATH_CONST) !== 'false'
        && is_dir(constant(self::MOODLE_PATH_CONST))) {
            $this->componentsFinder = new ComponentsFinder(constant(self::MOODLE_PATH_CONST));
            return;
        }
        $this->markTestSkipped("Moodle path not defined or not found");
    }

    public function testGetSubplugins(): void
    {
        $subplugins = iterator_to_array($this->componentsFinder->getSubplugins('mod/assign'));
        assertCount(7, $subplugins);
        assertArrayHasKey('assignsubmission_onlinetext', $subplugins);
    }

    public function testGetComponents(): void
    {
        $components = iterator_to_array($this->componentsFinder->getComponents());
        assertCount(495, $components);
    }

    public function testGetComponentsWithPath(): void
    {
        $components = iterator_to_array($this->componentsFinder->getComponentsWithPath('db/renamedclasses.php'));
        assertCount(5, $components);
        assertArrayHasKey('core', $components);
        assertArrayHasKey('report_configlog', $components);
    }
}
