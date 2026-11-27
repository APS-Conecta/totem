<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Tests\Integration;

use OCA\Totem\Db\Box;
use OCA\Totem\Db\BoxMapper;
use OCA\Totem\Db\Pantalla;
use OCA\Totem\Db\PantallaMapper;
use OCA\Totem\Db\ServicioLinks;
use OCA\Totem\Db\TurnoMapper;
use OCA\Totem\Domain\Estado;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception;
use OCP\IConfig;
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

	/**
	 * A display reads its pantalla's servicios while an admin saves the pantalla, and an empty set means every
	 * servicio (#131): the replace must be all or nothing. The database refuses the new link, so a replace that
	 * is not one transaction leaves the set it already deleted.
	 */
	public function testALinkSetTheDatabaseRefusesKeepsTheServiciosItHad(): void {
		$this->links->replace(ServicioLinks::PANTALLA, 3, [10, 11]);
		$table = Server::get(IConfig::class)->getSystemValueString('dbtableprefix', 'oc_') . ServicioLinks::PANTALLA[0];
		$this->db()->executeStatement("CREATE FUNCTION totem_test_refuse_link() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'refused'; END \$\$");
		$this->db()->executeStatement("CREATE TRIGGER totem_test_refuse_link BEFORE INSERT ON $table FOR EACH ROW WHEN (NEW.servicio_id = 99) EXECUTE FUNCTION totem_test_refuse_link()");
		try {
			$this->links->replace(ServicioLinks::PANTALLA, 3, [99]);
			$this->fail('the database took the refused link');
		} catch (Exception) {
			// refused, as arranged
		} finally {
			$this->db()->executeStatement("DROP TRIGGER totem_test_refuse_link ON $table");
			$this->db()->executeStatement('DROP FUNCTION totem_test_refuse_link()');
		}

		$this->assertSame([10, 11], $this->links->ids(ServicioLinks::PANTALLA, 3));
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

	public function testANoSePresentoOnATurnoAlreadyMarkedNoSePresentoIsRefused(): void {
		$this->now = $this->momentOf(self::DAY, '12:00');
		$s = $this->servicio();
		$box = new Box();
		$box->setName('Box 1');
		$boxId = (int)Server::get(BoxMapper::class)->insert($box)->getId();
		$this->links->replace(ServicioLinks::BOX, $boxId, [$s->getId()]);
		$x = $this->turno($s, false, 1, $this->now);
		$turnos = Server::get(TurnoMapper::class);
		$this->calls()->callNext($boxId);
		// The box's other console has already marked X No se presentó; this console still shows X called.
		$turnos->transition($x->getId(), Estado::Called, Estado::NoShow, $this->now);

		$refusal = $this->refusal(fn () => $this->calls()->absent($boxId));

		$this->assertSame(Estado::NoShow->value, $turnos->find($x->getId())->getEstado());
		$this->assertSame('No hay un turno llamado para marcar “No se presentó”.', $refusal);
	}
}
