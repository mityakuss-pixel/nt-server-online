<?php

declare(strict_types=1);

namespace NT\ServerOnline\Cron;

use NT\ServerOnline\Repository\ServerStatusRepository;

class Refresh
{
	public static function run(): void
	{
		\XF::repository(ServerStatusRepository::class)->refreshWidgets();
	}
}
