<?php

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OC\Core\Sharing\Recipient;

use OC\Core\AppInfo\Application;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Server;
use OCP\Share\IShare;
use OCP\Sharing\Icon\ShareIconURL;

// TODO: Add delete listener to remove recipients
final class UserShareRecipientType extends AShareRecipientTypeSearchCollaborator {
	#[\Override]
	public function getDisplayName(): string {
		return Server::get(IFactory::class)->get(Application::APP_ID)->t('User');
	}

	#[\Override]
	public function validateRecipient(IUser $owner, string $recipient): bool {
		if ($recipient === $owner->getUID()) {
			return false;
		}

		return Server::get(IUserManager::class)->userExists($recipient);
	}

	#[\Override]
	public function getRecipients(?IUser $currentUser, mixed $arguments): array {
		if (!$currentUser instanceof IUser) {
			return [];
		}

		return [$currentUser->getUID()];
	}

	#[\Override]
	public function getRecipientDisplayName(string $recipient): ?string {
		return Server::get(IUserManager::class)->getDisplayName($recipient);
	}

	#[\Override]
	public function getRecipientIcon(string $recipient): ShareIconURL {
		/** @var IUser $user */
		$user = Server::get(IUserManager::class)->get($recipient);

		return new ShareIconURL(
			$user->getUserAvatarUrlLight(64),
			$user->getUserAvatarUrlDark(64),
		);
	}

	#[\Override]
	public function getCollaboratorType(): int {
		return IShare::TYPE_USER;
	}

	#[\Override]
	public function getCollaboratorKey(): string {
		return 'users';
	}
}
