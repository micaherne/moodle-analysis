<?php

namespace MoodleAnalysisUtils\Component;

use MoodleAnalysisUtils\MoodleCodebaseAware;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[CoversClass(CoreComponentBridge::class)]
class CoreComponentBridgeTest extends TestCase
{
    use MoodleCodebaseAware;

    #[Override]
    protected function setUp(): void
    {
        if (!$this->isMoodleCodebaseAvailable()) {
            $this->markTestSkipped("Moodle path not defined or not found");
        }
        CoreComponentBridge::loadCoreComponent($this->getMoodleCodebasePath());
    }

    public function testGetClassesDirectories(): void
    {
        $this->assertNotEmpty(CoreComponentBridge::getClassesDirectories());
    }

    public function testCanAutoloadSymbol(): void
    {
        $this->assertTrue(CoreComponentBridge::canAutoloadSymbol('core\context\module'));
    }

    public function testLoadCoreComponent(): void
    {
        // Ensure that there are no exceptions thrown on trying to load core_component
        // using the same Moodle root path.
        CoreComponentBridge::loadCoreComponent($this->getMoodleCodebasePath());
        $this->assertTrue(true);
    }
}
