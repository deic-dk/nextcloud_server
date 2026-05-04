<?php

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);


use OCA\Sharing\AppInfo\Application;
use OCA\Sharing\Capabilities;
use OCP\Server;
use OCP\Sharing\IRegistry;
use Test\Sharing\TestSharePermissionCategoryType1;
use Test\Sharing\TestSharePermissionCategoryType2;
use Test\TestCase;

final class CapabilitiesTest extends TestCase {
	private IRegistry $registry;

	private Capabilities $capabilities;

	#[\Override]
	public function setUp(): void {
		parent::setUp();

		$this->registry = Server::get(IRegistry::class);
		$this->registry->clear();

		$this->capabilities = Server::get(Capabilities::class);
	}

	#[\Override]
	protected function tearDown(): void {
		$this->registry->clear();

		parent::tearDown();
	}

	public function testGetCapabilities(): void {
		$this->registry->registerPermissionCategoryType(new TestSharePermissionCategoryType1());
		$this->registry->registerPermissionCategoryType(new TestSharePermissionCategoryType2());

		$this->assertEquals(
			[
				Application::APP_ID => [
					'api_versions' => ['v1'],
					'legacy' => [
						'max_sources' => 1,
						'max_recipients' => 1,
					],
					'permission_categories' => [
						[
							'class' => TestSharePermissionCategoryType1::class,
							'display_name' => 'TestSharePermissionCategoryType1',
						],
						[
							'class' => TestSharePermissionCategoryType2::class,
							'display_name' => 'TestSharePermissionCategoryType2',
						],
					],
				],
			],
			$this->capabilities->getCapabilities(),
		);
	}
}
