<?php declare(strict_types=1);
namespace Naisdevice\HealthChecker\Exception;

use RuntimeException;

class MissingKolideDeviceException extends RuntimeException implements HealthCheckerException {}