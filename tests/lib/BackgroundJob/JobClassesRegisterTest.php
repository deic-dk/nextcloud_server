<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Test\BackgroundJob;

use OC\BackgroundJob\JobClassesRegister;
use OCP\IDBConnection;
use OCP\Server;
use Override;
use Test\TestCase;

/**
 * @package Test\BackgroundJob
 */
#[\PHPUnit\Framework\Attributes\Group('DB')]
class JobClassesRegisterTest extends TestCase {
	private IDBConnection $connection;
	private JobClassesRegister $register;

	#[Override]
	protected function setUp(): void {
		parent::setUp();

		$this->connection = Server::get(IDBConnection::class);
		$this->register = new JobClassesRegister($this->connection);
	}

	public function testResolveClass() {
		$className = 'test_class_' . random_int(1000000, 9999999);

		$classId = $this->register->getId($className);
		$this->assertIsInt($classId);
		$this->assertGreaterThan(0, $classId);

		// Renew register
		$this->register = new JobClassesRegister($this->connection);
		$newId = $this->register->getId($className);
		$this->assertEquals($classId, $newId);
	}
}
