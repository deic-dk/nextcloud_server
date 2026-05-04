<?php

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OC\Sharing;

use Closure;
use Exception;
use OC\Sharing\Permission\ReshareSharePermissionType;
use OC\Sharing\Permission\ShareSharePermissionCategoryType;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Sharing\Exception\ShareForbiddenException;
use OCP\Sharing\Exception\ShareInvalidException;
use OCP\Sharing\Exception\ShareNotFoundException;
use OCP\Sharing\IManager;
use OCP\Sharing\Permission\ISharePermissionType;
use OCP\Sharing\Permission\SharePermission;
use OCP\Sharing\Property\ISharePropertyType;
use OCP\Sharing\Property\ISharePropertyTypeFilter;
use OCP\Sharing\Property\ISharePropertyTypeModifyValue;
use OCP\Sharing\Property\ShareProperty;
use OCP\Sharing\Recipient\IShareRecipientType;
use OCP\Sharing\Recipient\IShareRecipientTypeSearch;
use OCP\Sharing\Recipient\ShareRecipient;
use OCP\Sharing\Share;
use OCP\Sharing\ShareAccessContext;
use OCP\Sharing\ShareOwner;
use OCP\Sharing\ShareState;
use OCP\Sharing\Source\IShareSourceType;
use OCP\Sharing\Source\ShareSource;
use OCP\Snowflake\ISnowflakeGenerator;
use RuntimeException;

// TODO: Add listeners to remove recipients and sources when they are deleted
// TODO: Pass on full share to features
// TODO: Add accept/reject

/**
 * @psalm-import-type SharingShare from Share
 */
final readonly class Manager implements IManager {
	public function __construct(
		private IDBConnection $connection,
		private IUserManager $userManager,
		private ISnowflakeGenerator $snowflakeGenerator,
		private Registry $registry,
	) {
		$this->registry->registerPermissionCategoryType(new ShareSharePermissionCategoryType());
		$this->registry->registerPermissionType(null, new ReshareSharePermissionType());
	}

	/**
	 * For some reason rector always tries to add ShareRecipient[] as the return type and there is no way to stop it.
	 * @param ?class-string<IShareRecipientType> $recipientTypeClass
	 * @param non-empty-string $query
	 * @param positive-int $limit
	 * @param non-negative-int $offset
	 * @return list<ShareRecipient>
	 * @throws ShareInvalidException
	 */
	#[\Override]
	public function searchRecipients(ShareAccessContext $accessContext, ?string $recipientTypeClass, string $query, int $limit, int $offset): array {
		$recipientTypes = $this->registry->getRecipientTypes();

		if ($recipientTypeClass !== null) {
			if (!isset($recipientTypes[$recipientTypeClass])) {
				throw new ShareInvalidException('The recipient type is not registered: ' . $recipientTypeClass);
			}

			$recipientTypes = [$recipientTypeClass => $recipientTypes[$recipientTypeClass]];
		}

		$searchableRecipientTypes = array_values(array_filter(
			$recipientTypes,
			static fn (IShareRecipientType $recipientType): bool => $recipientType instanceof IShareRecipientTypeSearch,
		));

		// TODO: Search on trusted servers

		return array_merge(...array_map(
			static fn (IShareRecipientTypeSearch $recipientType): array => $recipientType->searchRecipients($accessContext, $query, $limit, $offset),
			$searchableRecipientTypes,
		));
	}

	#[\Override]
	public function createShare(ShareAccessContext $accessContext): string {
		if (!($currentUser = $accessContext->currentUser) instanceof IUser) {
			throw new RuntimeException('No user present to create a share');
		}

		$id = $this->snowflakeGenerator->nextId();
		$lastUpdated = $this->generateLastUpdated();

		$qb = $this->connection->getQueryBuilder();
		$qb
			->insert('sharing_share')
			->values([
				'id' => $qb->createNamedParameter((int)$id, IQueryBuilder::PARAM_INT),
				'owner' => $qb->createNamedParameter($currentUser->getUID()),
				'last_updated' => $qb->createNamedParameter($lastUpdated),
				'state' => $qb->createNamedParameter(ShareState::Draft->value),
			])
			->executeStatement();

		return $id;
	}

	#[\Override]
	public function updateShareState(ShareAccessContext $accessContext, string $id, ShareState $state): void {
		$owner = $this->getShareOwner($id);

		$this->validateShareOwnerOperation($accessContext, $id, $owner);

		if ($state === ShareState::Active) {
			$share = $this->getShare($accessContext, $id);

			if ($share->sources === []) {
				throw new ShareInvalidException('No source set.');
			}

			if ($share->recipients === []) {
				throw new ShareInvalidException('No recipient set.');
			}

			if (!array_any($share->permissions, static fn (SharePermission $permission): bool => $permission->enabled)) {
				throw new ShareInvalidException('No permission given.');
			}

			$propertyTypes = $this->registry->getPropertyTypes();
			foreach ($share->properties as $propertyTypeClass => $property) {
				$propertyType = $propertyTypes[$propertyTypeClass];
				if ($property->value === null && $propertyType->getRequired()) {
					throw new ShareInvalidException('Missing value for required property: ' . $propertyTypeClass);
				}
			}
		}

		$this->wrapUpdate($id, function () use ($state, $id): void {
			$qb = $this->connection->getQueryBuilder();
			$qb
				->update('sharing_share')
				->set('state', $qb->createNamedParameter($state->value))
				->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$id, IQueryBuilder::PARAM_INT)))
				->executeStatement();
		});
	}

	#[\Override]
	public function addShareSource(ShareAccessContext $accessContext, string $id, ShareSource $source): void {
		$owner = $this->getShareOwner($id);

		$this->validateShareOwnerOperation($accessContext, $id, $owner);

		if (($sourceType = $this->registry->getSourceTypes()[$source->class] ?? null) === null) {
			throw new ShareInvalidException('The source type is not registered: ' . $source->class);
		}

		if (!$sourceType->validateSource($owner, $source->value)) {
			throw new ShareInvalidException('The source ' . $source->value . ' for ' . $source->class . ' is not valid.');
		}

		$this->wrapUpdate($id, function () use ($id, $source): void {
			try {
				$qb = $this->connection->getQueryBuilder();
				$qb
					->insert('sharing_share_sources')
					->values([
						'share_id' => $qb->createNamedParameter((int)$id, IQueryBuilder::PARAM_INT),
						'source_class' => $qb->createNamedParameter($source->class),
						'source_value' => $qb->createNamedParameter($source->value),
					])
					->executeStatement();
			} catch (Exception $exception) {
				if ($exception instanceof \OCP\DB\Exception && $exception->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
					throw new ShareInvalidException('Tried to add share source that already exists: ' . $source->class . ' ' . $source->value);
				}

				throw $exception;
			}
		});
	}

	#[\Override]
	public function removeShareSource(ShareAccessContext $accessContext, string $id, ShareSource $source): void {
		$owner = $this->getShareOwner($id);

		$this->validateShareOwnerOperation($accessContext, $id, $owner);

		$this->wrapUpdate($id, function () use ($accessContext, $id, $source): void {
			$qb = $this->connection->getQueryBuilder();
			$rowCount = $qb
				->delete('sharing_share_sources')
				->where($qb->expr()->eq('share_id', $qb->createNamedParameter((int)$id, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('source_class', $qb->createNamedParameter($source->class)))
				->andWhere($qb->expr()->eq('source_value', $qb->createNamedParameter($source->value)))
				->executeStatement();
			if ($rowCount === 0) {
				throw new ShareInvalidException('Tried to remove share source that does not exist: ' . $source->class . ' ' . $source->value);
			}

			$share = $this->getShare($accessContext, $id);
			if ($share->state === ShareState::Active && $share->sources === []) {
				$qb = $this->connection->getQueryBuilder();
				$qb
					->update('sharing_share')
					->set('state', $qb->createNamedParameter(ShareState::Draft->value))
					->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$id, IQueryBuilder::PARAM_INT)))
					->executeStatement();
			}
		});
	}

	#[\Override]
	public function addShareRecipient(ShareAccessContext $accessContext, string $id, ShareRecipient $recipient): void {
		$owner = $this->getShareOwner($id);

		try {
			$this->validateShareOwnerOperation($accessContext, $id, $owner);
			$share = $this->getShare($accessContext, $id);
		} catch (ShareForbiddenException) {
			$share = $this->getShare($accessContext, $id);
			$this->validatePermission($share, ReshareSharePermissionType::class);
		}

		if (($recipientType = $this->registry->getRecipientTypes()[$recipient->class] ?? null) === null) {
			throw new ShareInvalidException('The recipient type is not registered: ' . $recipient->class);
		}

		// TODO: Request remote instance to validate recipient
		if (!$recipientType->validateRecipient($owner, $recipient->value)) {
			throw new ShareInvalidException('The recipient ' . $recipient->value . ' for ' . $recipient->class . ' is not valid.');
		}

		$this->wrapUpdate($id, function () use ($accessContext, $id, $recipient, $share): void {
			try {
				$qb = $this->connection->getQueryBuilder();

				$values = [
					'id' => $qb->createNamedParameter((int)$this->snowflakeGenerator->nextId(), IQueryBuilder::PARAM_INT),
					'share_id' => $qb->createNamedParameter((int)$id, IQueryBuilder::PARAM_INT),
					'recipient_class' => $qb->createNamedParameter($recipient->class),
					'recipient_value' => $qb->createNamedParameter($recipient->value),
				];

				if (!$accessContext->force && $accessContext->currentUser?->getUID() !== $share->owner->userId) {
					/** @var list<ShareRecipientWithIds> $recipients */
					$recipients = $share->recipients;
					$recipientIds = array_map(static fn (ShareRecipientWithIds $recipient): string => $recipient->id, $recipients);
					$selfRecipients = [];

					foreach ($recipients as $recipient) {
						// Either the recipient has no parent or the parent is not in the list, in which case it's the the top most visible recipient (but not the root recipient)
						if ($recipient->parentId === null || !in_array($recipient->parentId, $recipientIds, true)) {
							$selfRecipients[] = $recipient;
						}
					}

					// If we're not the owner, there must be at least one recipient through which we got access.
					if ($selfRecipients === []) {
						throw new RuntimeException('No recipients found that are from ourselves.');
					}

					// TODO: Select the "best" parent recipient (e.g. the more explicit the better: user > group)
					$values['parent_id'] = $qb->createNamedParameter((int)$selfRecipients[0]->id, IQueryBuilder::PARAM_INT);
				}

				$qb
					->insert('sharing_share_recipients')
					->values($values)
					->executeStatement();
			} catch (Exception $exception) {
				if ($exception instanceof \OCP\DB\Exception && $exception->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
					throw new ShareInvalidException('Tried to add share recipient that already exists: ' . $recipient->class . ' ' . $recipient->value);
				}

				throw $exception;
			}
		});
	}

	#[\Override]
	public function removeShareRecipient(ShareAccessContext $accessContext, string $id, ShareRecipient $recipient): void {
		$owner = $this->getShareOwner($id);

		try {
			$this->validateShareOwnerOperation($accessContext, $id, $owner);
		} catch (ShareForbiddenException) {
			$share = $this->getShare($accessContext, $id);
			$this->validatePermission($share, ReshareSharePermissionType::class);

			// We're only allowed to remove recipients, if they are visible to us.
			$found = false;
			/** @var list<ShareRecipientWithIds> $recipients */
			$recipients = $share->recipients;
			foreach ($recipients as $shareRecipient) {
				if ($recipient->class === $shareRecipient->class && $recipient->value === $shareRecipient->value && $recipient->instance === $shareRecipient->instance) {
					$found = true;
					break;
				}
			}

			if (!$found) {
				throw new ShareForbiddenException($id);
			}
		}

		$this->wrapUpdate($id, function () use ($id, $recipient): void {
			// Child recipients are deleted by foreign key constraint and on delete cascade.

			$qb = $this->connection->getQueryBuilder();
			$rowCount = $qb
				->delete('sharing_share_recipients')
				->where($qb->expr()->eq('share_id', $qb->createNamedParameter((int)$id, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('recipient_class', $qb->createNamedParameter($recipient->class)))
				->andWhere($qb->expr()->eq('recipient_value', $qb->createNamedParameter($recipient->value)))
				->executeStatement();
			if ($rowCount === 0) {
				throw new ShareInvalidException('Tried to remove share recipient that does not exist: ' . $recipient->class . ' ' . $recipient->value);
			}

			// Do not use the current share access context, as it might not be able to see all recipients!
			$share = $this->getShare(new ShareAccessContext(force: true), $id);
			if ($share->state === ShareState::Active && $share->recipients === []) {
				$qb = $this->connection->getQueryBuilder();
				$qb
					->update('sharing_share')
					->set('state', $qb->createNamedParameter(ShareState::Draft->value))
					->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$id, IQueryBuilder::PARAM_INT)))
					->executeStatement();
			}
		});
	}

	#[\Override]
	public function updateShareProperty(ShareAccessContext $accessContext, string $id, ShareProperty $property): void {
		$owner = $this->getShareOwner($id);

		$this->validateShareOwnerOperation($accessContext, $id, $owner);

		if (($propertyType = $this->registry->getPropertyTypes()[$property->class] ?? null) === null) {
			throw new ShareInvalidException('The property is not registered: ' . $property->class);
		}

		if ($property->value !== null && ($message = $propertyType->validateValue($property->value)) !== true) {
			throw new ShareInvalidException($message);
		}

		$this->wrapUpdate($id, function () use ($accessContext, $id, $property, $propertyType): void {
			$value = $property->value;

			if ($propertyType instanceof ISharePropertyTypeModifyValue) {
				$qb = $this->connection->getQueryBuilder();
				$qb
					->select('sp.property_value')
					->from('sharing_share_properties', 'sp')
					->where($qb->expr()->eq('sp.share_id', $qb->createNamedParameter((int)$id, IQueryBuilder::PARAM_INT)))
					->andWhere($qb->expr()->eq('sp.property_class', $qb->createNamedParameter($property->class)));

				/** @var string|false $oldValue */
				$oldValue = $qb->executeQuery()->fetchOne();
				if ($oldValue === false) {
					$oldValue = null;
				}

				$value = $propertyType->modifyValueOnSave($oldValue, $property->value);
			}

			$qb = $this->connection->getQueryBuilder();
			$rowCount = $qb
				->update('sharing_share_properties')
				->set('property_value', $qb->createNamedParameter($value))
				->where($qb->expr()->eq('share_id', $qb->createNamedParameter((int)$id, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('property_class', $qb->createNamedParameter($property->class)))
				->executeStatement();

			if ($rowCount === 0) {
				throw new ShareInvalidException('Tried to update a property that does not exist: ' . $property->class);
			}

			if ($property->value === null && $propertyType->getRequired()) {
				$share = $this->getShare($accessContext, $id);
				if ($share->state === ShareState::Active) {
					$qb = $this->connection->getQueryBuilder();
					$qb
						->update('sharing_share')
						->set('state', $qb->createNamedParameter(ShareState::Draft->value))
						->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$id, IQueryBuilder::PARAM_INT)))
						->executeStatement();
				}
			}
		});
	}

	#[\Override]
	public function updateSharePermission(ShareAccessContext $accessContext, string $id, SharePermission $permission): void {
		$owner = $this->getShareOwner($id);

		$this->validateShareOwnerOperation($accessContext, $id, $owner);

		if (!isset($this->registry->getPermissionTypes()[$permission->class])) {
			throw new ShareInvalidException('The permission type is not registered: ' . $permission->class);
		}

		$this->wrapUpdate($id, function () use ($accessContext, $id, $permission): void {
			$qb = $this->connection->getQueryBuilder();
			$rowCount = $qb
				->update('sharing_share_permissions')
				->set('permission_enabled', $qb->createNamedParameter($permission->enabled, IQueryBuilder::PARAM_BOOL))
				->where($qb->expr()->eq('share_id', $qb->createNamedParameter((int)$id, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('permission_class', $qb->createNamedParameter($permission->class)))
				->executeStatement();

			if ($rowCount === 0) {
				throw new ShareInvalidException('Tried to update a permission that does not exist: ' . $permission->class);
			}

			$share = $this->getShare($accessContext, $id);
			if ($share->state === ShareState::Active && !array_any($share->permissions, static fn (SharePermission $permission): bool => $permission->enabled)) {
				$qb = $this->connection->getQueryBuilder();
				$qb
					->update('sharing_share')
					->set('state', $qb->createNamedParameter(ShareState::Draft->value))
					->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$id, IQueryBuilder::PARAM_INT)))
					->executeStatement();
			}
		});
	}


	#[\Override]
	public function deleteShare(ShareAccessContext $accessContext, string $id): void {
		$owner = $this->getShareOwner($id);

		$this->validateShareOwnerOperation($accessContext, $id, $owner);

		$qb = $this->connection->getQueryBuilder();
		$qb
			->delete('sharing_share')
			->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$id, IQueryBuilder::PARAM_INT)))
			->executeStatement();

		// The other tables are cleared by their foreign key constraints and on delete cascade.
	}

	#[\Override]
	public function getShare(ShareAccessContext $accessContext, string $id): Share {
		$shares = $this->list($accessContext, $id, null, null, null);
		if (count($shares) !== 1) {
			throw new ShareNotFoundException($id);
		}

		return $shares[0];
	}

	#[\Override]
	public function listShares(ShareAccessContext $accessContext, ?string $sourceTypeClass, ?string $lastShareID, ?int $limit): array {
		return $this->list($accessContext, null, $sourceTypeClass, $lastShareID, $limit);
	}

	/**
	 * @return non-negative-int
	 */
	private function generateLastUpdated(): int {
		$time = (int)(microtime(true) * 1000.0);
		if ($time < 0) {
			throw new RuntimeException('Have you invented time travel?');
		}

		return $time;
	}

	/**
	 * @param Closure():void $closure
	 * @return non-negative-int
	 */
	private function wrapUpdate(string $id, Closure $closure): int {
		try {
			$lastUpdated = $this->generateLastUpdated();

			$this->connection->beginTransaction();

			// First update the row to get a lock on it
			$qb = $this->connection->getQueryBuilder();
			$qb
				->update('sharing_share')
				->set('last_updated', $qb->createNamedParameter($lastUpdated, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$id, IQueryBuilder::PARAM_INT)))
				->executeStatement();

			$closure();

			$this->connection->commit();
			return $lastUpdated;
		} catch (Exception $exception) {
			$this->connection->rollBack();
			throw $exception;
		}

		// TODO: Try to push updated share to all recipient instances
	}

	/**
	 * @throws ShareNotFoundException
	 */
	private function getShareOwner(string $id): IUser {
		$qb = $this->connection->getQueryBuilder();
		$qb
			->select('s.owner')
			->from('sharing_share', 's')
			->where($qb->expr()->eq('s.id', $qb->createNamedParameter((int)$id, IQueryBuilder::PARAM_INT)));

		/** @var non-empty-string|false $uid */
		$uid = $qb->executeQuery()->fetchOne();
		if ($uid === false) {
			throw new ShareNotFoundException($id);
		}

		// TODO: Delete share if owner was deleted. Also listen for BeforeUserDeletedEvent
		if (($user = $this->userManager->get($uid)) === null) {
			throw new ShareNotFoundException($id);
		}

		return $user;
	}

	/**
	 * @throws ShareForbiddenException
	 * @throws ShareNotFoundException
	 */
	private function validateShareOwnerOperation(ShareAccessContext $accessContext, string $id, IUser $owner): void {
		if ($accessContext->force) {
			return;
		}

		if (!$accessContext->currentUser instanceof IUser) {
			throw new ShareForbiddenException($id);
		}

		if ($owner->getUID() !== $accessContext->currentUser->getUID()) {
			throw new ShareForbiddenException($id);
		}
	}

	/**
	 * @param class-string<ISharePermissionType> $permissionTypeClass
	 * @throws ShareForbiddenException
	 */
	private function validatePermission(Share $share, string $permissionTypeClass): void {
		if ((($permission = $share->permissions[$permissionTypeClass] ?? null) !== null) && $permission->enabled) {
			return;
		}

		throw new ShareForbiddenException($share->id);
	}

	/**
	 * @param ?class-string<IShareSourceType> $filterSourceTypeClass
	 * @return list<Share>
	 * @throws ShareInvalidException
	 */
	private function list(ShareAccessContext $accessContext, ?string $filterShareID, ?string $filterSourceTypeClass, ?string $lastShareID, ?int $limit): array {
		/** @var array<class-string<IShareRecipientType>, list<string>> $recipientTypeValues */
		$recipientTypeValues = [];

		/** @var list<IQueryBuilder> $queries */
		$queries = [];
		if ($accessContext->force) {
			$queries[] = $this->connection->getQueryBuilder();
		} else {
			if ($accessContext->currentUser instanceof IUser) {
				$qb = $this->connection->getQueryBuilder();
				$qb->where($qb->expr()->eq('s.owner', $qb->createNamedParameter($accessContext->currentUser->getUID())));
				$queries[] = $qb;
			}

			foreach ($this->registry->getRecipientTypes() as $recipientType) {
				$recipientValues = $recipientType->getRecipients($accessContext->currentUser, $accessContext->arguments[$recipientType::class] ?? null);
				if ($recipientValues !== []) {
					$recipientTypeValues[$recipientType::class] = $recipientValues;
				}
			}

			// Do not add a query if no recipients matched, otherwise all shares will be returned.
			if ($recipientTypeValues !== []) {
				$qb = $this->connection->getQueryBuilder();
				$qb->innerJoin('s', 'sharing_share_recipients', 'sr', $qb->expr()->andX(
					$qb->expr()->eq('sr.share_id', 's.id'),
					$qb->expr()->eq('s.state', $qb->createNamedParameter(ShareState::Active->value)),
				));

				foreach ($recipientTypeValues as $recipientTypeClass => $recipientValues) {
					$qb->orWhere($qb->expr()->andX(
						$qb->expr()->eq('sr.recipient_class', $qb->createNamedParameter($recipientTypeClass)),
						// TODO: Add chunking
						$qb->expr()->in('sr.recipient_value', $qb->createNamedParameter($recipientValues, IQueryBuilder::PARAM_STR_ARRAY)),
					));
				}

				$queries[] = $qb;
			}
		}

		/** @var array<int, array{id: non-empty-string, owner: ShareOwner, last_updated: non-negative-int, state: ShareState, sources: list<ShareSource>, recipients: list<ShareRecipient>, properties: array<class-string<ISharePropertyType>, ShareProperty>, permissions: array<class-string<ISharePermissionType>, SharePermission>}> $shares */
		$shares = [];
		foreach ($queries as $qb) {
			$qb
				->select(
					's.id',
					's.owner',
					's.instance',
					's.last_updated',
					's.state',
				)
				->from('sharing_share', 's')
				->orderBy('s.id', 'ASC');

			if ($filterShareID !== null) {
				$qb->andWhere($qb->expr()->eq('s.id', $qb->createNamedParameter((int)$filterShareID, IQueryBuilder::PARAM_INT)));
			}

			if ($filterSourceTypeClass !== null) {
				$qb->innerJoin('s', 'sharing_share_sources', 'ss', $qb->expr()->andX(
					$qb->expr()->eq('s.id', 'ss.share_id'),
					$qb->expr()->eq('ss.source_class', $qb->createNamedParameter($filterSourceTypeClass)),
				));
			}

			if ($lastShareID !== null) {
				if (!ctype_digit($lastShareID)) {
					throw new ShareInvalidException('The lastShareId is invalid.');
				}

				$qb->andWhere($qb->expr()->gt('s.id', $qb->createNamedParameter((int)$lastShareID, IQueryBuilder::PARAM_INT)));
			}

			if ($limit !== null) {
				$qb->setMaxResults($limit);
			}

			$result = $qb->executeQuery();
			$rows = $result->fetchAll();
			foreach ($rows as $row) {
				// Because Snowflake IDs are numeric-strings, PHP converts them to integers automatically when used as array keys.
				// We'll just accept that here, as we only need them for constant time lookups and discard them later anyway.
				/** @var int $id */
				$id = $row['id'];
				/** @var non-negative-int $lastUpdated */
				$lastUpdated = $row['last_updated'];
				/** @var non-empty-string $owner */
				$owner = $row['owner'];
				/** @var ?non-empty-string $instance */
				$instance = $row['instance'];
				/** @var string $state */
				$state = $row['state'];
				$shares[$id] ??= [
					'id' => (string)$id,
					'owner' => new ShareOwner($owner, $instance),
					'last_updated' => $lastUpdated,
					'state' => ShareState::from($state),
					'sources' => [],
					'recipients' => [],
					'properties' => [],
					'permissions' => [],
				];
			}
		}

		// If multiple queries are used the shares are not automatically sorted already.
		if (count($queries) > 1) {
			ksort($shares);
		}

		// The queries are limited already, but could return more results in total, so discard them here.
		if ($limit !== null) {
			$shares = array_slice($shares, 0, $limit, true);
		}

		/** @var list<list<int>> $chunks */
		$chunks = array_chunk(array_keys($shares), 1000);

		$registrySourceTypes = $this->registry->getSourceTypes();
		/** @var array<int, array<class-string<IShareSourceType>, bool>> $shareSourceTypeClasses */
		$shareSourceTypeClasses = [];
		foreach ($chunks as $chunk) {
			$qb = $this->connection->getQueryBuilder();
			$qb
				->select(
					'ss.share_id',
					'ss.source_class',
					'ss.source_value',
				)
				->from('sharing_share_sources', 'ss')
				->where($qb->expr()->in('ss.share_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));

			$result = $qb->executeQuery();
			foreach ($result->fetchAll() as $row) {
				/** @var class-string<IShareSourceType> $type */
				$type = $row['source_class'];
				if (!isset($registrySourceTypes[$type])) {
					// Skip sources that are currently not compatible, but don't remove them.
					continue;
				}

				/** @var non-empty-string $value */
				$value = $row['source_value'];
				/** @var int $id */
				$id = $row['share_id'];
				$shares[$id]['sources'][] = new ShareSource(
					$type,
					$value,
				);

				$shareSourceTypeClasses[$id] ??= [];
				$shareSourceTypeClasses[$id][$type] = true;
			}
		}

		$registryRecipientTypes = $this->registry->getRecipientTypes();
		/** @var array<int, array<class-string<IShareRecipientType>, bool>> $shareRecipientTypeClasses */
		$shareRecipientTypeClasses = [];
		foreach ($chunks as $chunk) {
			$qb = $this->connection->getQueryBuilder();
			$qb
				->select(
					'sr.id',
					'sr.parent_id',
					'sr.share_id',
					'sr.recipient_class',
					'sr.recipient_value',
					'sr.recipient_instance',
				)
				->from('sharing_share_recipients', 'sr')
				->where($qb->expr()->in('sr.share_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));

			if (!$accessContext->force) {
				$orX = [];
				if ($accessContext->currentUser instanceof IUser) {
					$qb->leftJoin('sr', 'sharing_share', 's', $qb->expr()->eq('s.id', 'sr.share_id'));
					$orX[] = $qb->expr()->eq('s.owner', $accessContext->currentUser->getUID());
				}

				foreach ($recipientTypeValues as $recipientTypeClass => $recipientValues) {
					$orX[] = $qb->expr()->andX(
						$qb->expr()->eq('sr.recipient_class', $qb->createNamedParameter($recipientTypeClass)),
						// TODO: Add chunking
						$qb->expr()->in('sr.recipient_value', $qb->createNamedParameter($recipientValues, IQueryBuilder::PARAM_STR_ARRAY)),
					);
				}

				if ($orX !== []) {
					$qb->andWhere($qb->expr()->orX(...$orX));
				}
			}


			$rows = [];
			$parentIds = [];
			foreach ($qb->executeQuery()->fetchAll() as $row) {
				/** @var int $id */
				$id = $row['id'];
				$parentIds[] = $id;
				$rows[] = $row;
			}

			while ($parentIds !== []) {
				$parentIdsChunks = array_chunk($parentIds, 1000);
				$parentIds = [];
				foreach ($parentIdsChunks as $parentIdsChunk) {
					$qb = $this->connection->getQueryBuilder();
					$qb
						->select(
							'sr.id',
							'sr.parent_id',
							'sr.share_id',
							'sr.recipient_class',
							'sr.recipient_value',
							'sr.recipient_instance',
						)
						->from('sharing_share_recipients', 'sr')
						->where($qb->expr()->in('sr.parent_id', $qb->createNamedParameter($parentIdsChunk, IQueryBuilder::PARAM_INT_ARRAY)));

					foreach ($qb->executeQuery()->fetchAll() as $row) {
						/** @var int $id */
						$id = $row['id'];
						$parentIds[] = $id;
						$rows[] = $row;
					}
				}
			}

			foreach ($rows as $row) {
				/** @var class-string<IShareRecipientType> $type */
				$type = $row['recipient_class'];
				if (!isset($registryRecipientTypes[$type])) {
					// Skip recipients that are currently not compatible, but don't remove them.
					continue;
				}

				/** @var int $id */
				$id = $row['id'];
				/** @var ?int $parentId */
				$parentId = $row['parent_id'];
				/** @var non-empty-string $value */
				$value = $row['recipient_value'];
				/** @var ?non-empty-string $instance */
				$instance = $row['recipient_instance'];
				/** @var int $shareId */
				$shareId = $row['share_id'];
				$shares[$shareId]['recipients'][] = new ShareRecipientWithIds(
					(string)$id,
					(string)$parentId,
					$type,
					$value,
					$instance,
				);

				$shareRecipientTypeClasses[$shareId] ??= [];
				$shareRecipientTypeClasses[$shareId][$type] = true;
			}
		}

		$registryPropertyTypes = $this->registry->getPropertyTypes();
		$registryPropertyTypeCompatibleSourceTypeClasses = $this->registry->getPropertyTypeCompatibleSourceTypeClasses();
		$registryPropertyTypeCompatibleRecipientTypeClasses = $this->registry->getPropertyTypeCompatibleRecipientTypes();

		foreach ($chunks as $chunk) {
			$qb = $this->connection->getQueryBuilder();
			$qb
				->select(
					'sp.share_id',
					'sp.property_class',
					'sp.property_value',
				)
				->from('sharing_share_properties', 'sp')
				->where($qb->expr()->in('sp.share_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));

			$result = $qb->executeQuery();
			foreach ($result->fetchAll() as $row) {
				/** @var int $id */
				$id = $row['share_id'];
				if (!isset($shareSourceTypeClasses[$id], $shareRecipientTypeClasses[$id])) {
					continue;
				}

				/** @var class-string<ISharePropertyType> $propertyTypeClass */
				$propertyTypeClass = $row['property_class'];
				if (!isset($registryPropertyTypeCompatibleSourceTypeClasses[$propertyTypeClass], $registryPropertyTypeCompatibleRecipientTypeClasses[$propertyTypeClass])) {
					// Skip properties that are currently not compatible, but don't remove them.
					continue;
				}

				if (array_intersect($registryPropertyTypeCompatibleSourceTypeClasses[$propertyTypeClass], array_keys($shareSourceTypeClasses[$id])) === []) {
					// Skip properties that are currently not compatible, but don't remove them.
					continue;
				}

				if (array_intersect($registryPropertyTypeCompatibleRecipientTypeClasses[$propertyTypeClass], array_keys($shareRecipientTypeClasses[$id])) === []) {
					// Skip properties that are currently not compatible, but don't remove them.
					continue;
				}

				/** @var ?string $value */
				$value = $row['property_value'];

				$propertyType = $registryPropertyTypes[$propertyTypeClass];
				if ($propertyType instanceof ISharePropertyTypeModifyValue) {
					$value = $propertyType->modifyValueOnLoad($value);
				}

				$shares[$id]['properties'][$propertyTypeClass] = new ShareProperty($propertyTypeClass, $value);
			}
		}

		foreach (array_keys($shares) as $id) {
			foreach ($registryPropertyTypes as $propertyTypeClass => $propertyType) {
				if (
					!isset($shares[$id]['properties'][$propertyTypeClass])
					&& isset($shareSourceTypeClasses[$id], $shareRecipientTypeClasses[$id])
					&& array_intersect($registryPropertyTypeCompatibleSourceTypeClasses[$propertyTypeClass], array_keys($shareSourceTypeClasses[$id])) !== []
					&& array_intersect($registryPropertyTypeCompatibleRecipientTypeClasses[$propertyTypeClass], array_keys($shareRecipientTypeClasses[$id])) !== []) {
					$value = $propertyType->getDefaultValue();

					$lastUpdated = $this->wrapUpdate((string)$id, function () use ($id, $propertyTypeClass, $value): void {
						$qb = $this->connection->getQueryBuilder();
						$qb
							->insert('sharing_share_properties')
							->values([
								'share_id' => $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT),
								'property_class' => $qb->createNamedParameter($propertyTypeClass),
								'property_value' => $qb->createNamedParameter($value),
							])
							->executeStatement();
					});

					$shares[$id]['properties'][$propertyTypeClass] = new ShareProperty($propertyTypeClass, $value);
					$shares[$id]['last_updated'] = $lastUpdated;
				}
			}
		}

		$registrySourceTypePermissionTypeClasses = $this->registry->getSourceTypePermissionTypeClasses();
		$registryGenericPermissionTypeClasses = $this->registry->getGenericPermissionTypeClasses();

		/** @var array<int, array<class-string<ISharePermissionType>, bool>> $shareCompatiblePermissionTypeClasses */
		$shareCompatiblePermissionTypeClasses = [];
		foreach (array_keys($shares) as $id) {
			$shareCompatiblePermissionTypeClasses[$id] = [];
			foreach ($registryGenericPermissionTypeClasses as $permissionTypeClass) {
				$shareCompatiblePermissionTypeClasses[$id][$permissionTypeClass] = true;
			}

			if (isset($shareSourceTypeClasses[$id])) {
				foreach (array_keys($shareSourceTypeClasses[$id]) as $shareSourceTypeClass) {
					if (isset($registrySourceTypePermissionTypeClasses[$shareSourceTypeClass])) {
						foreach ($registrySourceTypePermissionTypeClasses[$shareSourceTypeClass] as $permissionTypeClass) {
							$shareCompatiblePermissionTypeClasses[$id][$permissionTypeClass] = true;
						}
					}
				}
			}
		}

		foreach ($chunks as $chunk) {
			$qb = $this->connection->getQueryBuilder();
			$qb
				->select(
					'sp.share_id',
					'sp.permission_class',
					'sp.permission_enabled',
				)
				->from('sharing_share_permissions', 'sp')
				->where($qb->expr()->in('sp.share_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));

			$result = $qb->executeQuery();
			foreach ($result->fetchAll() as $row) {
				/** @var int $id */
				$id = $row['share_id'];

				/** @var class-string<ISharePermissionType> $permissionTypeClass */
				$permissionTypeClass = $row['permission_class'];
				if (!isset($shareCompatiblePermissionTypeClasses[$id][$permissionTypeClass])) {
					// Skip permissions that are currently not compatible, but don't remove them.
					continue;
				}

				$enabled = (bool)$row['permission_enabled'];
				$shares[$id]['permissions'][$permissionTypeClass] = new SharePermission($permissionTypeClass, $enabled);
			}
		}

		$permissionTypes = $this->registry->getPermissionTypes();
		$permissionCategoryTypes = $this->registry->getPermissionCategoryTypes();

		foreach (array_keys($shares) as $id) {
			foreach (array_keys($shareCompatiblePermissionTypeClasses[$id]) as $permissionTypeClass) {
				$permissionType = $permissionTypes[$permissionTypeClass];
				if (!isset($shares[$id]['permissions'][$permissionTypeClass])) {
					$enabled = $permissionType->getDefault() ?? (($category = $permissionType->getCategory()) !== null && $permissionCategoryTypes[$category]->getDefault());

					$lastUpdated = $this->wrapUpdate((string)$id, function () use ($id, $permissionTypeClass, $enabled): void {
						$qb = $this->connection->getQueryBuilder();
						$qb
							->insert('sharing_share_permissions')
							->values([
								'share_id' => $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT),
								'permission_class' => $qb->createNamedParameter($permissionTypeClass),
								'permission_enabled' => $qb->createNamedParameter($enabled, IQueryBuilder::PARAM_BOOL),
							])
							->executeStatement();
					});

					$shares[$id]['permissions'][$permissionTypeClass] = new SharePermission($permissionTypeClass, $enabled);
					$shares[$id]['last_updated'] = $lastUpdated;
				}
			}
		}

		$shares = array_map(static fn (array $share): Share => new Share(
			$share['id'],
			$share['owner'],
			$share['last_updated'],
			$share['state'],
			$share['sources'],
			$share['recipients'],
			$share['properties'],
			$share['permissions'],
		), $shares);

		if (!$accessContext->force) {
			$filterPropertyTypes = array_filter($registryPropertyTypes, static fn (ISharePropertyType $propertyType): bool => $propertyType instanceof ISharePropertyTypeFilter);
			if ($filterPropertyTypes !== []) {
				// TODO: This could become expensive for many shares, so maybe cache the filtering results.
				$shares = array_filter($shares, static function (Share $share) use ($accessContext, $filterPropertyTypes): bool {
					if ($accessContext->currentUser?->getUID() === $share->owner->userId) {
						return true;
					}

					foreach ($filterPropertyTypes as $filterPropertyType) {
						if ($filterPropertyType->isFiltered($accessContext, $share)) {
							return false;
						}
					}

					return true;
				});
			}
		}

		return array_values($shares);
	}
}
