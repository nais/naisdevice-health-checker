<?php declare(strict_types=1);
namespace Naisdevice\HealthChecker\Command;

use Naisdevice\HealthChecker\ApiServerClient;
use Naisdevice\HealthChecker\KolideApiClient;
use Symfony\Component\Console\Command\Command;

abstract class BaseCommand extends Command {
    /** @var ?KolideApiClient */
    protected $kolideApiClient;

    /** @var ?ApiServerClient */
    protected $apiServerClient;

    public function setKolideApiClient(KolideApiClient $client) : void {
        $this->kolideApiClient = $client;
    }

    public function setApiServerClient(ApiServerClient $client) : void {
        $this->apiServerClient = $client;
    }
}