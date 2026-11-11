<?php

declare(strict_types=1);

namespace NT\ServerOnline;

use NT\ServerOnline\Repository\ServerStatusRepository;
use XF\AddOn\AbstractSetup;
use XF\AddOn\StepRunnerInstallTrait;
use XF\AddOn\StepRunnerUninstallTrait;
use XF\AddOn\StepRunnerUpgradeTrait;

class Setup extends AbstractSetup
{
	use StepRunnerInstallTrait;
	use StepRunnerUpgradeTrait;
	use StepRunnerUninstallTrait;

	public function uninstallStep1(): void
	{
		$this->app->simpleCache()->deleteSet(ServerStatusRepository::ADDON_ID);
	}
}
