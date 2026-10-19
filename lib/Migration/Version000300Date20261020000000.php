<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The totem (a kiosk) and the unidades it offers, with its ticket printer's address and the last result of
 * printing. Same conventions as the first migration: plain BIGINT links with named indexes and no foreign
 * keys, and a default on every NOT NULL column.
 */
class Version000300Date20261020000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('totem_totem')) {
			$table = $schema->createTable('totem_totem');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('slug', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 64]);
			// Null = no network printer: the kiosk's browser prints.
			$table->addColumn('printer_host', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('printer_port', Types::INTEGER, ['notnull' => true, 'default' => 9100]);
			// The last attempt: null = never tried.
			$table->addColumn('printer_ok', Types::BOOLEAN, ['notnull' => false]);
			$table->addColumn('printer_checked_at', Types::BIGINT, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['slug'], 'totem_tot_slug_idx');
		}

		if (!$schema->hasTable('totem_totem_servicio')) {
			$table = $schema->createTable('totem_totem_servicio');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('totem_id', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('servicio_id', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['totem_id', 'servicio_id'], 'totem_totsrv_idx');
		}

		return $schema;
	}
}
