<?php

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OC\Core\Sharing\Recipient;

use OC\Core\AppInfo\Application;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\L10N\IFactory;
use OCP\Server;
use OCP\Share\IShare;
use OCP\Sharing\Icon\ShareIconSVG;
use OCP\Sharing\Icon\ShareIconURL;

// TODO: Add delete listener to remove recipients
final class GroupShareRecipientType extends AShareRecipientTypeSearchCollaborator {
	#[\Override]
	public function getDisplayName(): string {
		return Server::get(IFactory::class)->get(Application::APP_ID)->t('Group');
	}

	#[\Override]
	public function validateRecipient(IUser $owner, string $recipient): bool {
		return Server::get(IGroupManager::class)->groupExists($recipient);
	}

	#[\Override]
	public function getRecipients(?IUser $currentUser, mixed $arguments): array {
		if (!$currentUser instanceof IUser) {
			return [];
		}

		return Server::get(IGroupManager::class)->getUserGroupIds($currentUser);
	}

	#[\Override]
	public function getRecipientDisplayName(string $recipient): ?string {
		$displayName = Server::get(IGroupManager::class)->getDisplayName($recipient);
		if ($displayName === '') {
			return null;
		}

		return $displayName;
	}

	#[\Override]
	public function getRecipientIcon(string $recipient): null|ShareIconSVG|ShareIconURL {
		return null;
	}

	#[\Override]
	public function getCollaboratorType(): int {
		return IShare::TYPE_GROUP;
	}

	#[\Override]
	public function getCollaboratorKey(): string {
		return 'groups';
	}
}
