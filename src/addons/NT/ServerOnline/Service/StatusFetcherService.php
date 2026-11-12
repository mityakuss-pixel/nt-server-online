<?php

declare(strict_types=1);

namespace NT\ServerOnline\Service;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\Utils as PromiseUtils;
use GuzzleHttp\Psr7\Utils as StreamUtils;
use NT\ServerOnline\Repository\ServerStatusRepository;
use NT\ServerOnline\Source\AbstractHttpSource;
use NT\ServerOnline\Source\AbstractSocketSource;
use NT\ServerOnline\Source\AbstractSource;
use Psr\Http\Message\ResponseInterface;
use XF\Http\Stream;
use XF\Service\AbstractService;

class StatusFetcherService extends AbstractService
{
	protected const MAX_BYTES = 1048576;
	protected const SOCKET_BUDGET = 6.0;
	protected const SOCKET_MIN_TIME = 0.3;

	public function fetch(array $sources): array
	{
		$statusRepo = $this->repository(ServerStatusRepository::class);

		$results = [];
		$httpUrls = [];
		$httpSources = [];
		$socketSources = [];
		foreach ($sources AS $key => $source)
		{
			$handler = $statusRepo->getSourceHandler($source['type']);
			if ($handler instanceof AbstractHttpSource)
			{
				$url = $handler->getRequestUrl($source);
				if ($url === null)
				{
					$results[$key] = $this->failure(AbstractSource::ERROR_ADDRESS, $source['url']);
					continue;
				}

				$httpUrls[md5($url)] = $url;
				$httpSources[$key] = [$handler, md5($url)];
			}
			else if ($handler instanceof AbstractSocketSource)
			{
				$address = $handler->parseAddress($source['url']);
				if (!$address)
				{
					$results[$key] = $this->failure(AbstractSource::ERROR_ADDRESS, $source['url']);
					continue;
				}

				$socketSources[$key] = [$handler, $address];
			}
		}

		if ($httpUrls)
		{
			$responses = $this->request($httpUrls);
			foreach ($httpSources AS $key => [$handler, $hash])
			{
				$response = $responses[$hash];
				$results[$key] = $response['error']
					? $this->failure($response['error'], $response['detail'])
					: $handler->parse($response['data'], $sources[$key]);
			}
		}

		$timeout = $this->getTimeout();
		$deadline = microtime(true) + static::SOCKET_BUDGET;
		foreach ($socketSources AS $key => [$handler, $address])
		{
			$remaining = $deadline - microtime(true);
			if ($remaining < static::SOCKET_MIN_TIME)
			{
				break;
			}

			try
			{
				$results[$key] = $handler->query($address, min($timeout, $remaining));
			}
			catch (\Throwable $e)
			{
				$results[$key] = $this->failure(AbstractSource::ERROR_RESPONSE, $e->getMessage());
			}
		}

		return $results;
	}

	protected function getTimeout(): int
	{
		return max(1, (int) $this->app->options()->ntServerOnlineTimeout);
	}

	protected function request(array $urls): array
	{
		$timeout = $this->getTimeout();
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

			return $this->failure(AbstractSource::ERROR_REQUEST, (string) $reader->getErrorMessage($errorCode));
		}

		if ($outcome['state'] !== 'fulfilled' || !($outcome['value'] instanceof ResponseInterface))
		{
			$reason = $outcome['reason'] ?? null;

			return $this->failure(
				AbstractSource::ERROR_REQUEST,
				$reason instanceof \Throwable ? $reason->getMessage() : ''
			);
		}

		$response = $outcome['value'];
		$status = $response->getStatusCode();
		if ($status < 200 || $status >= 300)
		{
			return $this->failure(AbstractSource::ERROR_HTTP, (string) $status);
		}

		$body = (string) $response->getBody();
		if (strncmp($body, "\xEF\xBB\xBF", 3) === 0)
		{
			$body = substr($body, 3);
		}

		$data = json_decode($body, true);
		if (!is_array($data))
		{
			return $this->failure(AbstractSource::ERROR_JSON, '');
		}

		return ['error' => '', 'detail' => '', 'data' => $data];
	}

	protected function failure(string $error, string $detail): array
	{
		return [
			'error' => $error,
			'detail' => mb_substr($detail, 0, 200),
		];
	}
}
