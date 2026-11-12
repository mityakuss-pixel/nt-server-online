<?php

declare(strict_types=1);

namespace NT\ServerOnline\Source;

class Json extends AbstractHttpSource
{
	protected const OFFLINE_VALUES = ['', '0', 'false', 'off', 'offline', 'no', 'down', 'closed', 'stopped'];

	public function getDefaultPort(): int
	{
		return 0;
	}

	public function usesAddress(): bool
	{
		return false;
	}

	public function getRequestUrl(array $source): ?string
	{
		return $source['url'];
	}

	public function parse(array $data, array $source): array
	{
		$node = $this->getPath($data, $source['root']);
		if (!is_array($node))
		{
			return $this->failure(static::ERROR_FIELD, $source['root']);
		}

		$players = $this->getPath($node, $source['players']);
		if (is_array($players))
		{
			$players = count($players);
		}
		if (!is_numeric($players))
		{
			return $this->failure(static::ERROR_FIELD, $source['players']);
		}

		$max = $source['max'] !== '' ? $this->getPath($node, $source['max']) : null;
		$state = $source['state'] !== '' ? $this->getPath($node, $source['state']) : null;

		return $this->success(
			(int) $players,
			is_numeric($max) ? (int) $max : null,
			$source['name'] !== '' ? $this->getScalar($node, $source['name']) : null,
			$source['address'] !== '' ? $this->getScalar($node, $source['address']) : null,
			$state === null || $this->isOnline($state, $source['online_value'])
		);
	}

	protected function getPath(array $data, string $path)
	{
		$value = $data;
		foreach (explode('.', $path) AS $part)
		{
			if ($part === '')
			{
				continue;
			}
			if (!is_array($value) || !array_key_exists($part, $value))
			{
				return null;
			}

			$value = $value[$part];
		}

		return $value;
	}

	protected function getScalar(array $node, string $path): ?string
	{
		$value = $this->getPath($node, $path);
		if (!is_string($value) && !is_int($value) && !is_float($value))
		{
			return null;
		}

		return (string) $value;
	}

	protected function isOnline($state, string $onlineValue): bool
	{
		if (is_bool($state))
		{
			return $state;
		}
		if (!is_scalar($state))
		{
			return false;
		}

		$state = mb_strtolower(trim((string) $state));
		if ($onlineValue === '')
		{
			return !in_array($state, static::OFFLINE_VALUES, true);
		}

		foreach (explode(',', mb_strtolower($onlineValue)) AS $accepted)
		{
			if (trim($accepted) === $state)
			{
				return true;
			}
		}

		return false;
	}
}
