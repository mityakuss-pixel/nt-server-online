<?php

declare(strict_types=1);

namespace NT\ServerOnline\Service;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\Utils as PromiseUtils;
use GuzzleHttp\Psr7\Utils as StreamUtils;
use Psr\Http\Message\ResponseInterface;
use XF\Http\Stream;
use XF\Service\AbstractService;

class StatusFetcherService extends AbstractService
{
	public const ERROR_REQUEST = 'request';
	public const ERROR_HTTP = 'http';
	public const ERROR_JSON = 'json';
	public const ERROR_FIELD = 'field';

	protected const MAX_BYTES = 1048576;
	protected const MAX_TEXT_LENGTH = 100;
	protected const OFFLINE_VALUES = ['', '0', 'false', 'off', 'offline', 'no', 'down', 'closed', 'stopped'];

	public function fetch(array $sources): array
	{
		$responses = $this->request($this->getUrls($sources));

		$results = [];
		foreach ($sources AS $key => $source)
		{
			$response = $responses[md5($source['url'])];
			$results[$key] = $response['error']
				? $this->failure($response['error'], $response['detail'])
				: $this->extract($response['data'], $source);
		}

		return $results;
	}

	protected function getUrls(array $sources): array
	{
		$urls = [];
		foreach ($sources AS $source)
		{
			$urls[md5($source['url'])] = $source['url'];
		}

		return $urls;
	}

	protected function request(array $urls): array
	{
		$timeout = max(1, (int) $this->app->options()->ntServerOnlineTimeout);
		$client = $this->app->http()->client();

		$promises = [];
		$sinks = [];
		foreach ($urls AS $hash => $url)
		{
			$sinks[$hash] = new Stream(
				StreamUtils::streamFor(fopen('php://temp', 'w+')),
				static::MAX_BYTES,
				$timeout
			);

			try
			{
				$promises[$hash] = $client->getAsync($url, [
					'sink' => $sinks[$hash],
					'timeout' => $timeout + 1,
					'connect_timeout' => $timeout,
					'http_errors' => false,
					'decode_content' => 'identity',
					'headers' => ['Accept' => 'application/json'],
				]);
			}
			catch (\Throwable $e)
			{
				$promises[$hash] = Create::rejectionFor($e);
			}
		}

		$responses = [];
		foreach (PromiseUtils::settle($promises)->wait() AS $hash => $outcome)
		{
			$responses[$hash] = $this->readOutcome($outcome, $sinks[$hash]);
		}

		return $responses;
	}

	protected function readOutcome(array $outcome, Stream $sink): array
	{
		if ($sink->hasError($errorCode))
		{
			$reader = $this->app->http()->reader();

			return $this->failure(static::ERROR_REQUEST, (string) $reader->getErrorMessage($errorCode));
		}

		if ($outcome['state'] !== 'fulfilled' || !($outcome['value'] instanceof ResponseInterface))
		{
			$reason = $outcome['reason'] ?? null;

			return $this->failure(
				static::ERROR_REQUEST,
				$reason instanceof \Throwable ? $reason->getMessage() : ''
			);
		}

		$response = $outcome['value'];
		$status = $response->getStatusCode();
		if ($status < 200 || $status >= 300)
		{
			return $this->failure(static::ERROR_HTTP, (string) $status);
		}

		$body = (string) $response->getBody();
		if (strncmp($body, "\xEF\xBB\xBF", 3) === 0)
		{
			$body = substr($body, 3);
		}

		$data = json_decode($body, true);
		if (!is_array($data))
		{
			return $this->failure(static::ERROR_JSON, '');
		}

		return ['error' => '', 'detail' => '', 'data' => $data];
	}

	protected function extract(array $data, array $source): array
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

		return [
			'error' => '',
			'detail' => '',
			'players' => max(0, (int) $players),
			'max' => is_numeric($max) && $max > 0 ? (int) $max : null,
			'name' => $source['name'] !== '' ? $this->getText($node, $source['name']) : null,
			'address' => $source['address'] !== '' ? $this->getText($node, $source['address']) : null,
			'online' => $state === null || $this->isOnline($state, $source['online_value']),
		];
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

	protected function getText(array $node, string $path): ?string
	{
		$value = $this->getPath($node, $path);
		if (!is_string($value) && !is_int($value) && !is_float($value))
		{
			return null;
		}

		$value = trim((string) $value);
		if ($value === '')
		{
			return null;
		}

		return mb_substr($value, 0, static::MAX_TEXT_LENGTH);
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

	protected function failure(string $error, string $detail): array
	{
		return [
			'error' => $error,
			'detail' => mb_substr($detail, 0, 200),
		];
	}
}
