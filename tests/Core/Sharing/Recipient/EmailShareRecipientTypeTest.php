<?php

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);


namespace Core\Sharing\Recipient;

use OC\Core\Sharing\Recipient\EmailShareRecipientType;
use OC\User\Database;
use OCP\Contacts\IManager;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Server;
use OCP\Sharing\ShareAccessContext;
use PHPUnit\Framework\Attributes\Group;
use Test\TestCase;

#[Group(name: 'DB')]
final class EmailShareRecipientTypeTest extends TestCase {
	private IUser $user1;

	private EmailShareRecipientType $recipientType;

	private function createUser(IUserManager $userManager, string $uid, string $password): IUser {
		$user = $userManager->createUser($uid, $password);
		$this->assertNotFalse($user);
		return $user;
	}

	#[\Override]
	public function setUp(): void {
		parent::setUp();

		$userManager = Server::get(IUserManager::class);
		$userManager->clearBackends();
		$userManager->registerBackend(new Database());

		$this->user1 = $this->createUser($userManager, 'user1', 'password');

		self::loginAsUser($this->user1->getUID());

		$this->recipientType = new EmailShareRecipientType();
	}

	#[\Override]
	protected function tearDown(): void {
		$this->user1->delete();

		parent::tearDown();
	}

	public function testGetDisplayName(): void {
		$this->overwriteService(IL10N::class, Server::get(IFactory::class)->get(''));

		$this->assertEquals('Email', $this->recipientType->getDisplayName());
	}

	public function testValidateRecipient(): void {
		$this->assertTrue($this->recipientType->validateRecipient($this->user1, 'example@example.com'));
		$this->assertFalse($this->recipientType->validateRecipient($this->user1, 'example'));
		$this->assertFalse($this->recipientType->validateRecipient($this->user1, 'example@example'));
		$this->assertFalse($this->recipientType->validateRecipient($this->user1, 'example.com'));
		$this->assertFalse($this->recipientType->validateRecipient($this->user1, '@'));
	}

	public function testGetRecipientDisplayName(): void {
		$this->assertEquals('example@example.com', $this->recipientType->getRecipientDisplayName('example@example.com' . EmailShareRecipientType::SEPARATOR . 'my-secret'));
	}

	public function testSearchRecipients(): void {
		$contactsManager = Server::get(IManager::class);

		$addressBooks = array_values($contactsManager->getUserAddressBooks());
		$this->assertNotEmpty($addressBooks);

		$contactsManager->createOrUpdate(
			[
				'FN' => 'example',
				'EMAIL' => 'example@example.com',
			],
			$addressBooks[0]->getKey(),
		);

		$accessContext = new ShareAccessContext(currentUser: $this->user1);

		$recipients = $this->recipientType->searchRecipients($accessContext, 'example@example.com', 1, 0);
		$this->assertCount(1, $recipients);
		$this->assertEquals(EmailShareRecipientType::class, $recipients[0]->class);
		$this->assertNull($recipients[0]->instance);
		$parts = explode(EmailShareRecipientType::SEPARATOR, $recipients[0]->value);
		$this->assertCount(2, $parts);
		$this->assertEquals('example@example.com', $parts[0]);
		$this->assertEquals(EmailShareRecipientType::SECRET_LENGTH, strlen($parts[1]));
	}
}
