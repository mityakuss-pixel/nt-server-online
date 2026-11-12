<?php

declare(strict_types=1);

namespace NT\ServerOnline\Source;

abstract class AbstractSource
{
	public const ERROR_REQUEST = 'request';
	public const ERROR_HTTP = 'http';
	public const ERROR_JSON = 'json';
	public const ERROR_FIELD = 'field';
	public const ERROR_ADDRESS = 'address';
	public const ERROR_RESPONSE = 'response';

	protected const MAX_TEXT_LENGTH = 100;
	protected const MAX_DETAIL_LENGTH = 200;
	protected const CYRILLIC_MIN_BYTES = 3;

	abstract public function getDefaultPort(): int;

	public function usesAddress(): bool
	{
		return true;
	}

	public function parseAddress(string $address): ?array
	{
		$address = trim($address);
		$address = preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $address);
		$address = rtrim((string) $address, '/');

		if (!preg_match('/^([a-z0-9](?:[a-z0-9.-]*[a-z0-9])?)(?::(\d{1,5}))?$/i', $address, $match))
		{
			return null;
		}

		$port = isset($match[2]) ? (int) $match[2] : 0;
		if ($port < 0 || $port > 65535)
		{
			return null;
		}

		return [
			'host' => strtolower($match[1]),
			'port' => $port ?: $this->getDefaultPort(),
			'explicit_port' => $port > 0,
		];
	}

	public function formatAddress(array $address): string
	{
		return $address['host'] . ':' . $address['port'];
	}

	protected function success(int $players, ?int $max, ?string $name, ?string $address, bool $online = true): array
	{
		return [
			'error' => '',
			'detail' => '',
			'players' => max(0, $players),
			'max' => $max !== null && $max > 0 ? $max : null,
			'name' => $this->cleanText($name),
			'address' => $this->cleanText($address),
			'online' => $online,
		];
	}

	protected function failure(string $error, string $detail = ''): array
	{
		return [
			'error' => $error,
			'detail' => mb_substr($this->toUtf8($detail), 0, static::MAX_DETAIL_LENGTH),
		];
	}

	protected function cleanText(?string $text): ?string
	{
		if ($text === null)
		{
			return null;
		}

		$text = $this->toUtf8($text);
		$text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text);
		$text = trim(preg_replace('/\s+/u', ' ', (string) $text));
		if ($text === '')
		{
			return null;
		}

		return mb_substr($text, 0, static::MAX_TEXT_LENGTH);
	}

	protected function toUtf8(string $text): string
	{
		if (mb_check_encoding($text, 'UTF-8'))
		{
			return $text;
		}

		$encoding = preg_match_all('/[\x80-\xFF]/', $text) >= static::CYRILLIC_MIN_BYTES ? 'Windows-1251' : 'Windows-1252';

		return mb_convert_encoding($text, 'UTF-8', $encoding);
	}
}
