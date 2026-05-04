<?php

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);


namespace OCA\Sharing\Migration;

use Closure;
use Doctrine\DBAL\Schema\SchemaException;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Override;

final class Version1000Date20250929161325 extends SimpleMigrationStep {
	/**
	 * @param Closure():ISchemaWrapper $schemaClosure
	 * @throws SchemaException
	 */
	#[Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		$shareTable = $schema->createTable('sharing_share');
		$shareTable->addColumn('id', Types::BIGINT);
		$shareTable->addColumn('owner', Types::TEXT);
		$shareTable->addColumn('instance', Types::TEXT, ['notnull' => false]);
		$shareTable->addColumn('last_updated', Types::BIGINT);
		$shareTable->addColumn('state', Types::TEXT);
		$shareTable->setPrimaryKey(['id']);

		$sourcesTable = $schema->createTable('sharing_share_sources');
		$sourcesTable->addColumn('share_id', Types::BIGINT);
		$sourcesTable->addColumn('source_class', Types::TEXT);
		$sourcesTable->addColumn('source_value', Types::TEXT);
		$sourcesTable->setPrimaryKey(['share_id', 'source_class', 'source_value']);
		$sourcesTable->addForeignKeyConstraint($shareTable->getName(), ['share_id'], ['id'], ['onDelete' => 'CASCADE']);

		// TODO: Add possibility to mask permissions for recipients. For reshares the user may only mask permissions for their child recipients, not their self recipients
		$recipientsTable = $schema->createTable('sharing_share_recipients');
		$recipientsTable->addColumn('id', Types::BIGINT);
		$recipientsTable->addColumn('parent_id', Types::BIGINT, ['notnull' => false]);
		$recipientsTable->addColumn('share_id', Types::BIGINT);
		$recipientsTable->addColumn('recipient_class', Types::TEXT);
		$recipientsTable->addColumn('recipient_value', Types::TEXT);
		$recipientsTable->addColumn('recipient_instance', Types::TEXT, ['notnull' => false]);
		$recipientsTable->setPrimaryKey(['id']);
		$recipientsTable->addUniqueIndex(['share_id', 'recipient_class', 'recipient_value']);
		$recipientsTable->addForeignKeyConstraint($shareTable->getName(), ['share_id'], ['id'], ['onDelete' => 'CASCADE']);
		$recipientsTable->addForeignKeyConstraint($recipientsTable->getName(), ['parent_id'], ['id'], ['onDelete' => 'CASCADE']);

		$propertiesTable = $schema->createTable('sharing_share_properties');
		$propertiesTable->addColumn('share_id', Types::BIGINT);
		$propertiesTable->addColumn('property_class', Types::TEXT);
		$propertiesTable->addColumn('property_value', Types::TEXT, ['notnull' => false]);
		$propertiesTable->setPrimaryKey(['share_id', 'property_class']);
		$propertiesTable->addForeignKeyConstraint($shareTable->getName(), ['share_id'], ['id'], ['onDelete' => 'CASCADE']);

		$permissionsTable = $schema->createTable('sharing_share_permissions');
		$permissionsTable->addColumn('share_id', Types::BIGINT);
		$permissionsTable->addColumn('permission_class', Types::TEXT);
		$permissionsTable->addColumn('permission_enabled', Types::BOOLEAN);
		$permissionsTable->setPrimaryKey(['share_id', 'permission_class']);
		$permissionsTable->addForeignKeyConstraint($shareTable->getName(), ['share_id'], ['id'], ['onDelete' => 'CASCADE']);

		return $schema;
	}
}
