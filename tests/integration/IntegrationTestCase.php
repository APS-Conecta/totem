<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Tests\Integration;

use OCA\Totem\Db\Servicio;
use OCA\Totem\Db\ServicioMapper;
use OCA\Totem\Db\Turno;
use OCA\Totem\Db\TurnoMapper;
use OCA\Totem\Domain\Estado;
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
	protected const DAY = '2026-10-18';
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

	/** A servicio open all day, 00:00-23:59, with no quota. */
	protected function servicio(string $name = 'Farmacia', string $prefix = 'FAR'): Servicio {
		$s = new Servicio();
		$s->setSlug(strtolower($prefix));
		$s->setName($name);
		$s->setPrefix($prefix);
		$s->setColor('#0b6e69');
		$s->setOpens('00:00');
		$s->setCloses('23:59');
		$s->setDailyQuota(null);
		$s->setActive(true);

		return Server::get(ServicioMapper::class)->insert($s);
	}

	/** A waiting turno of the servicio. */
	protected function turno(Servicio $s, bool $preferente, int $seq, int $createdAt = 1000, string $day = self::DAY): Turno {
		$t = new Turno();
		$t->setServicioId($s->getId());
		$t->setDay($day);
		$t->setPreferente($preferente);
		$t->setSeq($seq);
		$t->setEstado(Estado::Waiting->value);
		$t->setCreatedAt($createdAt);

		return Server::get(TurnoMapper::class)->insert($t);
	}

	private function emptyTables(): void {
		foreach (self::TABLES as $table) {
			$this->db()->getQueryBuilder()->delete($table)->executeStatement();
		}
	}
}
