<?php

/*
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCP\Sharing;

use OCP\AppFramework\Attribute\Consumable;
use OCP\IUserManager;
use OCP\Server;
use OCP\Sharing\Exception\ShareInvalidException;
use OCP\Sharing\Icon\ShareIconURL;
use RuntimeException;

/**
 * @psalm-import-type SharingOwner from Share
 * @since 34.0.0
 */
#[Consumable(since: '34.0.0')]
final readonly class ShareOwner {
	public function __construct(
		/** @var non-empty-string $owner */
		public string $userId,
		/** @var ?non-empty-string $instance */
		public ?string $instance,
	) {
		if ($instance !== null && !preg_match('/^https?:\/\//', $instance)) {
			throw new RuntimeException('The instance is not a valid absolute URL: ' . $instance);
		}
	}

	/**
	 * @return SharingOwner
	 */
	public function format(): array {
		// TODO: Use cached data if remote
		$ownerUser = Server::get(IUserManager::class)->get($this->userId);
		if ($ownerUser === null) {
			throw new ShareInvalidException('The userId does not exist: ' . $this->userId);
		}

		$out = [
			'user_id' => $this->userId,
			'display_name' => $ownerUser->getDisplayName(),
			'icon' => (new ShareIconURL(
				$ownerUser->getUserAvatarUrlLight(64),
				$ownerUser->getUserAvatarUrlDark(64),
			))->format(),
		];

		if ($this->instance !== null) {
			$out['instance'] = $this->instance;
		}

		return $out;
	}
}
