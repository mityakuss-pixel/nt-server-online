<?php

declare(strict_types=1);

namespace NT\ServerOnline\Source;

class MinecraftJava extends AbstractSocketSource
{
	protected const MAX_PACKET = 524288;
	protected const PROTOCOL_VERSION = 47;

	public function getDefaultPort(): int
	{
		return 25565;
	}

	public function query(array $address, float $timeout): array
	{
		$deadline = microtime(true) + $timeout;
		$target = $this->resolveService($address);

		$ip = $this->resolveHost($target['host']);
		if (!$ip)
		{
			return $this->failure(static::ERROR_REQUEST, $this->lastError);
		}

		$socket = $this->openSocket('tcp', $ip, $target['port'], max(0.1, $deadline - microtime(true)));
		if (!$socket)
		{
			return $this->failure(static::ERROR_REQUEST, $this->lastError);
		}

		$handshake = "\x00"
			. $this->writeVarInt(static::PROTOCOL_VERSION)
			. $this->writeVarInt(strlen($address['host'])) . $address['host']
			. pack('n', $target['port'])
			. $this->writeVarInt(1);
		@fwrite($socket, $this->writeVarInt(strlen($handshake)) . $handshake . "\x01\x00");

		$json = $this->readStatus($socket, $deadline);
		fclose($socket);

		if ($json === null)
		{
			return $this->failure($this->lastError === 'response' ? static::ERROR_RESPONSE : static::ERROR_REQUEST, $this->lastError);
		}

		$data = json_decode($json, true);
		if (!is_array($data) || !isset($data['players']['online']) || !is_numeric($data['players']['online']))
		{
			return $this->failure(static::ERROR_RESPONSE);
		}

		$name = $this->flattenText($data['description'] ?? '');
		$name = trim((string) strtok($this->stripMinecraftCodes($name), "\n"));

		return $this->success(
			(int) $data['players']['online'],
			isset($data['players']['max']) && is_numeric($data['players']['max']) ? (int) $data['players']['max'] : null,
			$name,
			$address['explicit_port'] ? $this->formatAddress($address) : $address['host']
		);
	}

	protected function resolveService(array $address): array
	{
		$target = ['host' => $address['host'], 'port' => $address['port']];
		if ($address['explicit_port'] || filter_var($address['host'], FILTER_VALIDATE_IP) || !function_exists('dns_get_record'))
		{
			return $target;
		}

		$records = @dns_get_record('_minecraft._tcp.' . $address['host'], DNS_SRV);
		if (is_array($records) && !empty($records[0]['target']) && !empty($records[0]['port']))
		{
			$target['host'] = strtolower(rtrim((string) $records[0]['target'], '.'));
			$target['port'] = (int) $records[0]['port'];
		}

		return $target;
	}

	protected function readStatus($socket, float $deadline): ?string
	{
		$length = $this->readVarInt($socket, $deadline);
		if ($length === null)
		{
			return null;
		}
		if ($length < 3 || $length > static::MAX_PACKET)
		{
			$this->lastError = 'response';

			return null;
		}

		$packet = $this->readBytes($socket, $length, $deadline);
		if ($packet === null)
		{
			return null;
		}

		$offset = 0;
		$packetId = $this->parseVarInt($packet, $offset);
		$jsonLength = $this->parseVarInt($packet, $offset);
		if ($packetId !== 0 || $jsonLength === null || $jsonLength > strlen($packet) - $offset)
		{
			$this->lastError = 'response';

			return null;
		}

		return substr($packet, $offset, $jsonLength);
	}

	protected function flattenText($component): string
	{
		if (is_string($component))
		{
			return $component;
		}
		if (!is_array($component))
		{
			return '';
		}

		$text = isset($component['text']) && is_string($component['text']) ? $component['text'] : '';
		foreach ((array) ($component['extra'] ?? []) AS $extra)
		{
			$text .= $this->flattenText($extra);
		}

		return $text;
	}

	protected function writeVarInt(int $value): string
	{
		$output = '';
		$value &= 0xFFFFFFFF;
		do
		{
			$byte = $value & 0x7F;
			$value >>= 7;
			$output .= chr($value ? $byte | 0x80 : $byte);
		}
		while ($value);

		return $output;
	}

	protected function readVarInt($socket, float $deadline): ?int
	{
		$value = 0;
		for ($shift = 0; $shift < 35; $shift += 7)
		{
			$byte = $this->readBytes($socket, 1, $deadline);
			if ($byte === null)
			{
				return null;
			}

			$number = ord($byte);
			$value |= ($number & 0x7F) << $shift;
			if (!($number & 0x80))
			{
				return $value;
			}
		}

		$this->lastError = 'response';

		return null;
	}

	protected function parseVarInt(string $data, int &$offset): ?int
	{
		$value = 0;
		for ($shift = 0; $shift < 35; $shift += 7)
		{
			if (!isset($data[$offset]))
			{
				return null;
			}

			$number = ord($data[$offset++]);
			$value |= ($number & 0x7F) << $shift;
			if (!($number & 0x80))
			{
				return $value;
			}
		}

		return null;
	}
}
