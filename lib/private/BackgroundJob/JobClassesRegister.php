<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-only
 */
namespace OC\BackgroundJob;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use OCP\IDBConnection;

/**
 * Map background job classes and their ID in database
 *
 * Uses a rapid hash to speed-up lookups
 */
final class JobClassesRegister {
	private array $register = [];

	private const TABLE = 'jobs_classes_register';

	public function __construct(
		private IDBConnection $connection,
	) {
		$this->loadRegister();
	}

	private function loadRegister(): void {
		$qb = $this->connection->getQueryBuilder();
		$result = $qb->select('class_id', 'class_name')->from(self::TABLE)->executeQuery();
		foreach ($result->iterateAssociative() as $row) {
			$this->register[$row['class_name']] = (int)$row['class_id'];
		}
	}

	/**
	 * Resolve current ID or generates a new one
	 */
	public function getId(string $className): int {
		if (isset($this->register[$className])) {
			return $this->register[$className];
		}

		$qb = $this->connection->getQueryBuilder();
		$hashedName = $this->hashName($className);
		try {
			$qb
				->insert(self::TABLE)
				->setValue('class_name', $qb->expr()->literal($className))
				->setValue('class_hash', $qb->expr()->literal($hashedName))
				->executeStatement();
			return $qb->getLastInsertId();
		} catch (UniqueConstraintViolationException) {
			// Class was probably added by a concurrent process
			// Try to load it
			$result = $qb
				->select('class_id')
				->from(self::TABLE)
				->where($qb->expr()->eq('class_hash', $hashedName))
				->andWhere($qb->expr()->eq('class_name', $className))
				->executeQuery();
			if ($classId = $result->fetchOne()) {
				return (int)$classId;
			}
		}

		throw new \Exception('Fail to retrieve ' . $className . ' ID');
	}

	private function hashName(string $className): int {
		return hexdec(hash('xxh32', $className));
	}
}
