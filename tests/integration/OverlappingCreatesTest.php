<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Tests\Integration;

use OCA\Totem\Db\Pantalla;
use OCA\Totem\Db\PantallaMapper;
use OCA\Totem\Db\ServicioLinks;
use OCA\Totem\Db\ServicioMapper;
use OCA\Totem\Db\Totem;
use OCA\Totem\Db\TotemMapper;
use OCA\Totem\Exception\Refused;
use OCA\Totem\Service\PantallaService;
use OCA\Totem\Service\TicketPrinting;
use OCA\Totem\Service\TotemService;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Exception as DbException;
use OCP\IDBConnection;
use OCP\Server;

/**
 * Two admin saves that create one name at the same moment, against real PostgreSQL. A mapper subclass plays the
 * other save: right after create()'s findAll() read it stores the competitor's record, so both saves pass the name
 * check and derive the same address, and the unique slug index decides which one is stored.
 */
final class OverlappingCreatesTest extends IntegrationTestCase {
	public function testATotemCreateThatLosesTheAddressIsRefusedWithTheNameSentence(): void {
		$servicio = $this->servicio();
		$service = new TotemService($this->totemsRacedBy(fn () => $this->totem('sala', 'Sala')), Server::get(ServicioLinks::class), Server::get(ServicioMapper::class), Server::get(TicketPrinting::class));

		$this->assertRefused('Ya existe un tótem con ese nombre.', fn () => $service->create(['name' => 'Sala', 'servicioIds' => [$servicio->getId()]]));

		$stored = array_map(static fn (Totem $t): array => [$t->getSlug(), $t->getName()], Server::get(TotemMapper::class)->findAll());
		$this->assertSame([['sala', 'Sala']], $stored);
		$this->assertSame(0, $this->rowCount(ServicioLinks::TOTEM));
	}

	public function testAPantallaCreateThatLosesTheAddressIsRefusedWithTheNameSentence(): void {
		$servicio = $this->servicio();
		$service = new PantallaService($this->pantallasRacedBy(fn () => $this->pantalla('sala', 'Sala')), Server::get(ServicioLinks::class), Server::get(ServicioMapper::class));

		$this->assertRefused('Ya existe una pantalla con ese nombre.', fn () => $service->create(['name' => 'Sala', 'servicioIds' => [$servicio->getId()]]));

		$stored = array_map(static fn (Pantalla $p): array => [$p->getSlug(), $p->getName()], Server::get(PantallaMapper::class)->findAll());
		$this->assertSame([['sala', 'Sala']], $stored);
		$this->assertSame(0, $this->rowCount(ServicioLinks::PANTALLA));
	}

	public function testAnyOtherDatabaseErrorFromTheInsertLeavesCreateUnchanged(): void {
		// An address longer than its 64-character column: PostgreSQL refuses the insert, and not for a unique index.
		$totems = new class(Server::get(IDBConnection::class)) extends TotemMapper {
			public ?DbException $thrown = null;

			public function insert(Entity $entity): Entity {
				$entity->setSlug(str_repeat('s', 65));
				try {
					return parent::insert($entity);
				} catch (DbException $e) {
					throw $this->thrown = $e;
				}
			}
		};
		$service = new TotemService($totems, Server::get(ServicioLinks::class), Server::get(ServicioMapper::class), Server::get(TicketPrinting::class));

		try {
			$service->create(['name' => 'Sala']);
			$this->fail('The insert was expected to fail.');
		} catch (DbException $e) {
			$this->assertNotSame(DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION, $e->getReason());
			$this->assertSame($totems->thrown, $e);
		}
	}

	/** A TotemMapper whose first findAll() runs the competitor right after it reads. */
	private function totemsRacedBy(\Closure $competitor): TotemMapper {
		$totems = new class(Server::get(IDBConnection::class)) extends TotemMapper {
			public ?\Closure $competitor = null;

			public function findAll(): array {
				$all = parent::findAll();
				[$competitor, $this->competitor] = [$this->competitor, null];
				$competitor?->__invoke();

				return $all;
			}
		};
		$totems->competitor = $competitor;

		return $totems;
	}

	/** A PantallaMapper whose first findAll() runs the competitor right after it reads. */
	private function pantallasRacedBy(\Closure $competitor): PantallaMapper {
		$pantallas = new class(Server::get(IDBConnection::class)) extends PantallaMapper {
			public ?\Closure $competitor = null;

			public function findAll(): array {
				$all = parent::findAll();
				[$competitor, $this->competitor] = [$this->competitor, null];
				$competitor?->__invoke();

				return $all;
			}
		};
		$pantallas->competitor = $competitor;

		return $pantallas;
	}

	private function assertRefused(string $sentence, \Closure $save): void {
		try {
			$save();
			$this->fail("The save was expected to be refused with \"$sentence\".");
		} catch (Refused $e) {
			$this->assertSame($sentence, $e->getMessage());
		}
	}

	/** @param array{string, string} $link one of ServicioLinks' constants */
	private function rowCount(array $link): int {
		$result = $this->db()->getQueryBuilder()->select('servicio_id')->from($link[0])->executeQuery();
		$count = count($result->fetchAll());
		$result->closeCursor();

		return $count;
	}
}
