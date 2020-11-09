<?php declare(strict_types=1);
namespace Naisdevice\HealthChecker;

use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass Naisdevice\HealthChecker\Severity
 */
class SeverityTest extends TestCase {
    /**
     * @return array<string,array{0:string,1:bool}>
     */
    public function getTags() : array {
        return [
            'valid tag' => [
                'CRITICAL',
                true,
            ],
            'valid tag (lowercase)' => [
                'CRITICAL',
                true,
            ],
            'invalid tag (lowercase)' => [
                'HIGH',
                false,
            ],
        ];
    }

    /**
     * @dataProvider getTags
     * @covers ::isSeverityTag
     */
    public function testCheckSeverityTagsTags(string $tag, bool $isValid) : void {
        $this->assertSame($isValid, Severity::isSeverityTag($tag), 'Unable to get tag validity');
    }

    /**
     * @return array<string,array{tags:array<string>,expectedTime:int}>
     */
    public function getTagsForGraceTime() : array {
        return [
            'no tags' => [
                'tags' => [],
                'expectedTime' => Severity::WARNING,
            ],
            'multiple tags' => [
                'tags' => [
                    'CRITICAL',
                    'LINUX',
                    'WINDOWS'
                ],
                'expectedTime' => Severity::CRITICAL,
            ],
            'multiple tags including INFO' => [
                'tags' => [
                    'CRITICAL',
                    'LINUX',
                    'INFO'
                ],
                'expectedTime' => Severity::INFO,
            ],
        ];
    }

    /**
     * @dataProvider getTagsForGraceTime
     * @covers ::getGraceTime
     * @param array<string> $tags
     */
    public function testCanGetTagGraceTime(array $tags, int $expectedTime) : void {
        $this->assertSame($expectedTime, Severity::getGraceTime($tags));
    }
}