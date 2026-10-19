<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Tests\Integration;

use OCA\Totem\Db\ServicioLinks;
use OCA\Totem\Db\Totem;
use OCA\Totem\Db\TotemMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Server;

/** The totem (a kiosk) and its printer, against real PostgreSQL: the Entity's camelCase must land in the migration's columns. */
final class TotemMapperTest extends IntegrationTestCase {
	private TotemMapper $totems;

	protected function setUp(): void {
		parent::setUp();
		$this->totems = Server::get(TotemMapper::class);
	}

	private function totem(string $slug, string $name, ?string $host = null): Totem {
		$totem = new Totem();
		$totem->setSlug($slug);
		$totem->setName($name);
		$totem->setPrinterHost($host);

		return $this->totems->insert($totem);
	}

	public function testATotemWithoutAPrinterRoundTripsWithTheDefaultPort(): void {
		$this->totem('entrada', 'Entrada');

		$found = $this->totems->findBySlug('entrada');

		$this->assertSame('Entrada', $found->getName());
		$this->assertNull($found->getPrinterHost());
		$this->assertSame(9100, $found->getPrinterPort());
		$this->assertNull($found->getPrinterOk());
		$this->assertNull($found->getPrinterCheckedAt());
	}

	public function testThePrintersAddressAndItsLastResultAreStored(): void {
		$totem = $this->totem('sala', 'Sala', '192.168.1.50');
		$totem->setPrinterPort(9101);
		$totem->setPrinterOk(false);
		$totem->setPrinterCheckedAt(1_792_400_000);
		$this->totems->update($totem);

		$found = $this->totems->find($totem->getId());

		$this->assertSame('192.168.1.50', $found->getPrinterHost());
		$this->assertSame(9101, $found->getPrinterPort());
		$this->assertFalse($found->getPrinterOk());
		$this->assertSame(1_792_400_000, $found->getPrinterCheckedAt());
	}

	public function testTotemsListByName(): void {
		$this->totem('b', 'Segundo');
		$this->totem('a', 'Primero');

		$this->assertSame(['Primero', 'Segundo'], array_map(static fn (Totem $t) => $t->getName(), $this->totems->findAll()));
	}

	public function testAnUnknownSlugIsNotFound(): void {
		$this->expectException(DoesNotExistException::class);
		$this->totems->findBySlug('nadie');
	}

	public function testTheUnidadesATotemOffersAreItsOwnLinks(): void {
		$links = Server::get(ServicioLinks::class);

		$links->replace(ServicioLinks::TOTEM, 7, [3, 4]);
		$links->replace(ServicioLinks::PANTALLA, 7, [9]);

		$this->assertSame([3, 4], $links->ids(ServicioLinks::TOTEM, 7));
		$this->assertSame([9], $links->ids(ServicioLinks::PANTALLA, 7));
	}
}
