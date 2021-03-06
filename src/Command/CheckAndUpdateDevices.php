<?php declare(strict_types=1);
namespace Naisdevice\HealthChecker\Command;

use DateTime;
use Naisdevice\HealthChecker\{
    ApiServerClient,
    Exception\HealthCheckerException,
    Exception\MissingKolideDeviceException,
    Exception\MultipleKolideDevicesException,
    KolideApiClient,
    Severity,
};
use RuntimeException;
use Symfony\Component\Console\{
    Input\InputInterface,
    Input\InputOption,
    Output\OutputInterface,
    Command\Command,
};

class CheckAndUpdateDevices extends BaseCommand {
    /** @var string */
    protected static $defaultName = 'apiserver:update-devices';

    protected function configure() : void {
        $this
            ->setDescription('Update health status of Nais devices')
            ->setHelp('This command will update the health status of all Nais devices based on data from the Kolide API.')
            ->addOption('ignore-checks', 'i', InputOption::VALUE_IS_ARRAY|InputOption::VALUE_OPTIONAL, 'List of check IDs to ignore', []);
    }

    protected function initialize(InputInterface $input, OutputInterface $output) : void {
        $kolideApiToken    = $this->env('KOLIDE_API_TOKEN');
        $apiserverUsername = $this->env('APISERVER_USERNAME') ?: 'device-health-checker';
        $apiserverPassword = $this->env('APISERVER_PASSWORD');

        if (null === $this->kolideApiClient && '' === $kolideApiToken) {
            throw new RuntimeException('Specify a token for the Kolide API by setting the KOLIDE_API_TOKEN environment variable');
        } else if (null === $this->apiServerClient && '' === $apiserverUsername) {
            throw new RuntimeException('Specify a username for the API server by setting the APISERVER_USERNAME environment variable');
        } else if (null === $this->apiServerClient && '' === $apiserverPassword) {
            throw new RuntimeException('Specify a password for the API server by setting the APISERVER_PASSWORD environment variable');
        }

        if (null === $this->kolideApiClient) {
            $this->setKolideApiClient(new KolideApiClient($kolideApiToken));
        }

        if (null === $this->apiServerClient) {
            $this->setApiServerClient(new ApiServerClient($apiserverUsername, $apiserverPassword));
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output) : int {
        // Force our own exception handler from here on
        $this->getApplication()->setCatchExceptions(false);

        /** @var array<int> */
        $ignoreCheckIds = $input->getOption('ignore-checks');

        $ignoreChecks = array_unique(array_map('intval', $ignoreCheckIds));
        $naisDevices = array_map(fn(array $device) : array => [
            'serial'          => $device['serial'],
            'platform'        => $device['platform'],
            'username'        => $device['username'],
            'isHealthy'       => $device['isHealthy'],
            'kolideLastSeen'  => $device['kolideLastSeen'],
        ], $this->apiServerClient->getDevices());

        /** @var array<int,array{id:int,serial:string,platform:string,assigned_owner:array{email:string},last_seen_at:string,failure_count:int}> */
        $kolideDevices = $this->kolideApiClient->getAllDevices();
        $updatedNaisDevices = [];

        foreach ($naisDevices as $naisDevice) {
            $failingChecks     = [];
            $username          = $naisDevice['username'];
            $serial            = $naisDevice['serial'];
            $platform          = $naisDevice['platform'];

            try {
                $kolideDevice = $this->identifyKolideDevice($username, $serial, $platform, $kolideDevices);
            } catch (HealthCheckerException $e) {
                $this->log($output, $e->getMessage(), $serial, $platform, $username);
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
     * @param array<int,array{id:int,serial:string,platform:string,assigned_owner:array{email:string},last_seen_at:string,failure_count:int}> $kolideDevices
     * @throws HealthCheckerException
     * @return array{id:int,serial:string,platform:string,assigned_owner:array{email:string},last_seen_at:string,failure_count:int} Returns the matching Kolide device
     */
    private function identifyKolideDevice(string $username, string $serial, string $platform, array $kolideDevices) : array {
        $devices = array_values(array_filter($kolideDevices, function(array $kolideDevice) use ($username, $serial, $platform) : bool {
            // Currently we only have darwin, windows or linux as possible platforms in the
            // apiserver, so if the Kolide device is not windows or darwin, assume linux.
            if (!in_array($kolideDevice['platform'], ['windows', 'darwin'])) {
                $kolideDevice['platform'] = 'linux';
            }

            if (empty($kolideDevice['assigned_owner']['email'])) {
                return false;
            }

            return
                strtolower($username) === strtolower($kolideDevice['assigned_owner']['email'])
                && strtolower($serial) === strtolower($kolideDevice['serial'])
                && strtolower($platform) === strtolower($kolideDevice['platform']);
        }));

        $numKolideDevices = count($devices);

        if (1 < $numKolideDevices) {
            throw new MultipleKolideDevicesException(sprintf('Found %d matching devices in Kolide', $numKolideDevices));
        } else if (0 === $numKolideDevices) {
            throw new MissingKolideDeviceException('Did not find any matching device in Kolide');
        }

        return $devices[0];
    }

    /**
     * Check if a device is currently failing
     *
     * @param int $deviceId ID of the device at Kolide
     * @param array<int> $ignoreChecks A list of check IDs to ignore
     * @return array<int,array{check_id:int,timestamp:string}>
     */
    private function getFailingDeviceChecks(int $deviceId, array $ignoreChecks = []) : array {
        /** @var array<int,array{check_id:int,resolved_at:?string,timestamp:string}> */
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

            $graceTime = Severity::getGraceTime($this->kolideApiClient->getCheck($failure['check_id'])['tags'] ?? []);

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
     * @return void
     */
    private function log(OutputInterface $output, string $message, string $serial = null, string $platform = null, string $username = null) : void {
        $output->writeln((string) json_encode(array_filter([
            'component'     => 'naisdevice-health-checker',
            'system'        => 'naisdevice',
            'message'       => $message,
            'serial'        => $serial,
            'platform'      => $platform,
            'username'      => $username,
            'level'         => 'info',
            'timestamp'     => time(),
        ])));
    }
}
