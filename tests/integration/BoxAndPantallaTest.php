<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Tests\Integration;

use OCA\Totem\Db\Box;
use OCA\Totem\Db\BoxMapper;
use OCA\Totem\Db\Pantalla;
use OCA\Totem\Db\PantallaMapper;
use OCA\Totem\Db\ServicioLinks;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Server;

/** Boxes, pantallas and the servicio links they share, against real PostgreSQL. */
final class BoxAndPantallaTest extends IntegrationTestCase {
	private ServicioLinks $links;

	protected function setUp(): void {
		parent::setUp();
		$this->links = Server::get(ServicioLinks::class);
	}

	public function testABoxRoundTripsAndListsByName(): void {
		$mapper = Server::get(BoxMapper::class);
		foreach (['Box 5', 'Box 2'] as $name) {
			$box = new Box();
			$box->setName($name);
			$mapper->insert($box);
		}

		$this->assertSame(['Box 2', 'Box 5'], array_map(static fn (Box $b) => $b->getName(), $mapper->findAll()));
	}

	public function testAPantallaIsFoundBySlugWithItsSoundSettings(): void {
		$mapper = Server::get(PantallaMapper::class);
		$p = new Pantalla();
		$p->setSlug('sala-farmacia');
		$p->setName('Sala Farmacia');
		$p->setVolume(60);
		$p->setVoice('hombre');
		$mapper->insert($p);

		$found = $mapper->findBySlug('sala-farmacia');

		$this->assertSame(60, $found->getVolume());
		$this->assertSame('hombre', $found->getVoice());
		$this->expectException(DoesNotExistException::class);
		$mapper->findBySlug('otra');
	}

	public function testReplacingALinkSetMakesExactlyTheseServicios(): void {
		$this->links->replace(ServicioLinks::BOX, 3, [10, 11, 12]);
		$this->assertSame([10, 11, 12], $this->links->ids(ServicioLinks::BOX, 3));

		$this->links->replace(ServicioLinks::BOX, 3, [11, 11, 13]);
		$this->assertSame([11, 13], $this->links->ids(ServicioLinks::BOX, 3));

		$this->links->replace(ServicioLinks::BOX, 3, []);
		$this->assertSame([], $this->links->ids(ServicioLinks::BOX, 3));
	}

	public function testBoxLinksAndPantallaLinksAreSeparateTablesAndOwners(): void {
		$this->links->replace(ServicioLinks::BOX, 1, [10]);
		$this->links->replace(ServicioLinks::PANTALLA, 1, [20]);
		$this->links->replace(ServicioLinks::BOX, 2, [30]);

		$this->assertSame([10], $this->links->ids(ServicioLinks::BOX, 1));
		$this->assertSame([20], $this->links->ids(ServicioLinks::PANTALLA, 1));
		$this->assertSame([30], $this->links->ids(ServicioLinks::BOX, 2));
	}
}
