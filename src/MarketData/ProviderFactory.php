<?php

declare(strict_types=1);

namespace BigStocks\MarketData;

use BigStocks\APIS\Massive;
use RuntimeException;

final class ProviderFactory
{
    public static function create(string $provider): Massive
    {
        return match (strtolower($provider)) {
            'massive' => new Massive(self::requiredEnv('MASSIVE_API_KEY')),
            default => throw new RuntimeException(
                sprintf('Unknown market-data provider "%s". Available: massive', $provider),
            ),
        };
    }

    private static function requiredEnv(string $key, mixed $default = null): mixed
    {
	global $_ENV;

	foreach ([$key, 'ENV_' . $key] as $name) {
        	$value = getenv($name);

	        if ($value !== false && $value !== '') {
        	    return $value;
	        }

	        $value = $_ENV[$name] ?? null;

	        if (is_string($value) && $value !== '') {
        	    return $value;
	        }
	}

	if (is_null($default)) {
		throw new RuntimeException(sprintf(
                	'Required environment variable %s is not set and no default was passed',
	                $key
		));
	}

	return null;
    }
}
