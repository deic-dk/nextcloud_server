<?php

/*
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OC\Sharing\Permission;

use OC\Core\AppInfo\Application;
use OCP\L10N\IFactory;
use OCP\Server;
use OCP\Sharing\Permission\ISharePermissionType;

final class ReshareSharePermissionType implements ISharePermissionType {
	#[\Override]
	public function getDisplayName(): string {
		return Server::get(IFactory::class)->get(Application::APP_ID)->t('Reshare');
	}

	#[\Override]
	public function getDefault(): bool {
		return false;
	}

	#[\Override]
	public function getCategory(): string {
		return ShareSharePermissionCategoryType::class;
	}
}
