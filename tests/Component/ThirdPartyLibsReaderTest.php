<?php

namespace MoodleAnalysis\Component;

use MoodleAnalysis\Component\ThirdPartyLibsReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ThirdPartyLibsReader::class)]
class ThirdPartyLibsReaderTest extends TestCase
{

    public function testGetLocationsRelative(): void
    {
        $reader = new ThirdPartyLibsReader();
        $result = $reader->getLocationsRelative(__DIR__ . '/fixture/test-plugin-1');
        $this->assertContains('php-di/php-di', $result['dirs']);
    }
}
