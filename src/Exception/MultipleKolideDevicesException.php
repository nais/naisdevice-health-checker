<?php declare(strict_types=1);
namespace Naisdevice\HealthChecker\Exception;

use RuntimeException;

class MultipleKolideDevicesException extends RuntimeException implements HealthCheckerException {}