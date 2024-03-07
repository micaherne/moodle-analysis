<?php

namespace Component;

use MoodleAnalysisUtils\Component\ThirdPartyLibsReader;
use PHPUnit\Framework\TestCase;

class ThirdPartyLibsReaderTest extends TestCase
{

    public function testGetLocationsRelative(): void
    {
        $reader = new ThirdPartyLibsReader();
        $result = $reader->getLocationsRelative(__DIR__ . '/fixture/test-plugin-1');
        $this->assertContains('php-di/php-di', $result['dirs']);
    }
}
