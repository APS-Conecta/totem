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
 * The app's whole schema in one step: the servicios, the day's turnos, the
 * append-only llamado log, and the boxes and pantallas with their servicio
 * links. Totem is greenfield, so one changeSchema beats a chain nothing would
 * ever walk.
 *
 * Cross-reference columns are plain BIGINT with named indexes and no foreign
 * keys, on the farmacia/territorio convention: the app must run on MySQL,
 * SQLite and PostgreSQL alike, and the referential guarantees live in the
 * service layer.
 *
 * Every NOT NULL column carries a default unless the write path always sets it
 * (territorio lesson #74): Entity::setter skips marking a field whose value
 * equals the current one.
 *
 * Reserved words avoided: `opens`/`closes` (not `open`/`close`), `day` is fine
 * as a column on all three engines.
 */
class Version000100Date20261018000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('totem_servicio')) {
			$table = $schema->createTable('totem_servicio');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('slug', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('prefix', Types::STRING, ['notnull' => true, 'length' => 8]);
			$table->addColumn('color', Types::STRING, ['notnull' => true, 'length' => 7, 'default' => '#4a4f57']);
			// HH:MM in the instance's time zone.
			$table->addColumn('opens', Types::STRING, ['notnull' => true, 'length' => 5, 'default' => '00:00']);
			$table->addColumn('closes', Types::STRING, ['notnull' => true, 'length' => 5, 'default' => '23:59']);
			// Null = no quota.
			$table->addColumn('daily_quota', Types::INTEGER, ['notnull' => false]);
			$table->addColumn('active', Types::BOOLEAN, ['notnull' => true, 'default' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['slug'], 'totem_srv_slug_idx');
		}

		if (!$schema->hasTable('totem_turno')) {
			$table = $schema->createTable('totem_turno');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('servicio_id', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			// Y-m-d in the instance's time zone: the day scopes every sequence and quota.
			$table->addColumn('day', Types::STRING, ['notnull' => true, 'length' => 10, 'default' => '']);
			$table->addColumn('preferente', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
			$table->addColumn('seq', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			// Estado's backing value.
			$table->addColumn('estado', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'waiting']);
			$table->addColumn('box_id', Types::BIGINT, ['notnull' => false]);
			// Unix seconds. called_at is the FIRST call: a re-llamado is a llamado row, not a new call.
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('called_at', Types::BIGINT, ['notnull' => false]);
			$table->addColumn('served_at', Types::BIGINT, ['notnull' => false]);
			$table->addColumn('finished_at', Types::BIGINT, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			// The retry-on-collision net for MAX(seq)+1 under concurrent issuing.
			$table->addUniqueIndex(['servicio_id', 'day', 'preferente', 'seq'], 'totem_turno_seq_idx');
			$table->addIndex(['servicio_id', 'day', 'estado', 'preferente', 'created_at'], 'totem_turno_queue_idx');
			$table->addIndex(['box_id', 'day', 'estado'], 'totem_turno_box_idx');
		}

		// Append-only: the display feed and the audit trail.
		if (!$schema->hasTable('totem_llamado')) {
			$table = $schema->createTable('totem_llamado');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('turno_id', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('servicio_id', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('box_id', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('called_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['servicio_id', 'id'], 'totem_llam_srv_idx');
			$table->addIndex(['turno_id'], 'totem_llam_turno_idx');
		}

		if (!$schema->hasTable('totem_box')) {
			$table = $schema->createTable('totem_box');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->setPrimaryKey(['id']);
		}

		if (!$schema->hasTable('totem_box_servicio')) {
			$table = $schema->createTable('totem_box_servicio');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('box_id', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('servicio_id', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['box_id', 'servicio_id'], 'totem_boxsrv_idx');
		}

		if (!$schema->hasTable('totem_pantalla')) {
			$table = $schema->createTable('totem_pantalla');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('slug', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 64]);
			// 0-100: the app cannot go louder than the device.
			$table->addColumn('volume', Types::INTEGER, ['notnull' => true, 'default' => 100]);
			// mujer | hombre | off
			$table->addColumn('voice', Types::STRING, ['notnull' => true, 'length' => 8, 'default' => 'mujer']);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['slug'], 'totem_pan_slug_idx');
		}

		if (!$schema->hasTable('totem_pantalla_servicio')) {
			$table = $schema->createTable('totem_pantalla_servicio');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('pantalla_id', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('servicio_id', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['pantalla_id', 'servicio_id'], 'totem_pansrv_idx');
		}

		return $schema;
	}
}
