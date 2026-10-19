<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Tests\Integration;

use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Shared plumbing for tests that run against the throwaway instance's real
 * PostgreSQL (make deps && make instance-up, then make test-integration). The
 * unit suite mocks the mappers; nothing but a real insert proves that camelCase
 * Entity properties land in the snake_case columns the migration created, and
 * that the conditional UPDATEs and unique indexes behave as the services assume.
 *
 * The instance is throwaway, so a test starts from empty tables rather than
 * tracking what it created.
 */
abstract class IntegrationTestCase extends TestCase {
	private const TABLES = ['totem_llamado', 'totem_turno', 'totem_box_servicio', 'totem_box', 'totem_pantalla_servicio', 'totem_pantalla', 'totem_totem_servicio', 'totem_totem', 'totem_servicio'];

	protected function setUp(): void {
		$this->emptyTables();
	}

	protected function tearDown(): void {
		$this->emptyTables();
	}

	final protected function db(): IDBConnection {
		return Server::get(IDBConnection::class);
	}

	private function emptyTables(): void {
		foreach (self::TABLES as $table) {
			$this->db()->getQueryBuilder()->delete($table)->executeStatement();
		}
	}
}
