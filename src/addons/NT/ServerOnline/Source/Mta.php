<?php

declare(strict_types=1);

namespace NT\ServerOnline\Source;

class Mta extends AbstractSocketSource
{
	protected const QUERY_PORT_OFFSET = 123;
	protected const FIELDS = ['game', 'port', 'name', 'type', 'map', 'version', 'password', 'players', 'max'];

	public function getDefaultPort(): int
	{
		return 22003;
	}

	public function query(array $address, float $timeout): array
	{
		$ip = $this->resolveHost($address['host']);
		if (!$ip)
		{
			return $this->failure(static::ERROR_REQUEST, $this->lastError);
		}

		$queryPort = $address['port'] + static::QUERY_PORT_OFFSET;
		if ($queryPort > 65535)
		{
			return $this->failure(static::ERROR_ADDRESS, $this->formatAddress($address));
		}

		$data = $this->udpExchange($ip, $queryPort, 's', $timeout);
		if ($data === null)
		{
			return $this->failure(static::ERROR_REQUEST, $this->lastError);
		}
		if (substr($data, 0, 4) !== 'EYE1')
		{
			return $this->failure(static::ERROR_RESPONSE);
		}

		$offset = 4;
		$values = [];
		foreach (static::FIELDS AS $field)
		{
			if (!isset($data[$offset]))
			{
				return $this->failure(static::ERROR_RESPONSE);
			}

			$length = ord($data[$offset]);
			if ($length < 1 || $offset + $length > strlen($data))
			{
				return $this->failure(static::ERROR_RESPONSE);
			}

			$values[$field] = substr($data, $offset + 1, $length - 1);
			$offset += $length;
		}
		if (!is_numeric($values['players']))
		{
			return $this->failure(static::ERROR_RESPONSE);
		}

		return $this->success(
			(int) $values['players'],
			is_numeric($values['max']) ? (int) $values['max'] : null,
			$values['name'],
			$this->formatAddress($address)
		);
	}
}
