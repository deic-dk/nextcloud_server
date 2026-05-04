<?php

/*
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Test\Sharing;

use OCP\Sharing\Permission\ISharePermissionType;

class TestSharePermissionType1 implements ISharePermissionType {
	#[\Override]
	public function getDisplayName(): string {
		/** @var non-empty-list<non-empty-string> $parts */
		$parts = explode('\\', static::class);
		return end($parts);
	}

	#[\Override]
	public function getCategory(): ?string {
		return TestSharePermissionCategoryType1::class;
	}

	#[\Override]
	public function getDefault(): ?bool {
		return false;
	}
}
