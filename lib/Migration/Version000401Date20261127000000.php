<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The turno a box holds is looked up by box_id and Estado, whatever its Día (TurnoMapper::findActiveForBox and
 * countActiveForBox), so its index leads with exactly those two columns and the one with day goes.
 */
class Version000401Date20261127000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$table = $schema->getTable('totem_turno');

		if ($table->hasIndex('totem_turno_box_idx')) {
			$table->dropIndex('totem_turno_box_idx');
		}
		if (!$table->hasIndex('totem_turno_box_estado_idx')) {
			$table->addIndex(['box_id', 'estado'], 'totem_turno_box_estado_idx');
		}

		return $schema;
	}
}
