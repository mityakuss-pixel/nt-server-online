<?php

declare(strict_types=1);

namespace NT\ServerOnline\Source;

class MinecraftBedrock extends AbstractSocketSource
{
	protected const MAGIC = "\x00\xFF\xFF\x00\xFE\xFE\xFE\xFE\xFD\xFD\xFD\xFD\x12\x34\x56\x78";
	protected const PONG = "\x1C";

	public function getDefaultPort(): int
	{
		return 19132;
	}

	public function query(array $address, float $timeout): array
	{
		$ip = $this->resolveHost($address['host']);
		if (!$ip)
		{
			return $this->failure(static::ERROR_REQUEST, $this->lastError);
		}

		$payload = "\x01" . pack('J', time()) . static::MAGIC . pack('J', 2);
		$data = $this->udpExchange($ip, $address['port'], $payload, $timeout);
		if ($data === null)
		{
			return $this->failure(static::ERROR_REQUEST, $this->lastError);
		}
		if (strlen($data) < 35 || $data[0] !== static::PONG || substr($data, 17, 16) !== static::MAGIC)
		{
			return $this->failure(static::ERROR_RESPONSE);
		}

		$info = explode(';', substr($data, 35));
		if (count($info) < 6 || !is_numeric($info[4]))
		{
			return $this->failure(static::ERROR_RESPONSE);
		}

		return $this->success(
			(int) $info[4],
			is_numeric($info[5]) ? (int) $info[5] : null,
			$this->stripMinecraftCodes($info[1]),
			$address['explicit_port'] ? $this->formatAddress($address) : $address['host']
		);
	}
}
