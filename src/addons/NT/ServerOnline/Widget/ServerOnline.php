<?php

declare(strict_types=1);

namespace NT\ServerOnline\Widget;

use NT\ServerOnline\Repository\ServerStatusRepository;
use NT\ServerOnline\Source\AbstractSource;
use XF\Http\Request;
use XF\Widget\AbstractWidget;

class ServerOnline extends AbstractWidget
{
	protected const BLOCKING_ERRORS = [
		AbstractSource::ERROR_JSON,
		AbstractSource::ERROR_FIELD,
		AbstractSource::ERROR_ADDRESS,
	];

	protected $defaultOptions = [
		'servers' => [],
		'path_players' => 'players',
		'path_max' => 'maxPlayers',
		'path_name' => 'displayName',
		'path_address' => 'address',
		'path_state' => 'state',
		'state_online' => 'online',
		'show_status' => true,
		'show_max' => true,
		'show_bar' => true,
		'show_address' => true,
		'show_total' => true,
		'hide_offline' => false,
		'link_text' => '',
		'link_url' => '',
	];

	public function render()
	{
		$servers = $this->getStatusRepo()->getDisplayServers($this->options);
		if ($this->options['hide_offline'])
		{
			$servers = array_filter($servers, function (array $server): bool
			{
				return $server['online'];
			});
		}
		if (!$servers)
		{
			return '';
		}

		$total = 0;
		foreach ($servers AS $server)
		{
			$total += $server['players'];
		}

		return $this->renderer('nt_server_online_widget', [
			'servers' => $servers,
			'total' => $total,
		]);
	}

	public function verifyOptions(Request $request, array &$options, &$error = null)
	{
		$filterer = $this->app->inputFilterer();
		$statusRepo = $this->getStatusRepo();

		$options = $filterer->filterArray($options, [
			'servers' => 'array',
			'path_players' => 'str',
			'path_max' => 'str',
			'path_name' => 'str',
			'path_address' => 'str',
			'path_state' => 'str',
			'state_online' => 'str',
			'show_status' => 'bool',
			'show_max' => 'bool',
			'show_bar' => 'bool',
			'show_address' => 'bool',
			'show_total' => 'bool',
			'hide_offline' => 'bool',
			'link_text' => 'str',
			'link_url' => 'str',
		]);

		$servers = [];
		$hasJson = false;
		foreach ($options['servers'] AS $server)
		{
			$server = $filterer->filterArray(is_array($server) ? $server : [], [
				'type' => 'str',
				'url' => 'str',
				'name' => 'str',
				'root' => 'str',
			]);
			if ($server['url'] === '')
			{
				continue;
			}

			$server['type'] = $statusRepo->getServerType($server);
			if ($server['type'] === ServerStatusRepository::TYPE_JSON)
			{
				$hasJson = true;
				if (!$this->isHttpUrl($server['url']))
				{
					$error = \XF::phrase('nt_server_online_invalid_url_x', ['url' => $server['url']]);

					return false;
				}
			}
			else
			{
				$server['root'] = '';
				if (!$statusRepo->getSourceHandler($server['type'])->parseAddress($server['url']))
				{
					$error = \XF::phrase('nt_server_online_invalid_address_x', ['address' => $server['url']]);

					return false;
				}
			}

			$servers[] = $server;
		}
		$options['servers'] = array_slice($servers, 0, ServerStatusRepository::MAX_SERVERS);

		if (!$options['servers'])
		{
			$error = \XF::phrase('nt_server_online_no_servers');

			return false;
		}
		if ($hasJson && $options['path_players'] === '')
		{
			$error = \XF::phrase('nt_server_online_players_field_required');

			return false;
		}
		if ($options['link_url'] !== '' && !$this->isHttpUrl($options['link_url']))
		{
			$error = \XF::phrase('nt_server_online_invalid_url_x', ['url' => $options['link_url']]);

			return false;
		}

		$sources = $statusRepo->getSources($options);
		foreach ($statusRepo->refresh($sources) AS $key => $result)
		{
			if (in_array($result['error'], static::BLOCKING_ERRORS, true))
			{
				$error = \XF::phrase('nt_server_online_check_failed_x', [
					'url' => $this->getSourceUrl($sources, $key),
					'error' => $this->getErrorPhrase($result['error'], $result['detail']),
				]);

				return false;
			}
		}

		return true;
	}

	protected function getDefaultTemplateParams($context)
	{
		$params = parent::getDefaultTemplateParams($context);
		if ($context === 'options')
		{
			$servers = $this->getStatusRepo()->getDisplayServers($this->options);
			foreach ($servers AS &$server)
			{
				$server['errorPhrase'] = $server['error'] !== ''
					? $this->getErrorPhrase($server['error'], $server['detail'])
					: null;
			}
			unset($server);

			$types = [];
			foreach (array_keys(ServerStatusRepository::TYPES) AS $type)
			{
				$types[$type] = \XF::phrase('nt_server_online_type.' . $type);
			}

			$params['servers'] = $servers;
			$params['types'] = $types;
			$params['nextCounter'] = count($this->options['servers']);
		}

		return $params;
	}

	protected function getErrorPhrase(string $error, string $detail)
	{
		return \XF::phrase('nt_server_online_error_' . $error, ['detail' => $detail]);
	}

	protected function getSourceUrl(array $sources, string $key): string
	{
		foreach ($sources AS $source)
		{
			if ($source['key'] === $key)
			{
				return $source['url'];
			}
		}

		return '';
	}

	protected function isHttpUrl(string $url): bool
	{
		return (bool) preg_match('#^https?://[^\s/?\#]+#i', $url);
	}

	protected function getStatusRepo(): ServerStatusRepository
	{
		return $this->repository(ServerStatusRepository::class);
	}
}
