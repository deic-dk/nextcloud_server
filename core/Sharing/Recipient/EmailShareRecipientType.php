<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OC\Core\Sharing\Recipient;

use OC\Core\AppInfo\Application;
use OCP\IUser;
use OCP\L10N\IFactory;
use OCP\Mail\IEmailValidator;
use OCP\Security\ISecureRandom;
use OCP\Server;
use OCP\Share\IShare;
use OCP\Sharing\Icon\ShareIconSVG;
use OCP\Sharing\Icon\ShareIconURL;
use OCP\Sharing\Recipient\ShareRecipient;
use OCP\Sharing\ShareAccessContext;
use RuntimeException;

// TODO: Add logic to send emails when share state is updated to active

/**
 * Searching users by their email addresses is done by {@see UserShareRecipientType}
 */
final class EmailShareRecipientType extends AShareRecipientTypeSearchCollaborator {
	public const SEPARATOR = '|';

	// TODO: What is a good length?
	public const SECRET_LENGTH = 32;

	#[\Override]
	public function getDisplayName(): string {
		return Server::get(IFactory::class)->get(Application::APP_ID)->t('Email');
	}

	#[\Override]
	public function validateRecipient(IUser $owner, string $recipient): bool {
		return Server::get(IEmailValidator::class)->isValid($recipient);
	}

	#[\Override]
	public function getRecipients(?IUser $currentUser, mixed $arguments): array {
		if (is_string($arguments)) {
			return [$arguments];
		}

		return [];
	}

	#[\Override]
	public function getRecipientDisplayName(string $recipient): string {
		$parts = explode(self::SEPARATOR, $recipient);
		array_pop($parts);
		// Just in case there was any other SEPARATOR in the email we implode instead of just taking the first element.
		$email = implode(self::SEPARATOR, $parts);
		if ($email === '') {
			throw new RuntimeException('Empty email.');
		}

		return $email;
	}

	#[\Override]
	public function getRecipientIcon(string $recipient): null|ShareIconSVG|ShareIconURL {
		return null;
	}

	#[\Override]
	public function getCollaboratorType(): int {
		return IShare::TYPE_EMAIL;
	}

	#[\Override]
	public function getCollaboratorKey(): string {
		return 'emails';
	}

	/**
	 * Search for recipients.
	 *
	 * @param non-empty-string $query
	 * @param positive-int $limit
	 * @param non-negative-int $offset
	 * @return list<ShareRecipient>
	 */
	#[\Override]
	public function searchRecipients(ShareAccessContext $accessContext, string $query, int $limit, int $offset): array {
		$secureRandom = Server::get(ISecureRandom::class);

		// We add a random secret to each email, to make it impossible to guess the recipient value.
		// The downside is that we can't deduplicate emails with the unique constraint.
		// TODO: Check if we can use the federation secret instead
		return array_map(
			static fn (ShareRecipient $recipient): ShareRecipient => new ShareRecipient(
				$recipient->class,
				$recipient->value . self::SEPARATOR . $secureRandom->generate(self::SECRET_LENGTH, ISecureRandom::CHAR_ALPHANUMERIC),
				$recipient->instance,
			),
			parent::searchRecipients($accessContext, $query, $limit, $offset),
		);
	}
}
