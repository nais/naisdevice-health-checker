<?php declare(strict_types=1);
namespace Naisdevice\HealthChecker\Command;

use Naisdevice\HealthChecker\KolideApiClient;
use RuntimeException;
use Symfony\Component\Console\{
    Command\Command,
    Input\InputInterface,
    Input\InputOption,
    Output\OutputInterface,
};

class ListChecks extends BaseCommand {
    /** @var string */
    protected static $defaultName = 'kolide:list-checks';

    protected function configure() : void {
        $this
            ->setDescription('List Kolide checks as JSON')
            ->setHelp('This command will list all checks that is currently assigned to our account on Kolide in JSON format.')
            ->addOption('kolide-api-token', 't', InputOption::VALUE_REQUIRED, 'Token used with the Kolide API');
    }

    protected function initialize(InputInterface $input, OutputInterface $output) : void {
        if (null !== $this->kolideApiClient) {
            return;
        }

        if (empty($input->getOption('kolide-api-token'))) {
            throw new RuntimeException('Specity a token for the Kolide API using -t/--kolide-api-token');
        }

        $this->setKolideApiClient(new KolideApiClient($input->getOption('kolide-api-token')));
    }

    protected function execute(InputInterface $input, OutputInterface $output) : int {
        $output->writeln(json_encode($this->kolideApiClient->getAllChecks()));
        return Command::SUCCESS;
    }
}