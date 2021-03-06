<?php declare(strict_types=1);
namespace Naisdevice\HealthChecker\Command;

use Naisdevice\HealthChecker\{
    Severity,
    KolideApiClient,
};
use RuntimeException;
use Symfony\Component\Console\{
    Command\Command,
    Input\InputInterface,
    Output\OutputInterface,
};

class ValidateKolideChecksSeverity extends BaseCommand {
    /** @var string */
    protected static $defaultName = 'kolide:validate-checks';

    protected function configure() : void {
        $this
            ->setDescription('Validate Kolide checks for missing severity tags')
            ->setHelp('Make sure we have set severity tags for all Kolide checks connected to our account');
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
        $checks   = $this->kolideApiClient->getAllChecks();
        $checkIds = array_column($checks, 'id');
        array_multisort($checkIds, SORT_ASC, $checks);
        $incompleteChecks = [];

        foreach ($checks as $check) {
            foreach ($check['tags'] as $tag) {
                if (Severity::isSeverityTag($tag)) {
                    continue 2;
                }
            }

            $incompleteChecks[] = $check;
        }

        if (!empty($incompleteChecks)) {
            $output->writeln('The following Kolide checks are missing a severity tag:');
            $output->writeln(array_map(
                fn(array $check) : string => sprintf(
                    '<info>%s</info> (ID: <info>%d</info>, https://k2.kolide.com/1401/checks/%2$d): %s',
                    $check['name'],
                    $check['id'],
                    $check['description']
                ),
                $incompleteChecks
            ));

            $output->writeln(sprintf('::set-output name=incomplete-checks::%s', json_encode($incompleteChecks)));

            return Command::FAILURE;
        }

        $output->writeln('All checks have been configured');

        return Command::SUCCESS;
    }
}