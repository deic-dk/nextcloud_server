<?php

/*
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Test\Sharing;

use OC\Sharing\Permission\ReshareSharePermissionType;
use OC\Sharing\Permission\ShareSharePermissionCategoryType;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Server;
use OCP\Sharing\IManager;
use OCP\Sharing\IRegistry;
use OCP\Sharing\Permission\SharePermission;
use OCP\Sharing\Property\ShareProperty;
use OCP\Sharing\Recipient\ShareRecipient;
use OCP\Sharing\Share;
use OCP\Sharing\ShareAccessContext;
use OCP\Sharing\ShareState;
use OCP\Sharing\Source\ShareSource;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\TestCase;

/**
 * @psalm-import-type SharingShare from Share
 * @psalm-import-type SharingRecipient from Share
 */
abstract class AbstractManagerTests extends TestCase {
	abstract protected function searchRecipients(ShareAccessContext $accessContext, ?string $recipientTypeClass, string $query, int $limit, int $offset): array;

	abstract protected function createShare(ShareAccessContext $accessContext): array;

	abstract protected function updateShareState(ShareAccessContext $accessContext, string $id, ShareState $state): array;

	abstract protected function addShareSource(ShareAccessContext $accessContext, string $id, ShareSource $source): array;

	abstract protected function removeShareSource(ShareAccessContext $accessContext, string $id, ShareSource $source): array;

	abstract protected function addShareRecipient(ShareAccessContext $accessContext, string $id, ShareRecipient $recipient): array;

	abstract protected function removeShareRecipient(ShareAccessContext $accessContext, string $id, ShareRecipient $recipient): array;

	abstract protected function updateShareProperty(ShareAccessContext $accessContext, string $id, ShareProperty $property): array;

	abstract protected function updateSharePermission(ShareAccessContext $accessContext, string $id, SharePermission $permission): array;

	abstract protected function deleteShare(ShareAccessContext $accessContext, string $id): void;

	abstract protected function getShare(ShareAccessContext $accessContext, string $id): array;

	abstract protected function listShares(ShareAccessContext $accessContext, ?string $sourceTypeClass, ?string $lastShareID, ?int $limit): array;

	protected IManager $manager;

	protected IRegistry $registry;

	protected IUser $owner;

	protected IUser $user1;

	protected IUser $user2;

	#[\Override]
	public function setUp(): void {
		parent::setUp();

		$this->manager = Server::get(IManager::class);

		$this->registry = Server::get(IRegistry::class);
		$this->registry->clear();

		$owner = Server::get(IUserManager::class)->createUser('owner', 'password');
		$this->assertNotFalse($owner);
		$this->owner = $owner;
		$this->owner->setDisplayName('Owner');

		$user1 = Server::get(IUserManager::class)->createUser('user1', 'password');
		$this->assertNotFalse($user1);
		$this->user1 = $user1;
		$this->user1->setDisplayName('User 1');

		$user2 = Server::get(IUserManager::class)->createUser('user2', 'password');
		$this->assertNotFalse($user2);
		$this->user2 = $user2;
		$this->user2->setDisplayName('User 2');
	}

	#[\Override]
	protected function tearDown(): void {
		$accessContext = new ShareAccessContext(force: true);

		foreach ($this->manager->listShares($accessContext, null, null, null) as $share) {
			$this->manager->deleteShare($accessContext, $share->id);
		}

		$connection = Server::get(IDBConnection::class);
		foreach ([
			'sharing_share',
			'sharing_share_permissions',
			'sharing_share_properties',
			'sharing_share_recipients',
			'sharing_share_sources',
		] as $table) {
			$qb = $connection->getQueryBuilder();
			$qb
				->select($qb->func()->count('*'))
				->from($table);
			$this->assertEquals(0, $qb->executeQuery()->fetchOne(), $table);
		}

		$this->registry->clear();

		$this->owner->delete();
		$this->user1->delete();
		$this->user2->delete();

		parent::tearDown();
	}

	private function register(): void {
		$this->registry->registerSourceType(new TestShareSourceType1(['source1' => 'Source 1']));
		$this->registry->registerSourceType(new TestShareSourceType2(['source2' => 'Source 2']));
		$this->registry->registerRecipientType(new TestShareRecipientType1(
			[
				'recipient1' => 'Recipient 1',
			],
			[
				$this->user1->getUID() => ['recipient1'],
			],
			[],
		));
		$this->registry->registerRecipientType(new TestShareRecipientType2(
			[
				'recipient2' => 'Recipient 2',
			],
			[
				$this->user2->getUID() => ['recipient2'],
			],
			[],
		));
		$this->registry->registerPropertyType(new TestSharePropertyType1(['valid1']));
		$this->registry->markPropertyTypeCompatibleWithSourceType(TestSharePropertyType1::class, TestShareSourceType1::class);
		$this->registry->markPropertyTypeCompatibleWithRecipientType(TestSharePropertyType1::class, TestShareRecipientType1::class);
		$this->registry->registerPropertyType(new TestSharePropertyType2(['valid2']));
		$this->registry->markPropertyTypeCompatibleWithSourceType(TestSharePropertyType2::class, TestShareSourceType2::class);
		$this->registry->markPropertyTypeCompatibleWithRecipientType(TestSharePropertyType2::class, TestShareRecipientType2::class);
		$this->registry->registerPermissionCategoryType(new TestSharePermissionCategoryType1());
		$this->registry->registerPermissionCategoryType(new TestSharePermissionCategoryType2());
		$this->registry->registerPermissionCategoryType(new ShareSharePermissionCategoryType());
		$this->registry->registerPermissionType(TestShareSourceType1::class, new TestSharePermissionType1());
		$this->registry->registerPermissionType(TestShareSourceType2::class, new TestSharePermissionType2());
		$this->registry->registerPermissionType(null, new ReshareSharePermissionType());
	}

	private function getTimestamp(): int {
		/** @psalm-suppress MixedReturnStatement */
		return self::invokePrivate($this->manager, 'generateLastUpdated');
	}

	public function testSearchRecipients(): void {
		$accessContext = new ShareAccessContext($this->owner);

		$displayNames = [
			'recipient1a' => 'Recipient 1A',
			'recipient1b' => 'Recipient 1B',
			'recipient1c' => 'Recipient 1C',
			'recipient2a' => 'Recipient 2A',
			'recipient2b' => 'Recipient 2B',
			'recipient2c' => 'Recipient 2C',
		];

		$recipientType1 = new TestShareRecipientType1($displayNames, [], []);
		$recipientType2 = new TestShareRecipientType2($displayNames, [], []);
		$this->registry->registerRecipientType($recipientType1);
		$this->registry->registerRecipientType($recipientType2);

		$recipient1a = new ShareRecipient(TestShareRecipientType1::class, 'recipient1a', null);
		$recipientType1->searchRecipients[] = $recipient1a;
		$recipient1b = new ShareRecipient(TestShareRecipientType1::class, 'recipient1b', null);
		$recipientType1->searchRecipients[] = $recipient1b;
		$recipient1c = new ShareRecipient(TestShareRecipientType1::class, 'recipient1c', null);
		$recipientType1->searchRecipients[] = $recipient1c;
		$recipient2a = new ShareRecipient(TestShareRecipientType2::class, 'recipient2a', null);
		$recipientType2->searchRecipients[] = $recipient2a;
		$recipient2b = new ShareRecipient(TestShareRecipientType2::class, 'recipient2b', null);
		$recipientType2->searchRecipients[] = $recipient2b;
		$recipient2c = new ShareRecipient(TestShareRecipientType2::class, 'recipient2c', null);
		$recipientType2->searchRecipients[] = $recipient2c;

		$this->assertEquals([
			['class' => TestShareRecipientType1::class, 'value' => 'recipient1a', 'display_name' => $displayNames['recipient1a']],
			['class' => TestShareRecipientType1::class, 'value' => 'recipient1b', 'display_name' => $displayNames['recipient1b']],
			['class' => TestShareRecipientType1::class, 'value' => 'recipient1c', 'display_name' => $displayNames['recipient1c']],
			['class' => TestShareRecipientType2::class, 'value' => 'recipient2a', 'display_name' => $displayNames['recipient2a']],
			['class' => TestShareRecipientType2::class, 'value' => 'recipient2b', 'display_name' => $displayNames['recipient2b']],
			['class' => TestShareRecipientType2::class, 'value' => 'recipient2c', 'display_name' => $displayNames['recipient2c']],
		], $this->searchRecipients($accessContext, null, 'recipient', 10, 0));

		$this->assertEquals([
			['class' => TestShareRecipientType1::class, 'value' => 'recipient1a', 'display_name' => $displayNames['recipient1a']],
			['class' => TestShareRecipientType1::class, 'value' => 'recipient1b', 'display_name' => $displayNames['recipient1b']],
			['class' => TestShareRecipientType1::class, 'value' => 'recipient1c', 'display_name' => $displayNames['recipient1c']],
		], $this->searchRecipients($accessContext, TestShareRecipientType1::class, 'recipient', 10, 0));

		$this->assertEquals([
			['class' => TestShareRecipientType1::class, 'value' => 'recipient1a', 'display_name' => $displayNames['recipient1a']],
		], $this->searchRecipients($accessContext, TestShareRecipientType1::class, 'recipient', 1, 0));

		$this->assertEquals([
			['class' => TestShareRecipientType1::class, 'value' => 'recipient1b', 'display_name' => $displayNames['recipient1b']],
			['class' => TestShareRecipientType1::class, 'value' => 'recipient1c', 'display_name' => $displayNames['recipient1c']],
		], $this->searchRecipients($accessContext, TestShareRecipientType1::class, 'recipient', 10, 1));
	}

	public function testSearchRecipientsUniqueDisplayNames(): void {
		$accessContext = new ShareAccessContext($this->owner);

		$recipientType1 = new TestShareRecipientType1(['recipient1' => 'Recipient'], [], []);
		$recipientType2 = new TestShareRecipientType2(['recipient2' => 'Recipient', 'recipient3' => 'Other'], [], []);
		$this->registry->registerRecipientType($recipientType1);
		$this->registry->registerRecipientType($recipientType2);

		$recipient1 = new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null);
		$recipientType1->searchRecipients[] = $recipient1;
		$recipient2 = new ShareRecipient(TestShareRecipientType2::class, 'recipient2', null);
		$recipientType2->searchRecipients[] = $recipient2;
		$recipient3 = new ShareRecipient(TestShareRecipientType2::class, 'recipient3', null);
		$recipientType2->searchRecipients[] = $recipient3;

		$this->assertEquals([
			['class' => TestShareRecipientType1::class, 'value' => 'recipient1', 'display_name' => 'Recipient (TestShareRecipientType1: recipient1)'],
			['class' => TestShareRecipientType2::class, 'value' => 'recipient2', 'display_name' => 'Recipient (TestShareRecipientType2: recipient2)'],
			['class' => TestShareRecipientType2::class, 'value' => 'recipient3', 'display_name' => 'Other'],
		], $this->searchRecipients($accessContext, null, 'recipient', 10, 0));
	}

	public function testSearchRecipientsIcons(): void {
		$accessContext = new ShareAccessContext($this->owner);

		$recipientType = new TestShareRecipientType1(['svg' => 'SVG', 'url' => 'URL'], [], []);
		$this->registry->registerRecipientType($recipientType);

		$recipient1 = new ShareRecipient(TestShareRecipientType1::class, 'svg', null);
		$recipientType->searchRecipients[] = $recipient1;
		$recipient2 = new ShareRecipient(TestShareRecipientType1::class, 'url', null);
		$recipientType->searchRecipients[] = $recipient2;

		$this->assertEquals([
			['class' => TestShareRecipientType1::class, 'value' => 'svg', 'display_name' => 'SVG', 'icon' => ['svg' => '<svg/>']],
			['class' => TestShareRecipientType1::class, 'value' => 'url', 'display_name' => 'URL', 'icon' => ['light' => 'https://example.com/light.png', 'dark' => 'https://example.com/dark.png',]],
		], $this->searchRecipients($accessContext, null, 'icon', 10, 0));
	}

	public function testCreateShare(): void {
		$this->register();

		$accessContext = new ShareAccessContext($this->owner);

		$before = $this->getTimestamp();
		$share = $this->createShare($accessContext);
		$after = $this->getTimestamp();
		unset($share['id']);
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		unset($share['last_updated']);
		$this->assertEquals([
			'owner' => [
				'user_id' => 'owner',
				'display_name' => 'Owner',
				'icon' => [
					'light' => 'http://localhost/index.php/avatar/owner/64',
					'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
				],
			],
			'state' => ShareState::Draft->value,
			'sources' => [],
			'recipients' => [],
			'properties' => [],
			'permissions' => [
				[
					'class' => ReshareSharePermissionType::class,
					'display_name' => 'Reshare',
					'category' => ShareSharePermissionCategoryType::class,
					'enabled' => false,
				],
			],
		], $share);
	}

	/**
	 * @return list<array{list<ShareSource>, list<ShareRecipient>, list<ShareProperty>, list<SharePermission>, ?string}>
	 */
	public static function dataProviderUpdateShareState(): array {
		return [
			[
				[new ShareSource(TestShareSourceType1::class, 'source1')],
				[new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null)],
				[new ShareProperty(TestSharePropertyTypeRequired::class, 'valid1')],
				[new SharePermission(ReshareSharePermissionType::class, true)],
				null,
			],
			[
				[],
				[new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null)],
				[],
				[new SharePermission(ReshareSharePermissionType::class, true)],
				'No source set.',
			],
			[
				[new ShareSource(TestShareSourceType1::class, 'source1')],
				[],
				[],
				[new SharePermission(ReshareSharePermissionType::class, true)],
				'No recipient set.',
			],
			[
				[new ShareSource(TestShareSourceType1::class, 'source1')],
				[new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null)],
				[],
				[new SharePermission(ReshareSharePermissionType::class, true)],
				'Missing value for required property: ' . TestSharePropertyTypeRequired::class,
			],
			[
				[new ShareSource(TestShareSourceType1::class, 'source1')],
				[new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null)],
				[new ShareProperty(TestSharePropertyTypeRequired::class, 'valid1')],
				[],
				'No permission given.',
			],
		];
	}

	/**
	 * @param list<ShareSource> $sources
	 * @param list<ShareRecipient> $recipients
	 * @param list<ShareProperty> $properties
	 * @param list<SharePermission> $permissions
	 */
	#[DataProvider('dataProviderUpdateShareState')]
	public function testUpdateShareState(array $sources, array $recipients, array $properties, array $permissions, ?string $errorMessage): void {
		$this->register();
		$this->registry->registerPropertyType(new TestSharePropertyTypeRequired(['valid1']));
		$this->registry->markPropertyTypeCompatibleWithSourceType(TestSharePropertyTypeRequired::class, TestShareSourceType1::class);
		$this->registry->markPropertyTypeCompatibleWithRecipientType(TestSharePropertyTypeRequired::class, TestShareRecipientType1::class);

		$accessContext = new ShareAccessContext($this->owner);

		$id = $this->manager->createShare($accessContext);
		foreach ($sources as $source) {
			$this->manager->addShareSource($accessContext, $id, $source);
		}

		foreach ($recipients as $recipient) {
			$this->manager->addShareRecipient($accessContext, $id, $recipient);
		}

		$this->manager->getShare($accessContext, $id);

		foreach ($properties as $property) {
			$this->manager->updateShareProperty($accessContext, $id, $property);
		}

		foreach ($permissions as $permission) {
			$this->manager->updateSharePermission($accessContext, $id, $permission);
		}

		if ($errorMessage !== null) {
			$this->expectExceptionMessage($errorMessage);
			$this->updateShareState($accessContext, $id, ShareState::Active);
		} else {
			$before = $this->getTimestamp();
			$share = $this->updateShareState($accessContext, $id, ShareState::Active);
			$after = $this->getTimestamp();
			$this->assertGreaterThanOrEqual($before, $share['last_updated']);
			$this->assertLessThanOrEqual($after, $share['last_updated']);
			unset($share['last_updated']);
			$this->assertEquals([
				'id' => $id,
				'owner' => [
					'user_id' => 'owner',
					'display_name' => 'Owner',
					'icon' => [
						'light' => 'http://localhost/index.php/avatar/owner/64',
						'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
					],
				],
				'state' => ShareState::Active->value,
				'sources' => [
					[
						'class' => TestShareSourceType1::class,
						'value' => 'source1',
						'display_name' => 'Source 1',
					],
				],
				'recipients' => [
					[
						'class' => TestShareRecipientType1::class,
						'value' => 'recipient1',
						'display_name' => 'Recipient 1',
					],
				],
				'properties' => [
					[
						'class' => TestSharePropertyType1::class,
						'display_name' => 'TestSharePropertyType1',
						'priority' => 1,
						'required' => false,
						'value' => null,
						'type' => 'enum',
						'valid_values' => ['valid1'],
					],
					[
						'class' => TestSharePropertyTypeRequired::class,
						'display_name' => 'TestSharePropertyTypeRequired',
						'priority' => 1,
						'required' => true,
						'value' => 'valid1',
						'type' => 'enum',
						'valid_values' => ['valid1'],
					],
				],
				'permissions' => [
					[
						'class' => ReshareSharePermissionType::class,
						'display_name' => 'Reshare',
						'category' => ShareSharePermissionCategoryType::class,
						'enabled' => true,
					],
					[
						'class' => TestSharePermissionType1::class,
						'display_name' => 'TestSharePermissionType1',
						'category' => TestSharePermissionCategoryType1::class,
						'enabled' => false,
					],
				],
			], $share);
		}
	}

	public function testAddShareSource(): void {
		$this->register();

		$accessContext = new ShareAccessContext($this->owner);

		$id = $this->manager->createShare($accessContext);

		$before = $this->getTimestamp();
		$share = $this->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));
		$after = $this->getTimestamp();
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		unset($share['last_updated']);
		$this->assertEquals([
			'id' => $id,
			'owner' => [
				'user_id' => 'owner',
				'display_name' => 'Owner',
				'icon' => [
					'light' => 'http://localhost/index.php/avatar/owner/64',
					'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
				],
			],
			'state' => ShareState::Draft->value,
			'sources' => [
				[
					'class' => TestShareSourceType1::class,
					'value' => 'source1',
					'display_name' => 'Source 1',
				],
			],
			'recipients' => [],
			'properties' => [],
			'permissions' => [
				[
					'class' => ReshareSharePermissionType::class,
					'display_name' => 'Reshare',
					'category' => ShareSharePermissionCategoryType::class,
					'enabled' => false,
				],
				[
					'class' => TestSharePermissionType1::class,
					'display_name' => 'TestSharePermissionType1',
					'category' => TestSharePermissionCategoryType1::class,
					'enabled' => false,
				],
			],
		], $share);
	}

	public function testRemoveShareSource(): void {
		$this->register();

		$accessContext = new ShareAccessContext($this->owner);

		$id = $this->manager->createShare($accessContext);
		$this->manager->getShare($accessContext, $id);
		$this->manager->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));
		$this->manager->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType2::class, 'source2'));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));
		$this->manager->updateSharePermission($accessContext, $id, new SharePermission(TestSharePermissionType1::class, true));
		$this->manager->updateShareState($accessContext, $id, ShareState::Active);

		$before = $this->getTimestamp();
		$share = $this->removeShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));
		$after = $this->getTimestamp();
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		unset($share['last_updated']);
		$this->assertEquals([
			'id' => $id,
			'owner' => [
				'user_id' => 'owner',
				'display_name' => 'Owner',
				'icon' => [
					'light' => 'http://localhost/index.php/avatar/owner/64',
					'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
				],
			],
			'state' => ShareState::Active->value,
			'sources' => [
				[
					'class' => TestShareSourceType2::class,
					'value' => 'source2',
					'display_name' => 'Source 2',
				],
			],
			'recipients' => [
				[
					'class' => TestShareRecipientType1::class,
					'value' => 'recipient1',
					'display_name' => 'Recipient 1',
				],
			],
			'properties' => [],
			'permissions' => [
				[
					'class' => ReshareSharePermissionType::class,
					'display_name' => 'Reshare',
					'category' => ShareSharePermissionCategoryType::class,
					'enabled' => false,
				],
				[
					'class' => TestSharePermissionType2::class,
					'display_name' => 'TestSharePermissionType2',
					'category' => TestSharePermissionCategoryType2::class,
					'enabled' => false,
				],
			],
		], $share);

		$before = $this->getTimestamp();
		$share = $this->removeShareSource($accessContext, $id, new ShareSource(TestShareSourceType2::class, 'source2'));
		$after = $this->getTimestamp();
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		unset($share['last_updated']);
		$this->assertEquals([
			'id' => $id,
			'owner' => [
				'user_id' => 'owner',
				'display_name' => 'Owner',
				'icon' => [
					'light' => 'http://localhost/index.php/avatar/owner/64',
					'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
				],
			],
			'state' => ShareState::Draft->value,
			'sources' => [],
			'recipients' => [
				[
					'class' => TestShareRecipientType1::class,
					'value' => 'recipient1',
					'display_name' => 'Recipient 1',
				],
			],
			'properties' => [],
			'permissions' => [
				[
					'class' => ReshareSharePermissionType::class,
					'display_name' => 'Reshare',
					'category' => ShareSharePermissionCategoryType::class,
					'enabled' => false,
				],
			],
		], $share);
	}

	public function testAddShareRecipient(): void {
		$this->register();

		$accessContext = new ShareAccessContext($this->owner);

		$id = $this->manager->createShare($accessContext);

		$before = $this->getTimestamp();
		$share = $this->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));
		$after = $this->getTimestamp();
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		unset($share['last_updated']);
		$this->assertEquals([
			'id' => $id,
			'owner' => [
				'user_id' => 'owner',
				'display_name' => 'Owner',
				'icon' => [
					'light' => 'http://localhost/index.php/avatar/owner/64',
					'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
				],
			],
			'state' => ShareState::Draft->value,
			'sources' => [],
			'recipients' => [
				[
					'class' => TestShareRecipientType1::class,
					'value' => 'recipient1',
					'display_name' => 'Recipient 1',
				],
			],
			'properties' => [],
			'permissions' => [
				[
					'class' => ReshareSharePermissionType::class,
					'display_name' => 'Reshare',
					'category' => ShareSharePermissionCategoryType::class,
					'enabled' => false,
				],
			],
		], $share);
	}

	public function testAddChildShareRecipientWithoutResharePermission(): void {
		$this->register();

		$accessContext = new ShareAccessContext($this->owner);

		$id = $this->manager->createShare($accessContext);
		$this->manager->getShare($accessContext, $id);
		$this->manager->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));
		$this->manager->updateSharePermission($accessContext, $id, new SharePermission(TestSharePermissionType1::class, true));
		$this->manager->updateShareState($accessContext, $id, ShareState::Active);

		$this->expectExceptionMessage('Share operation forbidden: ' . $id);
		$this->addShareRecipient(new ShareAccessContext($this->user1), $id, new ShareRecipient(TestShareRecipientType2::class, 'recipient2', null));
	}

	public function testAddChildShareRecipientWithResharePermission(): void {
		$this->register();

		$accessContext = new ShareAccessContext($this->owner);

		$id = $this->manager->createShare($accessContext);
		$this->manager->getShare($accessContext, $id);
		$this->manager->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));
		$this->manager->updateSharePermission($accessContext, $id, new SharePermission(ReshareSharePermissionType::class, true));
		$this->manager->updateShareState($accessContext, $id, ShareState::Active);

		$before = $this->getTimestamp();
		$share = $this->addShareRecipient(new ShareAccessContext($this->user1), $id, new ShareRecipient(TestShareRecipientType2::class, 'recipient2', null));
		$after = $this->getTimestamp();
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		unset($share['last_updated']);
		$this->assertEquals([
			'id' => $id,
			'owner' => [
				'user_id' => 'owner',
				'display_name' => 'Owner',
				'icon' => [
					'light' => 'http://localhost/index.php/avatar/owner/64',
					'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
				],
			],
			'state' => ShareState::Active->value,
			'sources' => [
				[
					'class' => TestShareSourceType1::class,
					'value' => 'source1',
					'display_name' => 'Source 1',
				],
			],
			'recipients' => [
				[
					'class' => TestShareRecipientType1::class,
					'value' => 'recipient1',
					'display_name' => 'Recipient 1',
				],
				[
					'class' => TestShareRecipientType2::class,
					'value' => 'recipient2',
					'display_name' => 'Recipient 2',
				],
			],
			'properties' => [
				[
					'class' => TestSharePropertyType1::class,
					'display_name' => 'TestSharePropertyType1',
					'priority' => 1,
					'required' => false,
					'value' => null,
					'type' => 'enum',
					'valid_values' => ['valid1'],
				],
			],
			'permissions' => [
				[
					'class' => ReshareSharePermissionType::class,
					'display_name' => 'Reshare',
					'category' => ShareSharePermissionCategoryType::class,
					'enabled' => true,
				],
				[
					'class' => TestSharePermissionType1::class,
					'display_name' => 'TestSharePermissionType1',
					'category' => TestSharePermissionCategoryType1::class,
					'enabled' => false,
				],
			],
		], $share);
	}

	public function testRemoveShareRecipient(): void {
		$this->register();

		$accessContext = new ShareAccessContext($this->owner);

		$id = $this->manager->createShare($accessContext);
		$this->manager->getShare($accessContext, $id);
		$this->manager->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType2::class, 'recipient2', null));
		$this->manager->updateSharePermission($accessContext, $id, new SharePermission(TestSharePermissionType1::class, true));
		$this->manager->updateShareState($accessContext, $id, ShareState::Active);

		$before = $this->getTimestamp();
		$share = $this->removeShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));
		$after = $this->getTimestamp();
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		unset($share['last_updated']);
		$this->assertEquals([
			'id' => $id,
			'owner' => [
				'user_id' => 'owner',
				'display_name' => 'Owner',
				'icon' => [
					'light' => 'http://localhost/index.php/avatar/owner/64',
					'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
				],
			],
			'state' => ShareState::Active->value,
			'sources' => [
				[
					'class' => TestShareSourceType1::class,
					'value' => 'source1',
					'display_name' => 'Source 1',
				],
			],
			'recipients' => [
				[
					'class' => TestShareRecipientType2::class,
					'value' => 'recipient2',
					'display_name' => 'Recipient 2',
				],
			],
			'properties' => [],
			'permissions' => [
				[
					'class' => ReshareSharePermissionType::class,
					'display_name' => 'Reshare',
					'category' => ShareSharePermissionCategoryType::class,
					'enabled' => false,
				],
				[
					'class' => TestSharePermissionType1::class,
					'display_name' => 'TestSharePermissionType1',
					'category' => TestSharePermissionCategoryType1::class,
					'enabled' => true,
				],
			],
		], $share);

		$before = $this->getTimestamp();
		$share = $this->removeShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType2::class, 'recipient2', null));
		$after = $this->getTimestamp();
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		unset($share['last_updated']);
		$this->assertEquals([
			'id' => $id,
			'owner' => [
				'user_id' => 'owner',
				'display_name' => 'Owner',
				'icon' => [
					'light' => 'http://localhost/index.php/avatar/owner/64',
					'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
				],
			],
			'state' => ShareState::Draft->value,
			'sources' => [
				[
					'class' => TestShareSourceType1::class,
					'value' => 'source1',
					'display_name' => 'Source 1',
				],
			],
			'recipients' => [],
			'properties' => [],
			'permissions' => [
				[
					'class' => ReshareSharePermissionType::class,
					'display_name' => 'Reshare',
					'category' => ShareSharePermissionCategoryType::class,
					'enabled' => false,
				],
				[
					'class' => TestSharePermissionType1::class,
					'display_name' => 'TestSharePermissionType1',
					'category' => TestSharePermissionCategoryType1::class,
					'enabled' => true,
				],
			],
		], $share);
	}

	public function testRemoveSelfShareRecipientWithoutResharePermission(): void {
		$this->register();

		$accessContext = new ShareAccessContext($this->owner);

		$id = $this->manager->createShare($accessContext);
		$this->manager->getShare($accessContext, $id);
		$this->manager->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));
		$this->manager->updateSharePermission($accessContext, $id, new SharePermission(TestSharePermissionType1::class, true));
		$this->manager->updateShareState($accessContext, $id, ShareState::Active);

		$this->expectExceptionMessage('Share operation forbidden: ' . $id);
		$this->removeShareRecipient(new ShareAccessContext($this->user1), $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));
	}

	public function testRemoveSelfShareRecipientWithResharePermission(): void {
		$this->register();

		$accessContext = new ShareAccessContext($this->owner);

		$id = $this->manager->createShare($accessContext);
		$this->manager->getShare($accessContext, $id);
		$this->manager->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));
		$this->manager->updateSharePermission($accessContext, $id, new SharePermission(ReshareSharePermissionType::class, true));
		$this->manager->updateShareState($accessContext, $id, ShareState::Active);

		$this->expectExceptionMessage('Share not found: ' . $id);
		$this->removeShareRecipient(new ShareAccessContext($this->user1), $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));
	}

	public function testRemoveChildShareRecipientWithoutResharePermission(): void {
		$this->register();

		$accessContext = new ShareAccessContext($this->owner);

		$id = $this->manager->createShare($accessContext);
		$this->manager->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));
		$this->manager->getShare($accessContext, $id);
		$this->manager->updateSharePermission($accessContext, $id, new SharePermission(TestSharePermissionType1::class, true));
		$this->manager->updateSharePermission($accessContext, $id, new SharePermission(ReshareSharePermissionType::class, true));
		$this->manager->updateShareState($accessContext, $id, ShareState::Active);
		$this->manager->addShareRecipient(new ShareAccessContext($this->user1), $id, new ShareRecipient(TestShareRecipientType2::class, 'recipient2', null));
		$this->manager->updateSharePermission($accessContext, $id, new SharePermission(ReshareSharePermissionType::class, false));

		$this->expectExceptionMessage('Share operation forbidden: ' . $id);
		$this->removeShareRecipient(new ShareAccessContext($this->user1), $id, new ShareRecipient(TestShareRecipientType2::class, 'recipient2', null));
	}

	public function testRemoveChildShareRecipientWithResharePermission(): void {
		$this->register();

		$accessContext = new ShareAccessContext($this->owner);

		$id = $this->manager->createShare($accessContext);
		$this->manager->getShare($accessContext, $id);
		$this->manager->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));
		$this->manager->updateSharePermission($accessContext, $id, new SharePermission(ReshareSharePermissionType::class, true));
		$this->manager->updateShareState($accessContext, $id, ShareState::Active);
		$this->manager->addShareRecipient(new ShareAccessContext($this->user1), $id, new ShareRecipient(TestShareRecipientType2::class, 'recipient2', null));

		$before = $this->getTimestamp();
		$share = $this->removeShareRecipient(new ShareAccessContext($this->user1), $id, new ShareRecipient(TestShareRecipientType2::class, 'recipient2', null));
		$after = $this->getTimestamp();
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		unset($share['last_updated']);
		$this->assertEquals([
			'id' => $id,
			'owner' => [
				'user_id' => 'owner',
				'display_name' => 'Owner',
				'icon' => [
					'light' => 'http://localhost/index.php/avatar/owner/64',
					'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
				],
			],
			'state' => ShareState::Active->value,
			'sources' => [
				[
					'class' => TestShareSourceType1::class,
					'value' => 'source1',
					'display_name' => 'Source 1',
				],
			],
			'recipients' => [
				[
					'class' => TestShareRecipientType1::class,
					'value' => 'recipient1',
					'display_name' => 'Recipient 1',
				],
			],
			'properties' => [
				[
					'class' => TestSharePropertyType1::class,
					'display_name' => 'TestSharePropertyType1',
					'priority' => 1,
					'required' => false,
					'value' => null,
					'type' => 'enum',
					'valid_values' => ['valid1'],
				],
			],
			'permissions' => [
				[
					'class' => ReshareSharePermissionType::class,
					'display_name' => 'Reshare',
					'category' => ShareSharePermissionCategoryType::class,
					'enabled' => true,
				],
				[
					'class' => TestSharePermissionType1::class,
					'display_name' => 'TestSharePermissionType1',
					'category' => TestSharePermissionCategoryType1::class,
					'enabled' => false,
				],
			],
		], $share);
	}

	public function testRemoveSiblingShareRecipientWithoutResharePermission(): void {
		$this->register();

		$accessContext = new ShareAccessContext($this->owner);

		$id = $this->manager->createShare($accessContext);
		$this->manager->getShare($accessContext, $id);
		$this->manager->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType2::class, 'recipient2', null));
		$this->manager->updateSharePermission($accessContext, $id, new SharePermission(TestSharePermissionType1::class, true));
		$this->manager->updateShareState($accessContext, $id, ShareState::Active);

		$this->expectExceptionMessage('Share operation forbidden: ' . $id);
		$this->removeShareRecipient(new ShareAccessContext($this->user1), $id, new ShareRecipient(TestShareRecipientType2::class, 'recipient2', null));
	}

	public function testRemoveSiblingShareRecipientWithResharePermission(): void {
		$this->register();

		$accessContext = new ShareAccessContext($this->owner);

		$id = $this->manager->createShare($accessContext);
		$this->manager->getShare($accessContext, $id);
		$this->manager->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType2::class, 'recipient2', null));
		$this->manager->updateSharePermission($accessContext, $id, new SharePermission(ReshareSharePermissionType::class, true));
		$this->manager->updateShareState($accessContext, $id, ShareState::Active);

		$this->expectExceptionMessage('Share operation forbidden: ' . $id);
		$this->removeShareRecipient(new ShareAccessContext($this->user1), $id, new ShareRecipient(TestShareRecipientType2::class, 'recipient2', null));
	}

	public function testRemoveParentShareRecipientWithoutResharePermission(): void {
		$this->register();

		$accessContext = new ShareAccessContext($this->owner);

		$id = $this->manager->createShare($accessContext);
		$this->manager->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));
		$this->manager->getShare($accessContext, $id);
		$this->manager->updateSharePermission($accessContext, $id, new SharePermission(TestSharePermissionType1::class, true));
		$this->manager->updateSharePermission($accessContext, $id, new SharePermission(ReshareSharePermissionType::class, true));
		$this->manager->updateShareState($accessContext, $id, ShareState::Active);
		$this->manager->addShareRecipient(new ShareAccessContext($this->user1), $id, new ShareRecipient(TestShareRecipientType2::class, 'recipient2', null));
		$this->manager->updateSharePermission($accessContext, $id, new SharePermission(ReshareSharePermissionType::class, false));

		$this->expectExceptionMessage('Share operation forbidden: ' . $id);
		$this->removeShareRecipient(new ShareAccessContext($this->user2), $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));
	}

	public function testRemoveParentShareRecipientWithResharePermission(): void {
		$this->register();

		$accessContext = new ShareAccessContext($this->owner);

		$id = $this->manager->createShare($accessContext);
		$this->manager->getShare($accessContext, $id);
		$this->manager->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));
		$this->manager->updateSharePermission($accessContext, $id, new SharePermission(ReshareSharePermissionType::class, true));
		$this->manager->updateShareState($accessContext, $id, ShareState::Active);
		$this->manager->addShareRecipient(new ShareAccessContext($this->user1), $id, new ShareRecipient(TestShareRecipientType2::class, 'recipient2', null));

		$this->expectExceptionMessage('Share operation forbidden: ' . $id);
		$this->removeShareRecipient(new ShareAccessContext($this->user2), $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));
	}

	/**
	 * @return list<array{list<?string>}>
	 */
	public static function dataProviderUpdateShareProperty(): array {
		return [
			[[null, 'valid1']],
			[['valid1', null]],
		];
	}

	/**
	 * @param list<?string> $values
	 */
	#[DataProvider('dataProviderUpdateShareProperty')]
	public function testUpdateShareProperty(array $values): void {
		$this->register();

		$accessContext = new ShareAccessContext($this->owner);

		$id = $this->manager->createShare($accessContext);
		$this->manager->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));
		$this->manager->getShare($accessContext, $id);

		foreach ($values as $value) {
			$before = $this->getTimestamp();
			$share = $this->updateShareProperty($accessContext, $id, new ShareProperty(TestSharePropertyType1::class, $value));
			$after = $this->getTimestamp();
			$this->assertGreaterThanOrEqual($before, $share['last_updated']);
			$this->assertLessThanOrEqual($after, $share['last_updated']);
			unset($share['last_updated']);
			$this->assertEquals([
				'id' => $id,
				'owner' => [
					'user_id' => 'owner',
					'display_name' => 'Owner',
					'icon' => [
						'light' => 'http://localhost/index.php/avatar/owner/64',
						'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
					],
				],
				'state' => ShareState::Draft->value,
				'sources' => [
					[
						'class' => TestShareSourceType1::class,
						'value' => 'source1',
						'display_name' => 'Source 1',
					],
				],
				'recipients' => [
					[
						'class' => TestShareRecipientType1::class,
						'value' => 'recipient1',
						'display_name' => 'Recipient 1',
					],
				],
				'properties' => [
					[
						'class' => TestSharePropertyType1::class,
						'display_name' => 'TestSharePropertyType1',
						'priority' => 1,
						'required' => false,
						'value' => $value,
						'type' => 'enum',
						'valid_values' => ['valid1'],
					],
				],
				'permissions' => [
					[
						'class' => ReshareSharePermissionType::class,
						'display_name' => 'Reshare',
						'category' => ShareSharePermissionCategoryType::class,
						'enabled' => false,
					],
					[
						'class' => TestSharePermissionType1::class,
						'display_name' => 'TestSharePermissionType1',
						'category' => TestSharePermissionCategoryType1::class,
						'enabled' => false,
					],
				],
			], $share);
		}
	}

	public function testUpdateSharePropertyRequired(): void {
		$this->register();
		$this->registry->registerPropertyType(new TestSharePropertyTypeRequired(['valid1', 'valid2']));
		$this->registry->markPropertyTypeCompatibleWithSourceType(TestSharePropertyTypeRequired::class, TestShareSourceType1::class);
		$this->registry->markPropertyTypeCompatibleWithRecipientType(TestSharePropertyTypeRequired::class, TestShareRecipientType1::class);

		$accessContext = new ShareAccessContext($this->owner);

		$id = $this->manager->createShare($accessContext);
		$this->manager->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));
		$this->manager->getShare($accessContext, $id);
		$this->manager->updateSharePermission($accessContext, $id, new SharePermission(TestSharePermissionType1::class, true));

		$before = $this->getTimestamp();
		$share = $this->updateShareProperty($accessContext, $id, new ShareProperty(TestSharePropertyTypeRequired::class, 'valid1'));
		$after = $this->getTimestamp();
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		unset($share['last_updated']);
		$this->assertEquals([
			'id' => $id,
			'owner' => [
				'user_id' => 'owner',
				'display_name' => 'Owner',
				'icon' => [
					'light' => 'http://localhost/index.php/avatar/owner/64',
					'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
				],
			],
			'state' => ShareState::Draft->value,
			'sources' => [
				[
					'class' => TestShareSourceType1::class,
					'value' => 'source1',
					'display_name' => 'Source 1',
				],
			],
			'recipients' => [
				[
					'class' => TestShareRecipientType1::class,
					'value' => 'recipient1',
					'display_name' => 'Recipient 1',
				],
			],
			'properties' => [
				[
					'class' => TestSharePropertyType1::class,
					'display_name' => 'TestSharePropertyType1',
					'priority' => 1,
					'required' => false,
					'value' => null,
					'type' => 'enum',
					'valid_values' => ['valid1'],
				],
				[
					'class' => TestSharePropertyTypeRequired::class,
					'display_name' => 'TestSharePropertyTypeRequired',
					'priority' => 1,
					'required' => true,
					'value' => 'valid1',
					'type' => 'enum',
					'valid_values' => ['valid1', 'valid2'],
				],
			],
			'permissions' => [
				[
					'class' => ReshareSharePermissionType::class,
					'display_name' => 'Reshare',
					'category' => ShareSharePermissionCategoryType::class,
					'enabled' => false,
				],
				[
					'class' => TestSharePermissionType1::class,
					'display_name' => 'TestSharePermissionType1',
					'category' => TestSharePermissionCategoryType1::class,
					'enabled' => true,
				],
			],
		], $share);

		$this->manager->updateShareState($accessContext, $id, ShareState::Active);

		$before = $this->getTimestamp();
		$share = $this->updateShareProperty($accessContext, $id, new ShareProperty(TestSharePropertyTypeRequired::class, 'valid2'));
		$after = $this->getTimestamp();
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		unset($share['last_updated']);
		$this->assertEquals([
			'id' => $id,
			'owner' => [
				'user_id' => 'owner',
				'display_name' => 'Owner',
				'icon' => [
					'light' => 'http://localhost/index.php/avatar/owner/64',
					'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
				],
			],
			'state' => ShareState::Active->value,
			'sources' => [
				[
					'class' => TestShareSourceType1::class,
					'value' => 'source1',
					'display_name' => 'Source 1',
				],
			],
			'recipients' => [
				[
					'class' => TestShareRecipientType1::class,
					'value' => 'recipient1',
					'display_name' => 'Recipient 1',
				],
			],
			'properties' => [
				[
					'class' => TestSharePropertyType1::class,
					'display_name' => 'TestSharePropertyType1',
					'priority' => 1,
					'required' => false,
					'value' => null,
					'type' => 'enum',
					'valid_values' => ['valid1'],
				],
				[
					'class' => TestSharePropertyTypeRequired::class,
					'display_name' => 'TestSharePropertyTypeRequired',
					'priority' => 1,
					'required' => true,
					'value' => 'valid2',
					'type' => 'enum',
					'valid_values' => ['valid1', 'valid2'],
				],
			],
			'permissions' => [
				[
					'class' => ReshareSharePermissionType::class,
					'display_name' => 'Reshare',
					'category' => ShareSharePermissionCategoryType::class,
					'enabled' => false,
				],
				[
					'class' => TestSharePermissionType1::class,
					'display_name' => 'TestSharePermissionType1',
					'category' => TestSharePermissionCategoryType1::class,
					'enabled' => true,
				],
			],
		], $share);

		$before = $this->getTimestamp();
		$share = $this->updateShareProperty($accessContext, $id, new ShareProperty(TestSharePropertyTypeRequired::class, null));
		$after = $this->getTimestamp();
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		unset($share['last_updated']);
		$this->assertEquals([
			'id' => $id,
			'owner' => [
				'user_id' => 'owner',
				'display_name' => 'Owner',
				'icon' => [
					'light' => 'http://localhost/index.php/avatar/owner/64',
					'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
				],
			],
			'state' => ShareState::Draft->value,
			'sources' => [
				[
					'class' => TestShareSourceType1::class,
					'value' => 'source1',
					'display_name' => 'Source 1',
				],
			],
			'recipients' => [
				[
					'class' => TestShareRecipientType1::class,
					'value' => 'recipient1',
					'display_name' => 'Recipient 1',
				],
			],
			'properties' => [
				[
					'class' => TestSharePropertyType1::class,
					'display_name' => 'TestSharePropertyType1',
					'priority' => 1,
					'required' => false,
					'value' => null,
					'type' => 'enum',
					'valid_values' => ['valid1'],
				],
				[
					'class' => TestSharePropertyTypeRequired::class,
					'display_name' => 'TestSharePropertyTypeRequired',
					'priority' => 1,
					'required' => true,
					'value' => null,
					'type' => 'enum',
					'valid_values' => ['valid1', 'valid2'],
				],
			],
			'permissions' => [
				[
					'class' => ReshareSharePermissionType::class,
					'display_name' => 'Reshare',
					'category' => ShareSharePermissionCategoryType::class,
					'enabled' => false,
				],
				[
					'class' => TestSharePermissionType1::class,
					'display_name' => 'TestSharePermissionType1',
					'category' => TestSharePermissionCategoryType1::class,
					'enabled' => true,
				],
			],
		], $share);
	}

	public function testUpdateSharePropertyModifyProperties(): void {
		$this->register();
		$this->registry->registerPropertyType(new TestSharePropertyTypeModifyValue());
		$this->registry->markPropertyTypeCompatibleWithSourceType(TestSharePropertyTypeModifyValue::class, TestShareSourceType1::class);
		$this->registry->markPropertyTypeCompatibleWithRecipientType(TestSharePropertyTypeModifyValue::class, TestShareRecipientType1::class);

		$accessContext = new ShareAccessContext($this->owner);

		$id = $this->manager->createShare($accessContext);
		$this->manager->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));
		$this->manager->getShare($accessContext, $id);
		$this->manager->updateShareProperty($accessContext, $id, new ShareProperty(TestSharePropertyTypeModifyValue::class, 'old-value'));

		$before = $this->getTimestamp();
		$share = $this->updateShareProperty($accessContext, $id, new ShareProperty(TestSharePropertyTypeModifyValue::class, 'modify-on-save-old-value'));
		$after = $this->getTimestamp();
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		unset($share['last_updated']);
		$this->assertEquals([
			'id' => $id,
			'owner' => [
				'user_id' => 'owner',
				'display_name' => 'Owner',
				'icon' => [
					'light' => 'http://localhost/index.php/avatar/owner/64',
					'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
				],
			],
			'state' => ShareState::Draft->value,
			'sources' => [
				[
					'class' => TestShareSourceType1::class,
					'value' => 'source1',
					'display_name' => 'Source 1',
				],
			],
			'recipients' => [
				[
					'class' => TestShareRecipientType1::class,
					'value' => 'recipient1',
					'display_name' => 'Recipient 1',
				],
			],
			'properties' => [
				[
					'class' => TestSharePropertyType1::class,
					'display_name' => 'TestSharePropertyType1',
					'priority' => 1,
					'required' => false,
					'value' => null,
					'type' => 'enum',
					'valid_values' => ['valid1'],
				],
				[
					'class' => TestSharePropertyTypeModifyValue::class,
					'display_name' => 'TestSharePropertyTypeModifyValue',
					'priority' => 1,
					'required' => false,
					'value' => 'old-value',
					'type' => 'string',
				],
			],
			'permissions' => [
				[
					'class' => ReshareSharePermissionType::class,
					'display_name' => 'Reshare',
					'category' => ShareSharePermissionCategoryType::class,
					'enabled' => false,
				],
				[
					'class' => TestSharePermissionType1::class,
					'display_name' => 'TestSharePermissionType1',
					'category' => TestSharePermissionCategoryType1::class,
					'enabled' => false,
				],
			],
		], $share);

		$before = $this->getTimestamp();
		$share = $this->updateShareProperty($accessContext, $id, new ShareProperty(TestSharePropertyTypeModifyValue::class, 'modify-on-save'));
		$after = $this->getTimestamp();
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		unset($share['last_updated']);
		$this->assertEquals([
			'id' => $id,
			'owner' => [
				'user_id' => 'owner',
				'display_name' => 'Owner',
				'icon' => [
					'light' => 'http://localhost/index.php/avatar/owner/64',
					'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
				],
			],
			'state' => ShareState::Draft->value,
			'sources' => [
				[
					'class' => TestShareSourceType1::class,
					'value' => 'source1',
					'display_name' => 'Source 1',
				],
			],
			'recipients' => [
				[
					'class' => TestShareRecipientType1::class,
					'value' => 'recipient1',
					'display_name' => 'Recipient 1',
				],
			],
			'properties' => [
				[
					'class' => TestSharePropertyType1::class,
					'display_name' => 'TestSharePropertyType1',
					'priority' => 1,
					'required' => false,
					'value' => null,
					'type' => 'enum',
					'valid_values' => ['valid1'],
				],
				[
					'class' => TestSharePropertyTypeModifyValue::class,
					'display_name' => 'TestSharePropertyTypeModifyValue',
					'priority' => 1,
					'required' => false,
					'value' => 'modified-on-save',
					'type' => 'string',
				],
			],
			'permissions' => [
				[
					'class' => ReshareSharePermissionType::class,
					'display_name' => 'Reshare',
					'category' => ShareSharePermissionCategoryType::class,
					'enabled' => false,
				],
				[
					'class' => TestSharePermissionType1::class,
					'display_name' => 'TestSharePermissionType1',
					'category' => TestSharePermissionCategoryType1::class,
					'enabled' => false,
				],
			],
		], $share);

		$before = $this->getTimestamp();
		$share = $this->updateShareProperty($accessContext, $id, new ShareProperty(TestSharePropertyTypeModifyValue::class, 'modify-on-load'));
		$after = $this->getTimestamp();
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		unset($share['last_updated']);
		$this->assertEquals([
			'id' => $id,
			'owner' => [
				'user_id' => 'owner',
				'display_name' => 'Owner',
				'icon' => [
					'light' => 'http://localhost/index.php/avatar/owner/64',
					'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
				],
			],
			'state' => ShareState::Draft->value,
			'sources' => [
				[
					'class' => TestShareSourceType1::class,
					'value' => 'source1',
					'display_name' => 'Source 1',
				],
			],
			'recipients' => [
				[
					'class' => TestShareRecipientType1::class,
					'value' => 'recipient1',
					'display_name' => 'Recipient 1',
				],
			],
			'properties' => [
				[
					'class' => TestSharePropertyType1::class,
					'display_name' => 'TestSharePropertyType1',
					'priority' => 1,
					'required' => false,
					'value' => null,
					'type' => 'enum',
					'valid_values' => ['valid1'],
				],
				[
					'class' => TestSharePropertyTypeModifyValue::class,
					'display_name' => 'TestSharePropertyTypeModifyValue',
					'priority' => 1,
					'required' => false,
					'value' => 'modified-on-load',
					'type' => 'string',
				],
			],
			'permissions' => [
				[
					'class' => ReshareSharePermissionType::class,
					'display_name' => 'Reshare',
					'category' => ShareSharePermissionCategoryType::class,
					'enabled' => false,
				],
				[
					'class' => TestSharePermissionType1::class,
					'display_name' => 'TestSharePermissionType1',
					'category' => TestSharePermissionCategoryType1::class,
					'enabled' => false,
				],
			],
		], $share);
	}

	public function testUpdateSharePermission(): void {
		$this->register();

		$accessContext = new ShareAccessContext($this->owner);

		$id = $this->manager->createShare($accessContext);
		$this->manager->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));
		$this->manager->getShare($accessContext, $id);

		$before = $this->getTimestamp();
		$share = $this->updateSharePermission($accessContext, $id, new SharePermission(TestSharePermissionType1::class, true));
		$after = $this->getTimestamp();
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		unset($share['last_updated']);
		$this->assertEquals([
			'id' => $id,
			'owner' => [
				'user_id' => 'owner',
				'display_name' => 'Owner',
				'icon' => [
					'light' => 'http://localhost/index.php/avatar/owner/64',
					'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
				],
			],
			'state' => ShareState::Draft->value,
			'sources' => [
				[
					'class' => TestShareSourceType1::class,
					'value' => 'source1',
					'display_name' => 'Source 1',
				],
			],
			'recipients' => [
				[
					'class' => TestShareRecipientType1::class,
					'value' => 'recipient1',
					'display_name' => 'Recipient 1',
				],
			],
			'properties' => [
				[
					'class' => TestSharePropertyType1::class,
					'display_name' => 'TestSharePropertyType1',
					'priority' => 1,
					'required' => false,
					'value' => null,
					'type' => 'enum',
					'valid_values' => ['valid1'],
				],
			],
			'permissions' => [
				[
					'class' => ReshareSharePermissionType::class,
					'display_name' => 'Reshare',
					'category' => ShareSharePermissionCategoryType::class,
					'enabled' => false,
				],
				[
					'class' => TestSharePermissionType1::class,
					'display_name' => 'TestSharePermissionType1',
					'category' => TestSharePermissionCategoryType1::class,
					'enabled' => true,
				],
			],
		], $share);

		$this->manager->updateShareState($accessContext, $id, ShareState::Active);

		$before = $this->getTimestamp();
		$share = $this->updateSharePermission($accessContext, $id, new SharePermission(TestSharePermissionType1::class, false));
		$after = $this->getTimestamp();
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		unset($share['last_updated']);
		$this->assertEquals([
			'id' => $id,
			'owner' => [
				'user_id' => 'owner',
				'display_name' => 'Owner',
				'icon' => [
					'light' => 'http://localhost/index.php/avatar/owner/64',
					'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
				],
			],
			'state' => ShareState::Draft->value,
			'sources' => [
				[
					'class' => TestShareSourceType1::class,
					'value' => 'source1',
					'display_name' => 'Source 1',
				],
			],
			'recipients' => [
				[
					'class' => TestShareRecipientType1::class,
					'value' => 'recipient1',
					'display_name' => 'Recipient 1',
				],
			],
			'properties' => [
				[
					'class' => TestSharePropertyType1::class,
					'display_name' => 'TestSharePropertyType1',
					'priority' => 1,
					'required' => false,
					'value' => null,
					'type' => 'enum',
					'valid_values' => ['valid1'],
				],
			],
			'permissions' => [
				[
					'class' => ReshareSharePermissionType::class,
					'display_name' => 'Reshare',
					'category' => ShareSharePermissionCategoryType::class,
					'enabled' => false,
				],
				[
					'class' => TestSharePermissionType1::class,
					'display_name' => 'TestSharePermissionType1',
					'category' => TestSharePermissionCategoryType1::class,
					'enabled' => false,
				],
			],
		], $share);
	}

	public function testDeleteShare(): void {
		$this->register();

		$accessContext = new ShareAccessContext($this->owner);

		$id = $this->manager->createShare($accessContext);

		$this->deleteShare($accessContext, $id);

		$this->expectExceptionMessage('Share not found: ' . $id);
		$this->manager->getShare(new ShareAccessContext(force: true), $id);
	}

	public function testGetShare(): void {
		$this->register();

		$accessContext = new ShareAccessContext($this->owner);

		$before = $this->getTimestamp();
		$id = $this->manager->createShare($accessContext);
		$this->manager->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));
		$this->manager->getShare($accessContext, $id);

		$after = $this->getTimestamp();

		$share = $this->getShare($accessContext, $id);
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		unset($share['last_updated']);
		$this->assertEquals([
			'id' => $id,
			'owner' => [
				'user_id' => 'owner',
				'display_name' => 'Owner',
				'icon' => [
					'light' => 'http://localhost/index.php/avatar/owner/64',
					'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
				],
			],
			'state' => ShareState::Draft->value,
			'sources' => [
				[
					'class' => TestShareSourceType1::class,
					'value' => 'source1',
					'display_name' => 'Source 1',
				],
			],
			'recipients' => [
				[
					'class' => TestShareRecipientType1::class,
					'value' => 'recipient1',
					'display_name' => 'Recipient 1',
				],
			],
			'properties' => [
				[
					'class' => TestSharePropertyType1::class,
					'display_name' => 'TestSharePropertyType1',
					'priority' => 1,
					'required' => false,
					'value' => null,
					'type' => 'enum',
					'valid_values' => ['valid1'],
				],
			],
			'permissions' => [
				[
					'class' => ReshareSharePermissionType::class,
					'display_name' => 'Reshare',
					'category' => ShareSharePermissionCategoryType::class,
					'enabled' => false,
				],
				[
					'class' => TestSharePermissionType1::class,
					'display_name' => 'TestSharePermissionType1',
					'category' => TestSharePermissionCategoryType1::class,
					'enabled' => false,
				],
			],
		], $share);
	}

	public function testGetShareAsRecipientNotActive(): void {
		$this->register();

		$accessContext = new ShareAccessContext($this->owner);

		$id = $this->manager->createShare($accessContext);
		$this->manager->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));

		$this->expectExceptionMessage('Share not found: ' . $id);
		$this->getShare(new ShareAccessContext(currentUser: $this->user1), $id);
	}

	public function testGetShareAsRecipientActive(): void {
		$this->register();

		$accessContext = new ShareAccessContext($this->owner);

		$before = $this->getTimestamp();
		$id = $this->manager->createShare($accessContext);
		$this->manager->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType2::class, 'recipient2', null));
		$this->manager->updateSharePermission($accessContext, $id, new SharePermission(TestSharePermissionType1::class, true));
		$this->manager->updateShareState($accessContext, $id, ShareState::Active);

		$after = $this->getTimestamp();

		$share = $this->getShare(new ShareAccessContext(currentUser: $this->user1), $id);
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		unset($share['last_updated']);
		$this->assertEquals([
			'id' => $id,
			'owner' => [
				'user_id' => 'owner',
				'display_name' => 'Owner',
				'icon' => [
					'light' => 'http://localhost/index.php/avatar/owner/64',
					'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
				],
			],
			'state' => ShareState::Active->value,
			'sources' => [
				[
					'class' => TestShareSourceType1::class,
					'value' => 'source1',
					'display_name' => 'Source 1',
				],
			],
			'recipients' => [
				[
					'class' => TestShareRecipientType1::class,
					'value' => 'recipient1',
					'display_name' => 'Recipient 1',
				],
			],
			'properties' => [
				[
					'class' => TestSharePropertyType1::class,
					'display_name' => 'TestSharePropertyType1',
					'priority' => 1,
					'required' => false,
					'value' => null,
					'type' => 'enum',
					'valid_values' => ['valid1'],
				],
			],
			'permissions' => [
				[
					'class' => ReshareSharePermissionType::class,
					'display_name' => 'Reshare',
					'category' => ShareSharePermissionCategoryType::class,
					'enabled' => false,
				],
				[
					'class' => TestSharePermissionType1::class,
					'display_name' => 'TestSharePermissionType1',
					'category' => TestSharePermissionCategoryType1::class,
					'enabled' => true,
				],
			],
		], $share);
	}

	public function testGetShareAsRecipientWithArguments(): void {
		$this->register();
		$this->registry->registerRecipientType(new TestShareRecipientTypeArguments());

		$accessContext = new ShareAccessContext($this->owner);

		$before = $this->getTimestamp();
		$id = $this->manager->createShare($accessContext);
		$this->manager->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientTypeArguments::class, 'secret', null));
		$this->manager->updateSharePermission($accessContext, $id, new SharePermission(TestSharePermissionType1::class, true));
		$this->manager->updateShareState($accessContext, $id, ShareState::Active);

		$after = $this->getTimestamp();

		$share = $this->getShare(new ShareAccessContext(currentUser: $this->user1, arguments: [TestShareRecipientTypeArguments::class => 'secret']), $id);
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		unset($share['last_updated']);
		$this->assertEquals([
			'id' => $id,
			'owner' => [
				'user_id' => 'owner',
				'display_name' => 'Owner',
				'icon' => [
					'light' => 'http://localhost/index.php/avatar/owner/64',
					'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
				],
			],
			'state' => ShareState::Active->value,
			'sources' => [
				[
					'class' => TestShareSourceType1::class,
					'value' => 'source1',
					'display_name' => 'Source 1',
				],
			],
			'recipients' => [
				[
					'class' => TestShareRecipientTypeArguments::class,
					'value' => 'secret',
					'display_name' => 'secret',
				],
			],
			'properties' => [],
			'permissions' => [
				[
					'class' => ReshareSharePermissionType::class,
					'display_name' => 'Reshare',
					'category' => ShareSharePermissionCategoryType::class,
					'enabled' => false,
				],
				[
					'class' => TestSharePermissionType1::class,
					'display_name' => 'TestSharePermissionType1',
					'category' => TestSharePermissionCategoryType1::class,
					'enabled' => true,
				],
			],
		], $share);

		$this->expectExceptionMessage('Share not found: ' . $id);
		$this->getShare(new ShareAccessContext(currentUser: $this->user1), $id);
	}

	public function testGetShareAsNonRecipient(): void {
		$this->register();

		$accessContext = new ShareAccessContext($this->owner);

		$id = $this->manager->createShare($accessContext);
		$this->manager->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType2::class, 'recipient2', null));
		$this->manager->updateSharePermission($accessContext, $id, new SharePermission(TestSharePermissionType1::class, true));
		$this->manager->updateShareState($accessContext, $id, ShareState::Active);

		$this->expectExceptionMessage('Share not found: ' . $id);
		$this->getShare(new ShareAccessContext(currentUser: $this->user1), $id);
	}

	public function testGetShareAsRecipientFilteredProperties(): void {
		$this->register();
		$this->registry->registerPropertyType(new TestSharePropertyTypeFilter());
		$this->registry->markPropertyTypeCompatibleWithSourceType(TestSharePropertyTypeFilter::class, TestShareSourceType1::class);
		$this->registry->markPropertyTypeCompatibleWithRecipientType(TestSharePropertyTypeFilter::class, TestShareRecipientType1::class);

		$accessContext = new ShareAccessContext($this->owner);

		$before = $this->getTimestamp();
		$id = $this->manager->createShare($accessContext);
		$this->manager->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));
		$this->manager->updateSharePermission($accessContext, $id, new SharePermission(TestSharePermissionType1::class, true));
		$this->manager->updateShareState($accessContext, $id, ShareState::Active);
		$this->manager->getShare($accessContext, $id);
		$this->manager->updateShareProperty($accessContext, $id, new ShareProperty(TestSharePropertyTypeFilter::class, 'visible'));

		$after = $this->getTimestamp();

		$share = $this->getShare(new ShareAccessContext(currentUser: $this->user1), $id);
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		unset($share['last_updated']);
		$this->assertEquals([
			'id' => $id,
			'owner' => [
				'user_id' => 'owner',
				'display_name' => 'Owner',
				'icon' => [
					'light' => 'http://localhost/index.php/avatar/owner/64',
					'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
				],
			],
			'state' => ShareState::Active->value,
			'sources' => [
				[
					'class' => TestShareSourceType1::class,
					'value' => 'source1',
					'display_name' => 'Source 1',
				],
			],
			'recipients' => [
				[
					'class' => TestShareRecipientType1::class,
					'value' => 'recipient1',
					'display_name' => 'Recipient 1',
				],
			],
			'properties' => [
				[
					'class' => TestSharePropertyType1::class,
					'display_name' => 'TestSharePropertyType1',
					'priority' => 1,
					'required' => false,
					'value' => null,
					'type' => 'enum',
					'valid_values' => ['valid1'],
				],
				[
					'class' => TestSharePropertyTypeFilter::class,
					'display_name' => 'TestSharePropertyTypeFilter',
					'priority' => 1,
					'required' => false,
					'value' => 'visible',
					'type' => 'string',
				],
			],
			'permissions' => [
				[
					'class' => ReshareSharePermissionType::class,
					'display_name' => 'Reshare',
					'category' => ShareSharePermissionCategoryType::class,
					'enabled' => false,
				],
				[
					'class' => TestSharePermissionType1::class,
					'display_name' => 'TestSharePermissionType1',
					'category' => TestSharePermissionCategoryType1::class,
					'enabled' => true,
				],
			],
		], $share);

		$before = $this->getTimestamp();
		$this->manager->updateShareProperty($accessContext, $id, new ShareProperty(TestSharePropertyTypeFilter::class, 'filtered'));
		$after = $this->getTimestamp();

		$share = $this->getShare(new ShareAccessContext(currentUser: $this->owner), $id);
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		unset($share['last_updated']);
		$this->assertEquals([
			'id' => $id,
			'owner' => [
				'user_id' => 'owner',
				'display_name' => 'Owner',
				'icon' => [
					'light' => 'http://localhost/index.php/avatar/owner/64',
					'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
				],
			],
			'state' => ShareState::Active->value,
			'sources' => [
				[
					'class' => TestShareSourceType1::class,
					'value' => 'source1',
					'display_name' => 'Source 1',
				],
			],
			'recipients' => [
				[
					'class' => TestShareRecipientType1::class,
					'value' => 'recipient1',
					'display_name' => 'Recipient 1',
				],
			],
			'properties' => [
				[
					'class' => TestSharePropertyType1::class,
					'display_name' => 'TestSharePropertyType1',
					'priority' => 1,
					'required' => false,
					'value' => null,
					'type' => 'enum',
					'valid_values' => ['valid1'],
				],
				[
					'class' => TestSharePropertyTypeFilter::class,
					'display_name' => 'TestSharePropertyTypeFilter',
					'priority' => 1,
					'required' => false,
					'value' => 'filtered',
					'type' => 'string',
				],
			],
			'permissions' => [
				[
					'class' => ReshareSharePermissionType::class,
					'display_name' => 'Reshare',
					'category' => ShareSharePermissionCategoryType::class,
					'enabled' => false,
				],
				[
					'class' => TestSharePermissionType1::class,
					'display_name' => 'TestSharePermissionType1',
					'category' => TestSharePermissionCategoryType1::class,
					'enabled' => true,
				],
			],
		], $share);

		$this->expectExceptionMessage('Share not found: ' . $id);
		$this->getShare(new ShareAccessContext(currentUser: $this->user1), $id);
	}

	public function testGetShareAsRecipientFilteredArguments(): void {
		$this->register();
		$this->registry->registerPropertyType(new TestSharePropertyTypeFilter());
		$this->registry->markPropertyTypeCompatibleWithSourceType(TestSharePropertyTypeFilter::class, TestShareSourceType1::class);
		$this->registry->markPropertyTypeCompatibleWithRecipientType(TestSharePropertyTypeFilter::class, TestShareRecipientType1::class);

		$accessContext = new ShareAccessContext($this->owner);

		$before = $this->getTimestamp();
		$id = $this->manager->createShare($accessContext);
		$this->manager->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));
		$this->manager->updateSharePermission($accessContext, $id, new SharePermission(TestSharePermissionType1::class, true));
		$this->manager->updateShareState($accessContext, $id, ShareState::Active);
		$this->manager->getShare($accessContext, $id);

		$after = $this->getTimestamp();

		$share = $this->getShare(new ShareAccessContext(currentUser: $this->user1), $id);
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		unset($share['last_updated']);
		$this->assertEquals([
			'id' => $id,
			'owner' => [
				'user_id' => 'owner',
				'display_name' => 'Owner',
				'icon' => [
					'light' => 'http://localhost/index.php/avatar/owner/64',
					'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
				],
			],
			'state' => ShareState::Active->value,
			'sources' => [
				[
					'class' => TestShareSourceType1::class,
					'value' => 'source1',
					'display_name' => 'Source 1',
				],
			],
			'recipients' => [
				[
					'class' => TestShareRecipientType1::class,
					'value' => 'recipient1',
					'display_name' => 'Recipient 1',
				],
			],
			'properties' => [
				[
					'class' => TestSharePropertyType1::class,
					'display_name' => 'TestSharePropertyType1',
					'priority' => 1,
					'required' => false,
					'value' => null,
					'type' => 'enum',
					'valid_values' => ['valid1'],
				],
				[
					'class' => TestSharePropertyTypeFilter::class,
					'display_name' => 'TestSharePropertyTypeFilter',
					'priority' => 1,
					'required' => false,
					'value' => null,
					'type' => 'string',
				],
			],
			'permissions' => [
				[
					'class' => ReshareSharePermissionType::class,
					'display_name' => 'Reshare',
					'category' => ShareSharePermissionCategoryType::class,
					'enabled' => false,
				],
				[
					'class' => TestSharePermissionType1::class,
					'display_name' => 'TestSharePermissionType1',
					'category' => TestSharePermissionCategoryType1::class,
					'enabled' => true,
				],
			],
		], $share);

		$share = $this->getShare(new ShareAccessContext(currentUser: $this->owner, arguments: [TestSharePropertyTypeFilter::class => 'filtered']), $id);
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		unset($share['last_updated']);
		$this->assertEquals([
			'id' => $id,
			'owner' => [
				'user_id' => 'owner',
				'display_name' => 'Owner',
				'icon' => [
					'light' => 'http://localhost/index.php/avatar/owner/64',
					'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
				],
			],
			'state' => ShareState::Active->value,
			'sources' => [
				[
					'class' => TestShareSourceType1::class,
					'value' => 'source1',
					'display_name' => 'Source 1',
				],
			],
			'recipients' => [
				[
					'class' => TestShareRecipientType1::class,
					'value' => 'recipient1',
					'display_name' => 'Recipient 1',
				],
			],
			'properties' => [
				[
					'class' => TestSharePropertyType1::class,
					'display_name' => 'TestSharePropertyType1',
					'priority' => 1,
					'required' => false,
					'value' => null,
					'type' => 'enum',
					'valid_values' => ['valid1'],
				],
				[
					'class' => TestSharePropertyTypeFilter::class,
					'display_name' => 'TestSharePropertyTypeFilter',
					'priority' => 1,
					'required' => false,
					'value' => null,
					'type' => 'string',
				],
			],
			'permissions' => [
				[
					'class' => ReshareSharePermissionType::class,
					'display_name' => 'Reshare',
					'category' => ShareSharePermissionCategoryType::class,
					'enabled' => false,
				],
				[
					'class' => TestSharePermissionType1::class,
					'display_name' => 'TestSharePermissionType1',
					'category' => TestSharePermissionCategoryType1::class,
					'enabled' => true,
				],
			],
		], $share);

		$this->expectExceptionMessage('Share not found: ' . $id);
		$this->getShare(new ShareAccessContext(currentUser: $this->user1, arguments: [TestSharePropertyTypeFilter::class => 'filtered']), $id);
	}

	public function testListShares(): void {
		$this->register();

		$accessContext = new ShareAccessContext($this->owner);

		$before1 = $this->getTimestamp();
		$id1 = $this->manager->createShare($accessContext);
		$this->manager->addShareSource($accessContext, $id1, new ShareSource(TestShareSourceType1::class, 'source1'));
		$this->manager->addShareRecipient($accessContext, $id1, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));
		$this->manager->getShare($accessContext, $id1);

		$after1 = $this->getTimestamp();

		$before2 = $this->getTimestamp();
		$id2 = $this->manager->createShare($accessContext);
		$this->manager->addShareSource($accessContext, $id2, new ShareSource(TestShareSourceType2::class, 'source2'));
		$this->manager->addShareRecipient($accessContext, $id2, new ShareRecipient(TestShareRecipientType2::class, 'recipient2', null));
		$this->manager->getShare($accessContext, $id2);

		$after2 = $this->getTimestamp();

		$shares = $this->listShares($accessContext, null, null, null);
		$this->assertCount(2, $shares);
		$this->assertIsArray($shares[0]);
		$this->assertGreaterThanOrEqual($before1, $shares[0]['last_updated']);
		$this->assertLessThanOrEqual($after1, $shares[0]['last_updated']);
		$this->assertIsArray($shares[1]);
		$this->assertGreaterThanOrEqual($before2, $shares[1]['last_updated']);
		$this->assertLessThanOrEqual($after2, $shares[1]['last_updated']);
		unset($shares[0]['last_updated'], $shares[1]['last_updated']);
		$this->assertEquals([
			[
				'id' => $id1,
				'owner' => [
					'user_id' => 'owner',
					'display_name' => 'Owner',
					'icon' => [
						'light' => 'http://localhost/index.php/avatar/owner/64',
						'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
					],
				],
				'state' => ShareState::Draft->value,
				'sources' => [
					[
						'class' => TestShareSourceType1::class,
						'value' => 'source1',
						'display_name' => 'Source 1',
					],
				],
				'recipients' => [
					[
						'class' => TestShareRecipientType1::class,
						'value' => 'recipient1',
						'display_name' => 'Recipient 1',
					],
				],
				'properties' => [
					[
						'class' => TestSharePropertyType1::class,
						'display_name' => 'TestSharePropertyType1',
						'priority' => 1,
						'required' => false,
						'value' => null,
						'type' => 'enum',
						'valid_values' => ['valid1'],
					],
				],
				'permissions' => [
					[
						'class' => ReshareSharePermissionType::class,
						'display_name' => 'Reshare',
						'category' => ShareSharePermissionCategoryType::class,
						'enabled' => false,
					],
					[
						'class' => TestSharePermissionType1::class,
						'display_name' => 'TestSharePermissionType1',
						'category' => TestSharePermissionCategoryType1::class,
						'enabled' => false,
					],
				],
			],
			[
				'id' => $id2,
				'owner' => [
					'user_id' => 'owner',
					'display_name' => 'Owner',
					'icon' => [
						'light' => 'http://localhost/index.php/avatar/owner/64',
						'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
					],
				],
				'state' => ShareState::Draft->value,
				'sources' => [
					[
						'class' => TestShareSourceType2::class,
						'value' => 'source2',
						'display_name' => 'Source 2',
					],
				],
				'recipients' => [
					[
						'class' => TestShareRecipientType2::class,
						'value' => 'recipient2',
						'display_name' => 'Recipient 2',
					],
				],
				'properties' => [
					[
						'class' => TestSharePropertyType2::class,
						'display_name' => 'TestSharePropertyType2',
						'priority' => 1,
						'required' => false,
						'value' => null,
						'type' => 'enum',
						'valid_values' => ['valid2'],
					],
				],
				'permissions' => [
					[
						'class' => ReshareSharePermissionType::class,
						'display_name' => 'Reshare',
						'category' => ShareSharePermissionCategoryType::class,
						'enabled' => false,
					],
					[
						'class' => TestSharePermissionType2::class,
						'display_name' => 'TestSharePermissionType2',
						'category' => TestSharePermissionCategoryType2::class,
						'enabled' => false,
					],
				],
			],
		], $shares);

		$shares = $this->listShares($accessContext, TestShareSourceType1::class, null, null);
		$this->assertCount(1, $shares);
		$this->assertIsArray($shares[0]);
		$this->assertGreaterThanOrEqual($before1, $shares[0]['last_updated']);
		$this->assertLessThanOrEqual($after1, $shares[0]['last_updated']);
		unset($shares[0]['last_updated']);
		$this->assertEquals([
			[
				'id' => $id1,
				'owner' => [
					'user_id' => 'owner',
					'display_name' => 'Owner',
					'icon' => [
						'light' => 'http://localhost/index.php/avatar/owner/64',
						'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
					],
				],
				'state' => ShareState::Draft->value,
				'sources' => [
					[
						'class' => TestShareSourceType1::class,
						'value' => 'source1',
						'display_name' => 'Source 1',
					],
				],
				'recipients' => [
					[
						'class' => TestShareRecipientType1::class,
						'value' => 'recipient1',
						'display_name' => 'Recipient 1',
					],
				],
				'properties' => [
					[
						'class' => TestSharePropertyType1::class,
						'display_name' => 'TestSharePropertyType1',
						'priority' => 1,
						'required' => false,
						'value' => null,
						'type' => 'enum',
						'valid_values' => ['valid1'],
					],
				],
				'permissions' => [
					[
						'class' => ReshareSharePermissionType::class,
						'display_name' => 'Reshare',
						'category' => ShareSharePermissionCategoryType::class,
						'enabled' => false,
					],
					[
						'class' => TestSharePermissionType1::class,
						'display_name' => 'TestSharePermissionType1',
						'category' => TestSharePermissionCategoryType1::class,
						'enabled' => false,
					],
				],
			],
		], $shares);

		$shares = $this->listShares($accessContext, null, $id1, null);
		$this->assertCount(1, $shares);
		$this->assertIsArray($shares[0]);
		$this->assertGreaterThanOrEqual($before2, $shares[0]['last_updated']);
		$this->assertLessThanOrEqual($after2, $shares[0]['last_updated']);
		unset($shares[0]['last_updated']);
		$this->assertEquals([
			[
				'id' => $id2,
				'owner' => [
					'user_id' => 'owner',
					'display_name' => 'Owner',
					'icon' => [
						'light' => 'http://localhost/index.php/avatar/owner/64',
						'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
					],
				],
				'state' => ShareState::Draft->value,
				'sources' => [
					[
						'class' => TestShareSourceType2::class,
						'value' => 'source2',
						'display_name' => 'Source 2',
					],
				],
				'recipients' => [
					[
						'class' => TestShareRecipientType2::class,
						'value' => 'recipient2',
						'display_name' => 'Recipient 2',
					],
				],
				'properties' => [
					[
						'class' => TestSharePropertyType2::class,
						'display_name' => 'TestSharePropertyType2',
						'priority' => 1,
						'required' => false,
						'value' => null,
						'type' => 'enum',
						'valid_values' => ['valid2'],
					],
				],
				'permissions' => [
					[
						'class' => ReshareSharePermissionType::class,
						'display_name' => 'Reshare',
						'category' => ShareSharePermissionCategoryType::class,
						'enabled' => false,
					],
					[
						'class' => TestSharePermissionType2::class,
						'display_name' => 'TestSharePermissionType2',
						'category' => TestSharePermissionCategoryType2::class,
						'enabled' => false,
					],
				],
			],
		], $shares);

		$shares = $this->listShares($accessContext, null, null, 1);
		$this->assertCount(1, $shares);
		$this->assertIsArray($shares[0]);
		$this->assertGreaterThanOrEqual($before1, $shares[0]['last_updated']);
		$this->assertLessThanOrEqual($after1, $shares[0]['last_updated']);
		unset($shares[0]['last_updated']);
		$this->assertEquals([
			[
				'id' => $id1,
				'owner' => [
					'user_id' => 'owner',
					'display_name' => 'Owner',
					'icon' => [
						'light' => 'http://localhost/index.php/avatar/owner/64',
						'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
					],
				],
				'state' => ShareState::Draft->value,
				'sources' => [
					[
						'class' => TestShareSourceType1::class,
						'value' => 'source1',
						'display_name' => 'Source 1',
					],
				],
				'recipients' => [
					[
						'class' => TestShareRecipientType1::class,
						'value' => 'recipient1',
						'display_name' => 'Recipient 1',
					],
				],
				'properties' => [
					[
						'class' => TestSharePropertyType1::class,
						'display_name' => 'TestSharePropertyType1',
						'priority' => 1,
						'required' => false,
						'value' => null,
						'type' => 'enum',
						'valid_values' => ['valid1'],
					],
				],
				'permissions' => [
					[
						'class' => ReshareSharePermissionType::class,
						'display_name' => 'Reshare',
						'category' => ShareSharePermissionCategoryType::class,
						'enabled' => false,
					],
					[
						'class' => TestSharePermissionType1::class,
						'display_name' => 'TestSharePermissionType1',
						'category' => TestSharePermissionCategoryType1::class,
						'enabled' => false,
					],
				],
			],
		], $shares);
	}
}
