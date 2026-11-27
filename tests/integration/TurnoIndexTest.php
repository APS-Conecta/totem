<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Tests\Integration;

use OC\DB\Connection;
use OC\DB\MigrationService;
use OCP\Server;

/**
 * The installed schema of totem_turno: the lookup of the turno a box holds filters on box_id and Estado,
 * whatever its Día, so an index must lead with exactly those two columns.
 */
final class TurnoIndexTest extends IntegrationTestCase {
	public function testTheHeldTurnoLookupHasAnIndexOnBoxAndEstado(): void {
		$indexes = $this->turnoIndexColumns();

		self::assertContains(['box_id', 'estado'], $indexes);
		self::assertNotContains(['box_id', 'day', 'estado'], $indexes);
	}

	public function testRunningTheLatestMigrationStepAgainChangesNoIndex(): void {
		$before = $this->turnoIndexColumns();
		$migrations = new MigrationService('totem', Server::get(Connection::class));

		$migrations->executeStep($migrations->getMigration('latest'), true);

		self::assertSame($before, $this->turnoIndexColumns());
	}

	/** @return array<string, list<string>> each index of totem_turno by name, as its column list */
	private function turnoIndexColumns(): array {
		$table = $this->db()->createSchema()->getTable($this->tableName('totem_turno'));
		$columns = [];
		foreach ($table->getIndexes() as $name => $index) {
			$columns[$name] = $index->getColumns();
		}
		ksort($columns);

		return $columns;
	}
}
