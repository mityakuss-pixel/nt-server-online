<?php

declare(strict_types=1);

namespace NT\ServerOnline\Source;

class Samp extends AbstractSocketSource
{
	public function getDefaultPort(): int
	{
		return 7777;
	}

	public function query(array $address, float $timeout): array
	{
		$ip = $this->resolveHost($address['host']);
		if (!$ip)
		{
			return $this->failure(static::ERROR_REQUEST, $this->lastError);
		}

		$header = 'SAMP' . implode('', array_map('chr', array_map('intval', explode('.', $ip)))) . pack('v', $address['port']);
		$data = $this->udpExchange($ip, $address['port'], $header . 'i', $timeout);
		if ($data === null)
		{
			return $this->failure(static::ERROR_REQUEST, $this->lastError);
		}
		if (strlen($data) < 20 || substr($data, 0, 4) !== 'SAMP' || $data[10] !== 'i')
		{
			return $this->failure(static::ERROR_RESPONSE);
		}

		$values = unpack('vplayers/vmax/Vlength', substr($data, 12, 8));
		if ($values['length'] > strlen($data) - 20)
		{
			return $this->failure(static::ERROR_RESPONSE);
		}

		return $this->success(
			$values['players'],
			$values['max'],
			substr($data, 20, $values['length']),
			$this->formatAddress($address)
		);
	}
}
