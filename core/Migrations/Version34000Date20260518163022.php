<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OC\Core\Migrations;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Override;

class Version34000Date20260518163022 extends SimpleMigrationStep {
	#[Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$changed = false;
		if (!$schema->hasTable('jobs_classes_register')) {
			$table = $schema->createTable('jobs_classes_register');
			$table->addColumn('class_id', Types::SMALLINT, [
				'autoincrement' => true,
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('class_name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('class_hash', Types::INTEGER, ['notnull' => true]);
			$table->setPrimaryKey(['class_id']);
			$table->addUniqueConstraint(['class_hash', 'class_name'], 'class_index');
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
