<?php

declare(strict_types=1);

namespace NT\ServerOnline\Repository;

use NT\ServerOnline\Service\StatusFetcherService;
use NT\ServerOnline\Source\AbstractSource;
use NT\ServerOnline\Source\FiveM;
use NT\ServerOnline\Source\Json;
use NT\ServerOnline\Source\MinecraftBedrock;
use NT\ServerOnline\Source\MinecraftJava;
use NT\ServerOnline\Source\Mta;
use NT\ServerOnline\Source\Samp;
use NT\ServerOnline\Source\SourceEngine;
use XF\Finder\WidgetFinder;
use XF\Mvc\Entity\Repository;

class ServerStatusRepository extends Repository
{
	public const ADDON_ID = 'NT/ServerOnline';
	public const WIDGET_DEFINITION = 'nt_server_online';
	public const CACHE_KEY = 'servers';
	public const MAX_SERVERS = 20;
	public const TYPE_JSON = 'json';
	public const TYPES = [
		'json' => Json::class,
		'source' => SourceEngine::class,
		'minecraft' => MinecraftJava::class,
		'bedrock' => MinecraftBedrock::class,
		'samp' => Samp::class,
		'mta' => Mta::class,
		'fivem' => FiveM::class,
	];

	protected const MIN_INTERVAL = 60;
	protected const MIN_GRACE = 180;
	protected const STALE_AFTER = 900;
	protected const CRON_DRIFT = 5;

	protected $sourceHandlers = [];

	public function getSourceHandler(string $type): AbstractSource
	{
		if (!isset(static::TYPES[$type]))
		{
			$type = static::TYPE_JSON;
		}
		if (!isset($this->sourceHandlers[$type]))
		{
			$class = $this->app()->extendClass(static::TYPES[$type]);
			$this->sourceHandlers[$type] = new $class();
		}

		return $this->sourceHandlers[$type];
	}

	public function getServerType(array $server): string
	{
		$type = (string) ($server['type'] ?? '');

		return isset(static::TYPES[$type]) ? $type : static::TYPE_JSON;
	}

	public function getSources(array $options): array
	{
		$sources = [];
		foreach ($options['servers'] ?? [] AS $server)
		{
			$url = trim((string) ($server['url'] ?? ''));
			if ($url === '')
			{
				continue;
			}

			$type = $this->getServerType($server);
			if ($type === static::TYPE_JSON)
			{
				$source = [
					'url' => $url,
					'root' => trim((string) ($server['root'] ?? '')),
					'players' => trim((string) ($options['path_players'] ?? '')),
					'max' => trim((string) ($options['path_max'] ?? '')),
					'name' => trim((string) ($options['path_name'] ?? '')),
					'state' => trim((string) ($options['path_state'] ?? '')),
					'online_value' => trim((string) ($options['state_online'] ?? '')),
					'address' => trim((string) ($options['path_address'] ?? '')),
				];
				$source['key'] = md5(json_encode(array_values($source)));
			}
			else
			{
				$source = [
					'url' => $url,
					'key' => md5($type . '|' . strtolower($url)),
				];
			}
			$source['type'] = $type;
			$source['label'] = trim((string) ($server['name'] ?? ''));

			$sources[] = $source;
		}

		return $sources;
	}

	public function getStatuses(): array
	{
		$statuses = $this->app()->simpleCache()->getValue(static::ADDON_ID, static::CACHE_KEY);

		return is_array($statuses) ? $statuses : [];
	}

	public function getInterval(): int
	{
		return max(static::MIN_INTERVAL, (int) $this->options()->ntServerOnlineInterval);
	}

	public function refresh(array $sources): array
	{
		$keyed = [];
		foreach ($sources AS $source)
		{
			$keyed[$source['key']] = $source;
		}
		if (!$keyed)
		{
			return [];
		}

		$results = $this->app()->service(StatusFetcherService::class)->fetch($keyed);

		$statuses = $this->getStatuses();
		foreach ($results AS $key => $result)
		{
			$statuses[$key] = $this->mergeResult($statuses[$key] ?? [], $result);
		}
		$this->saveStatuses($statuses);

		return $results;
	}

	public function refreshWidgets(): void
	{
		$sources = [];
		$widgets = $this->finder(WidgetFinder::class)
			->where('definition_id', static::WIDGET_DEFINITION)
			->fetch();
		foreach ($widgets AS $widget)
		{
			foreach ($this->getSources($widget->options) AS $source)
			{
				$sources[$source['key']] = $source;
			}
		}

		$statuses = $this->getStatuses();
		$current = array_intersect_key($statuses, $sources);
		if (count($current) !== count($statuses))
		{
			$this->saveStatuses($current);
		}

		$dueBefore = \XF::$time - $this->getInterval() + static::CRON_DRIFT;
		$due = [];
		$order = [];
		foreach ($sources AS $key => $source)
		{
			$checked = $current[$key]['checked'] ?? 0;
			if ($checked <= $dueBefore)
			{
				$due[$key] = $source;
				$order[$key] = $checked;
			}
		}
		array_multisort($order, SORT_ASC, $due);

		$this->refresh($due);
	}

	public function getDisplayServers(array $options): array
	{
		$statuses = $this->getStatuses();

		$servers = [];
		foreach ($this->getSources($options) AS $source)
		{
			$status = $statuses[$source['key']] ?? [];
			$online = $this->isOnline($status);
			$players = $online ? (int) $status['players'] : 0;
			$max = $status['max'] ?? null;

			$servers[] = [
				'name' => $source['label'] !== ''
					? $source['label']
					: ($status['name'] ?? $this->getFallbackName($source)),
				'type' => $source['type'],
				'online' => $online,
				'players' => $players,
				'max' => $max,
				'percent' => $max ? min(100, round($players / $max * 100, 1)) : 0,
				'address' => $status['address'] ?? null,
				'checked' => $status['checked'] ?? 0,
				'error' => $status['error'] ?? '',
				'detail' => $status['detail'] ?? '',
			];
		}

		return $servers;
	}

	protected function getFallbackName(array $source): string
	{
		if ($source['type'] === static::TYPE_JSON)
		{
			return (string) parse_url($source['url'], PHP_URL_HOST);
		}

		$address = $this->getSourceHandler($source['type'])->parseAddress($source['url']);

		return $address ? $address['host'] : $source['url'];
	}

	protected function isOnline(array $status): bool
	{
		if (empty($status['success']) || empty($status['online']))
		{
			return false;
		}

		if ($status['error'] === '')
		{
			return $status['success'] >= \XF::$time - static::STALE_AFTER;
		}

		return $status['success'] >= \XF::$time - max(static::MIN_GRACE, $this->getInterval() * 3);
	}

	protected function mergeResult(array $status, array $result): array
	{
		$status['checked'] = \XF::$time;
		$status['error'] = $result['error'];
		$status['detail'] = $result['detail'];

		if ($result['error'] === '')
		{
			$status['success'] = \XF::$time;
			$status['players'] = $result['players'];
			$status['max'] = $result['max'];
			$status['name'] = $result['name'];
			$status['address'] = $result['address'];
			$status['online'] = $result['online'];
		}

		return $status;
	}

	protected function saveStatuses(array $statuses): void
	{
		$this->app()->simpleCache()->setValue(static::ADDON_ID, static::CACHE_KEY, $statuses);
	}
}
