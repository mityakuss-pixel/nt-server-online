<?php

declare(strict_types=1);

namespace NT\ServerOnline\Source;

class SourceEngine extends AbstractSocketSource
{
	protected const HEADER = "\xFF\xFF\xFF\xFF";
	protected const REQUEST = "\xFF\xFF\xFF\xFFTSource Engine Query\x00";
	protected const MAX_CHALLENGES = 2;

	public function getDefaultPort(): int
	{
		return 27015;
	}

	public function query(array $address, float $timeout): array
	{
		$ip = $this->resolveHost($address['host']);
		if (!$ip)
		{
			return $this->failure(static::ERROR_REQUEST, $this->lastError);
		}

		$deadline = microtime(true) + $timeout;
		$socket = $this->openSocket('udp', $ip, $address['port'], $timeout);
		if (!$socket)
		{
			return $this->failure(static::ERROR_REQUEST, $this->lastError);
		}

		$challenge = '';
		$data = null;
		for ($attempt = 0; $attempt <= static::MAX_CHALLENGES; $attempt++)
		{
			@fwrite($socket, static::REQUEST . $challenge);
			$data = $this->readDatagram($socket, $deadline);
			if ($data === null)
			{
				break;
			}
			if (strlen($data) >= 9 && substr($data, 0, 5) === static::HEADER . 'A')
			{
				$challenge = substr($data, 5, 4);
				$data = null;
				continue;
			}

			break;
		}
		fclose($socket);

		if ($data === null)
		{
			return $this->failure(static::ERROR_REQUEST, $this->lastError);
		}
		if (strlen($data) < 6 || substr($data, 0, 4) !== static::HEADER)
		{
			return $this->failure(static::ERROR_RESPONSE);
		}

		$type = $data[4];
		$offset = 5;
		if ($type === 'I')
		{
			$offset++;
			$name = $this->readCString($data, $offset);
		}
		else if ($type === 'm')
		{
			$this->readCString($data, $offset);
			$name = $this->readCString($data, $offset);
		}
		else
		{
			return $this->failure(static::ERROR_RESPONSE);
		}

		$map = $this->readCString($data, $offset);
		$folder = $this->readCString($data, $offset);
		$game = $this->readCString($data, $offset);
		if ($name === null || $map === null || $folder === null || $game === null)
		{
			return $this->failure(static::ERROR_RESPONSE);
		}
		if ($type === 'I')
		{
			$offset += 2;
		}
		if (strlen($data) < $offset + 2)
		{
			return $this->failure(static::ERROR_RESPONSE);
		}

		return $this->success(ord($data[$offset]), ord($data[$offset + 1]), $name, $this->formatAddress($address));
	}
}
