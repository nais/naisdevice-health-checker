<?php declare(strict_types=1);
namespace Naisdevice\HealthChecker\Command;

use Naisdevice\HealthChecker\{
    ApiServerClient,
    KolideApiClient,
};
use Symfony\Component\Console\Command\Command;

abstract class BaseCommand extends Command {
    protected ?KolideApiClient $kolideApiClient = null;
    protected ?ApiServerClient $apiServerClient = null;

    public function setKolideApiClient(KolideApiClient $client) : void {
        $this->kolideApiClient = $client;
    }

    public function setApiServerClient(ApiServerClient $client) : void {
        $this->apiServerClient = $client;
    }

    /**
     * Get an environment variable
     *
     * @param string $name
     * @return string
     */
    protected function env(string $name) : string {
        return trim((string) getenv($name));
    }
}