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
 * A servicio may be a sector (CONTEXT.md, Sector): the statistics board counts
 * sectors in a block of their own. Existing servicios are not sectors.
 * NOT NULL with a default, so the column is added to a table that has rows.
 */
class Version000200Date20261019000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$table = $schema->getTable('totem_servicio');

		if (!$table->hasColumn('is_sector')) {
			$table->addColumn('is_sector', Types::BOOLEAN, ['notnull' => true, 'default' => false]);

			return $schema;
		}

		return null;
	}
}
