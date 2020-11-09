<?php declare(strict_types=1);
namespace Naisdevice\HealthChecker\Command;

use Naisdevice\HealthChecker\KolideApiClient;
use RuntimeException;
use Symfony\Component\Console\{
    Command\Command,
    Input\InputInterface,
    Output\OutputInterface,
};

class ListChecks extends BaseCommand {
    /** @var string */
    protected static $defaultName = 'kolide:list-checks';

    protected function configure() : void {
        $this
            ->setDescription('List Kolide checks as JSON')
            ->setHelp('This command will list all checks that is currently assigned to our account on Kolide in JSON format.');
    }

    protected function initialize(InputInterface $input, OutputInterface $output) : void {
        if (null !== $this->kolideApiClient) {
            return;
        }

        $kolideApiToken = $this->env('KOLIDE_API_TOKEN');

        if ('' === $kolideApiToken) {
            throw new RuntimeException('Specify a token for the Kolide API by setting the KOLIDE_API_TOKEN environment variable');
        }

        $this->setKolideApiClient(new KolideApiClient($kolideApiToken));
    }

    protected function execute(InputInterface $input, OutputInterface $output) : int {
        $output->writeln((string) json_encode($this->kolideApiClient->getAllChecks()));
        return Command::SUCCESS;
    }
}