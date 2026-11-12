<?php
declare(strict_types=1);

namespace BusherianBiasharas\Config;

use RuntimeException;

/**
 * Thrown when the primary database cannot be reached and fail-closed semantics apply (#169).
 */
final class DatabaseUnavailableException extends RuntimeException
{
}
