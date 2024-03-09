<?php

namespace MoodleAnalysisUtils\Component;

use MoodleAnalysisUtils\MoodleCodebaseAware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function PHPUnit\Framework\assertArrayHasKey;
use function PHPUnit\Framework\assertCount;

#[CoversClass(ComponentsFinder::class)]
class ComponentsFinderTest extends TestCase
{
    use MoodleCodebaseAware;

    private ComponentsFinder $componentsFinder;

    #[\Override]
    protected function setUp(): void
    {
        if (!$this->isMoodleCodebaseAvailable()) {
            $this->markTestSkipped("Moodle path not defined or not found");
        }
        $this->componentsFinder = new ComponentsFinder(constant(self::MOODLE_PATH_CONST));
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
