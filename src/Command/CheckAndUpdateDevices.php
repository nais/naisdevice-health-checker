<?php declare(strict_types=1);
namespace Nais\Device\Command;

use Nais\Device\ApiServerClient;
use Nais\Device\Severity;
use Nais\Device\KolideApiClient;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use DateTime;
use RuntimeException;

class CheckAndUpdateDevices extends BaseCommand {
    /** @var string */
    protected static $defaultName = 'apiserver:update-devices';

    protected function configure() : void {
        $this
            ->setDescription('Update health status of Nais devices')
            ->setHelp('This command will update the health status of all Nais devices based on data from the Kolide API.')
            ->addOption('kolide-api-token', 't', InputOption::VALUE_REQUIRED, 'Token used with the Kolide API')
            ->addOption('ignore-checks', 'i', InputOption::VALUE_IS_ARRAY|InputOption::VALUE_OPTIONAL, 'List of check IDs to ignore', [])
            ->addOption('apiserver-username', 'u', InputOption::VALUE_OPTIONAL, 'Username used for API server authentication (basic auth)', 'device-health-checker')
            ->addOption('apiserver-password', 'p', InputOption::VALUE_REQUIRED, 'Password used for API server authentication (basic auth)');
    }

    protected function initialize(InputInterface $input, OutputInterface $output) : void {
        if (null === $this->kolideApiClient && empty($input->getOption('kolide-api-token'))) {
            throw new RuntimeException('Specity a token for the Kolide API using -t/--kolide-api-token');
        } else if (null === $this->apiServerClient && empty($input->getOption('apiserver-username'))) {
            throw new RuntimeException('Specity a username for the API serveer using -u/--apiserver-username');
        } else if (null === $this->apiServerClient && empty($input->getOption('apiserver-password'))) {
            throw new RuntimeException('Specity a password for the API serveer using -p/--apiserver-password');
        }

        if (null === $this->kolideApiClient) {
            $this->setKolideApiClient(new KolideApiClient($input->getOption('kolide-api-token')));
        }

        if (null === $this->apiServerClient) {
            $this->setApiServerClient(new ApiServerClient($input->getOption('apiserver-username'), $input->getOption('apiserver-password')));
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output) : int {
        // Force our own exception handler from here on
        $this->getApplication()->setCatchExceptions(false);

        $ignoreChecks = array_unique(array_map('intval', $input->getOption('ignore-checks')));
        $naisDevices = array_map(fn(array $device) : array => [
            'serial'          => $device['serial'],
            'platform'        => $device['platform'],
            'username'        => $device['username'],
            'isHealthy'       => $device['isHealthy'],
            'kolideLastSeen'  => $device['kolideLastSeen'],
        ], $this->apiServerClient->getDevices());
        $kolideDevices = $this->kolideApiClient->getAllDevices();
        $this->log($output, 'Fetched devices from Kolide', null, null, null, $kolideDevices);
        $updatedNaisDevices = [];

        foreach ($naisDevices as $naisDevice) {
            $failingChecks     = [];
            $username          = $naisDevice['username'];
            $serial            = $naisDevice['serial'];
            $platform          = $naisDevice['platform'];
            $kolideDevice      = $this->identifyKolideDevice($username, $serial, $platform, $kolideDevices);

            if (null === $kolideDevice) {
                $this->log($output, 'Could not find matching Kolide device', $serial, $platform, $username);
                continue;
            }

            $naisDevice['kolideLastSeen'] = $kolideDevice['last_seen_at'] ? strtotime($kolideDevice['last_seen_at']) : null;

            if ($kolideDevice['failure_count']) {
                $failingChecks = $this->getFailingDeviceChecks($kolideDevice['id'], $ignoreChecks);
            }

            $isHealthy = 0 === count($failingChecks);

            if ($isHealthy && !$naisDevice['isHealthy']) {
                $this->log($output, 'No failing checks anymore, device is now healthy', $serial, $platform, $username);
            } else if (!$isHealthy && $naisDevice['isHealthy']) {
                $failingChecks = array_map(fn(array $check) : string => $check['title'], $failingChecks);

                $this->log(
                    $output,
                    sprintf('Device is no longer healthy because of the following failing check(s): %s', join(', ', $failingChecks)),
                    $serial, $platform, $username
                );
            }

            $naisDevice['isHealthy'] = $isHealthy;
            $updatedNaisDevices[] = $naisDevice;
        }

        if (empty($updatedNaisDevices)) {
            $this->log($output, 'No Nais devices to update :(');
            return Command::SUCCESS;
        }

        $this->apiServerClient->updateDevices($updatedNaisDevices);
        $this->log($output, 'Sent updated Nais device configuration to API server');

        return Command::SUCCESS;
    }

    /**
     * Identify a Kolide device for a given serial and platform
     *
     * Return the matching Kolide device. If multiple or no devices are found, return null.
     *
     * @param string $username
     * @param string $serial
     * @param string $platform
     * @param array $kolideDevices
     * @return ?array Returns null if no Kolide device matches
     */
    private function identifyKolideDevice(string $username, string $serial, string $platform, array $kolideDevices) : ?array {
        $devices = array_values(array_filter($kolideDevices, function(array $kolideDevice) use ($username, $serial, $platform) : bool {
            // Currently we only have darwin, windows or linux as possible platforms in the
            // apiserver, so if the Kolide device is not windows or darwin, assume linux.
            if (!in_array($kolideDevice['platform'], ['windows', 'darwin'])) {
                $kolideDevice['platform'] = 'linux';
            }

            if is_null($kolideDevice['assigned_owner']) || is_null($kolideDevice['assigned_owner']['email']) {
              return false
            }

            return
                strtolower($username) === strtolower($kolideDevice['assigned_owner']['email'])
                && strtolower($serial) === strtolower($kolideDevice['serial'])
                && strtolower($platform) === strtolower($kolideDevice['platform']);
        }));

        if (1 === count($devices)) {
            return $devices[0];
        }

        return null;
    }

    /**
     * Check if a device is currently failing
     *
     * @param int $deviceId ID of the device at Kolide
     * @param array $ignoreChecks A list of check IDs to ignore
     * @return array
     */
    private function getFailingDeviceChecks(int $deviceId, array $ignoreChecks = []) : array {
        $failures = $this->kolideApiClient->getDeviceFailures($deviceId);
        $failingChecks = [];

        foreach ($failures as $failure) {
            if (null !== $failure['resolved_at']) {
                // Failure has been resolved
                continue;
            }

            if (in_array($failure['check_id'], $ignoreChecks)) {
                continue;
            }

            $tags = $this->kolideApiClient->getCheck($failure['check_id'])['tags'];
            $graceTime = Severity::getGraceTime($tags);

            if (Severity::INFO === $graceTime) {
                continue;
            }

            $occurredAt = (new DateTime($failure['timestamp']))->getTimestamp();

            if (Severity::CRITICAL === $graceTime || ((time() - $occurredAt) > $graceTime)) {
                $failingChecks[] = $failure;
            }
        }

        return $failingChecks;
    }

    /**
     * Output a log message in JSON
     *
     * @param OutputInterface $output
     * @param string $message
     * @param string $serial
     * @param string $platform
     * @param string $username
     * @param array  $kolideDevices
     * @return void
     */
    private function log(OutputInterface $output, string $message, string $serial = null, string $platform = null, string $username = null, array $kolideDevices = null) : void {
        $output->writeln(json_encode(array_filter([
            'component'     => 'device-health-checker',
            'system'        => 'nais-device',
            'message'       => $message,
            'serial'        => $serial,
            'platform'      => $platform,
            'username'      => $username,
            'level'         => 'info',
            'timestamp'     => time(),
            'kolideDevices' => $kolideDevices
        ])));
    }
}
