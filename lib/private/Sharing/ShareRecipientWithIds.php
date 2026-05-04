<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OC\Sharing;

use OCP\Sharing\Recipient\IShareRecipientType;
use OCP\Sharing\Recipient\ShareRecipient;

final readonly class ShareRecipientWithIds extends ShareRecipient {
	public function __construct(
		public string $id,
		public ?string $parentId,
		/** @var class-string<IShareRecipientType> $class */
		public string $class,
		/** @var non-empty-string $value */
		public string $value,
		/** @var ?non-empty-string $instance */
		public ?string $instance,
	) {
	}
}
