<?php

declare(strict_types=1);

namespace NT\ServerOnline\Source;

class FiveM extends AbstractHttpSource
{
	public function getDefaultPort(): int
	{
		return 30120;
	}

	public function getRequestUrl(array $source): ?string
	{
		$address = $this->parseAddress($source['url']);

		return $address ? 'http://' . $this->formatAddress($address) . '/dynamic.json' : null;
	}

	public function parse(array $data, array $source): array
	{
		if (!isset($data['clients']) || !is_numeric($data['clients']))
		{
			return $this->failure(static::ERROR_RESPONSE);
		}

		$address = $this->parseAddress($source['url']);
		$name = isset($data['hostname']) && is_string($data['hostname'])
			? preg_replace('/\^[0-9]/', '', $data['hostname'])
			: null;

		return $this->success(
			(int) $data['clients'],
			isset($data['sv_maxclients']) && is_numeric($data['sv_maxclients']) ? (int) $data['sv_maxclients'] : null,
			$name,
			$address ? $this->formatAddress($address) : null
		);
	}
}
