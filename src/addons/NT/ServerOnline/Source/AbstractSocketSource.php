<?php

declare(strict_types=1);

namespace NT\ServerOnline\Source;

abstract class AbstractSocketSource extends AbstractSource
{
	protected const DATAGRAM_SIZE = 8192;

	protected $lastError = '';

	abstract public function query(array $address, float $timeout): array;

	public function getLastError(): string
	{
		return $this->lastError;
	}

	protected function resolveHost(string $host): ?string
	{
		if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))
		{
			return $host;
		}

		$ip = gethostbyname($host);
		if ($ip === $host || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))
		{
			$this->lastError = 'DNS: ' . $host;

			return null;
		}

		return $ip;
	}

	protected function openSocket(string $transport, string $ip, int $port, float $timeout)
	{
		if (!function_exists('stream_socket_client'))
		{
			$this->lastError = 'stream_socket_client is disabled';

			return null;
		}

		$errorCode = 0;
		$errorText = '';
		$socket = @stream_socket_client($transport . '://' . $ip . ':' . $port, $errorCode, $errorText, max(0.1, $timeout));
		if (!$socket)
		{
			$this->lastError = $errorText !== '' ? $errorText : 'socket error ' . $errorCode;

			return null;
		}

		stream_set_blocking($socket, false);

		return $socket;
	}

	protected function waitReadable($socket, float $deadline): bool
	{
		$remaining = $deadline - microtime(true);
		if ($remaining <= 0)
		{
			return false;
		}

		$read = [$socket];
		$write = null;
		$except = null;
		$seconds = (int) floor($remaining);
		$microseconds = (int) (($remaining - $seconds) * 1000000);

		return @stream_select($read, $write, $except, $seconds, $microseconds) > 0;
	}

	protected function readDatagram($socket, float $deadline): ?string
	{
		if (!$this->waitReadable($socket, $deadline))
		{
			$this->lastError = 'timeout';

			return null;
		}

		$data = @fread($socket, static::DATAGRAM_SIZE);
		if ($data === false || $data === '')
		{
			$this->lastError = 'no data';

			return null;
		}

		return $data;
	}

	protected function readBytes($socket, int $length, float $deadline): ?string
	{
		$data = '';
		while (strlen($data) < $length)
		{
			if (!$this->waitReadable($socket, $deadline))
			{
				$this->lastError = 'timeout';

				return null;
			}

			$chunk = @fread($socket, $length - strlen($data));
			if ($chunk === false || ($chunk === '' && feof($socket)))
			{
				$this->lastError = 'connection closed';

				return null;
			}

			$data .= $chunk;
		}

		return $data;
	}

	protected function udpExchange(string $ip, int $port, string $payload, float $timeout): ?string
	{
		$deadline = microtime(true) + $timeout;
		$socket = $this->openSocket('udp', $ip, $port, $timeout);
		if (!$socket)
		{
			return null;
		}

		@fwrite($socket, $payload);
		$data = $this->readDatagram($socket, $deadline);
		fclose($socket);

		return $data;
	}

	protected function readCString(string $data, int &$offset): ?string
	{
		$end = strpos($data, "\x00", $offset);
		if ($end === false)
		{
			return null;
		}

		$value = substr($data, $offset, $end - $offset);
		$offset = $end + 1;

		return $value;
	}

	protected function stripMinecraftCodes(string $text): string
	{
		return (string) preg_replace('/\xC2\xA7[0-9a-fk-orx]/i', '', $text);
	}
}
