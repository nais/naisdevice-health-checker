<?php declare(strict_types=1);
namespace Naisdevice\HealthChecker\Command;

use Naisdevice\HealthChecker\KolideApiClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @coversDefaultClass Naisdevice\HealthChecker\Command\ListChecks
 */
class ListChecksTest extends TestCase {
    protected function setUp() : void {
        putenv('KOLIDE_API_TOKEN');
    }

    /**
     * @covers ::initialize
     */
    public function testFailsOnMissingOption() : void {
        $commandTester = new CommandTester(new ListChecks());
        $this->expectExceptionObject(new RuntimeException(
            'Specify a token for the Kolide API by setting the KOLIDE_API_TOKEN environment variable'
        ));
        $commandTester->execute([]);
    }

    /**
     * @return array<string,array{0:array<int,array{id:int,failing_device_count:int,name:string,description:string,notification_strategy:string}>,1:string}>
     */
    public function getChecks() : array {
        return [
            'no checks' => [
                [],
                '[]'
            ],
            'checks' => [
                [
                    [
                        'id'                    => 1,
                        'failing_device_count'  => 0,
                        'name'                  => 'check1',
                        'description'           => 'description1',
                        'notification_strategy' => 'strategy1',
                    ],
                    [
                        'id'                    => 2,
                        'failing_device_count'  => 3,
                        'name'                  => 'check2',
                        'description'           => 'description2',
                        'notification_strategy' => 'strategy2',
                    ],
                ],
                '[{"id":1,"failing_device_count":0,"name":"check1","description":"description1","notification_strategy":"strategy1"},{"id":2,"failing_device_count":3,"name":"check2","description":"description2","notification_strategy":"strategy2"}]'
            ],
        ];
    }

    /**
     * @dataProvider getChecks
     * @covers ::execute
     * @covers ::setKolideApiClient
     * @covers ::initialize
     * @covers ::configure
     * @covers ::__construct
     * @param array<int,array{id:int,failing_device_count:int,name:string,description:string,notification_strategy:string}> $checks
     */
    public function testCanListChecks(array $checks, string $expectedOutput) : void {
        $command = new ListChecks();
        $command->setKolideApiClient($this->createConfiguredMock(KolideApiClient::class, [
            'getAllChecks' => $checks,
        ]));

        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        $output = $commandTester->getDisplay();

        $this->assertSame($expectedOutput, trim($output));
        $this->assertSame(0, $commandTester->getStatusCode(), 'Expected command to return 0');
    }
}