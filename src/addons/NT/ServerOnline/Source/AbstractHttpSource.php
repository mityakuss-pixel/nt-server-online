<?php

declare(strict_types=1);

namespace NT\ServerOnline\Source;

abstract class AbstractHttpSource extends AbstractSource
{
	abstract public function getRequestUrl(array $source): ?string;

	abstract public function parse(array $data, array $source): array;
}
