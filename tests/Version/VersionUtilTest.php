<?php

namespace MoodleAnalysis\Version;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(VersionUtil::class)]
class VersionUtilTest extends TestCase
{

    public function testFindClosest(): void {
        $util = new VersionUtil();
        $this->assertEquals('4.2.3', $util->findClosest('4.2.3', ['4.2.3', '4.2.4', '4.2.5']));
        $this->assertNull($util->findClosest('4.2.2', ['4.2.3', '4.2.4', '4.2.5', '4.3.0']));
        $this->assertEquals('4.2.0', $util->findClosest('4.2', ['4.2.0', '4.2.1', '4.2.2', '4.2.3', '4.2.4', '4.2.5', '4.3.0']));
        $this->assertEquals('4.3.0', $util->findClosest('^4.2.0', ['4.2.0', '4.2.1', '4.2.2', '4.2.3', '4.2.4', '4.2.5', '4.3.0']));
        $this->assertEquals('4.2.5', $util->findClosest('4.2.x', ['4.2.0', '4.2.1', '4.2.2', '4.2.3', '4.2.4', '4.2.5', '4.3.0']));
        $this->assertEquals('4.2.5', $util->findClosest('4.2.*', ['4.2.0', '4.2.1', '4.2.2', '4.2.3', '4.2.4', '4.2.5', '4.3.0']));

        // Do the exact same test with v in front of all the version numbers.
        $this->assertEquals('v4.2.3', $util->findClosest('v4.2.3', ['v4.2.3', 'v4.2.4', 'v4.2.5']));
        $this->assertNull($util->findClosest('v4.2.2', ['v4.2.3', 'v4.2.4', 'v4.2.5', 'v4.3.0']));
        $this->assertEquals('v4.2.0', $util->findClosest('v4.2', ['v4.2.0', 'v4.2.1', 'v4.2.2', 'v4.2.3', 'v4.2.4', 'v4.2.5', 'v4.3.0']));
        $this->assertEquals('v4.3.0', $util->findClosest('^v4.2.0', ['v4.2.0', 'v4.2.1', 'v4.2.2', 'v4.2.3', 'v4.2.4', 'v4.2.5', 'v4.3.0']));
        $this->assertEquals('v4.2.5', $util->findClosest('v4.2.x', ['v4.2.0', 'v4.2.1', 'v4.2.2', 'v4.2.3', 'v4.2.4', 'v4.2.5', 'v4.3.0']));
        $this->assertEquals('v4.2.5', $util->findClosest('v4.2.*', ['v4.2.0', 'v4.2.1', 'v4.2.2', 'v4.2.3', 'v4.2.4', 'v4.2.5', 'v4.3.0']));

        // No v in the test version numbers.
        $this->assertEquals('v4.2.3', $util->findClosest('4.2.3', ['v4.2.3', 'v4.2.4', 'v4.2.5']));
        $this->assertNull($util->findClosest('4.2.2', ['v4.2.3', 'v4.2.4', 'v4.2.5', 'v4.3.0']));
        $this->assertEquals('v4.2.0', $util->findClosest('4.2', ['v4.2.0', 'v4.2.1', 'v4.2.2', 'v4.2.3', 'v4.2.4', 'v4.2.5', 'v4.3.0']));
        $this->assertEquals('v4.3.0', $util->findClosest('^4.2.0', ['v4.2.0', 'v4.2.1', 'v4.2.2', 'v4.2.3', 'v4.2.4', 'v4.2.5', 'v4.3.0']));
        $this->assertEquals('v4.2.5', $util->findClosest('4.2.x', ['v4.2.0', 'v4.2.1', 'v4.2.2', 'v4.2.3', 'v4.2.4', 'v4.2.5', 'v4.3.0']));
        $this->assertEquals('v4.2.5', $util->findClosest('4.2.*', ['v4.2.0', 'v4.2.1', 'v4.2.2', 'v4.2.3', 'v4.2.4', 'v4.2.5', 'v4.3.0']));

        // No v in the options.
        $this->assertEquals('4.2.3', $util->findClosest('v4.2.3', ['4.2.3', '4.2.4', '4.2.5']));
        $this->assertNull($util->findClosest('v4.2.2', ['4.2.3', '4.2.4', '4.2.5', '4.3.0']));
        $this->assertEquals('4.2.0', $util->findClosest('v4.2', ['4.2.0', '4.2.1', '4.2.2', '4.2.3', '4.2.4', '4.2.5', '4.3.0']));
        $this->assertEquals('4.3.0', $util->findClosest('^v4.2.0', ['4.2.0', '4.2.1', '4.2.2', '4.2.3', '4.2.4', '4.2.5', '4.3.0']));
        $this->assertEquals('4.2.5', $util->findClosest('v4.2.x', ['4.2.0', '4.2.1', '4.2.2', '4.2.3', '4.2.4', '4.2.5', '4.3.0']));
        $this->assertEquals('4.2.5', $util->findClosest('v4.2.*', ['4.2.0', '4.2.1', '4.2.2', '4.2.3', '4.2.4', '4.2.5', '4.3.0']));
    }

    public function testFindLatestCompatible(): void {
        $util = new VersionUtil();
        $this->assertEquals('4.2.3', $util->findLatestCompatible('v4.2.3', ['4.2.3', '4.2.4', '4.2.5']));
        $this->assertNull($util->findLatestCompatible('v4.2.2', ['4.2.3', '4.2.4', '4.2.5', '4.3.0']));
        $this->assertEquals('4.2.5', $util->findLatestCompatible('v4.2', ['4.2.0', '4.2.1', '4.2.2', '4.2.3', '4.2.4', '4.2.5', '4.3.0']));
        $this->assertEquals('4.3.0', $util->findLatestCompatible('^v4.2.0', ['4.2.0', '4.2.1', '4.2.2', '4.2.3', '4.2.4', '4.2.5', '4.3.0']));
        $this->assertEquals('4.2.5', $util->findLatestCompatible('v4.2.x', ['4.2.0', '4.2.1', '4.2.2', '4.2.3', '4.2.4', '4.2.5', '4.3.0']));
        $this->assertEquals('4.2.5', $util->findLatestCompatible('v4.2.*', ['4.2.0', '4.2.1', '4.2.2', '4.2.3', '4.2.4', '4.2.5', '4.3.0']));
    }

}
