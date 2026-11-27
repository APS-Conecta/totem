<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Tests\Integration;

use OCA\Totem\Db\BoxMapper;
use OCA\Totem\Db\LlamadoMapper;
use OCA\Totem\Db\Pantalla;
use OCA\Totem\Db\PantallaMapper;
use OCA\Totem\Db\Servicio;
use OCA\Totem\Db\ServicioLinks;
use OCA\Totem\Db\ServicioMapper;
use OCA\Totem\Db\Totem;
use OCA\Totem\Db\TotemMapper;
use OCA\Totem\Db\Turno;
use OCA\Totem\Db\TurnoMapper;
use OCA\Totem\Domain\Estado;
use OCA\Totem\Exception\Refused;
use OCA\Totem\Service\CallService;
use OCA\Totem\Service\DisplayFeed;
use OCA\Totem\Service\Today;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IDateTimeZone;
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

	/** The moment, as Unix seconds, that the clock of {@see self::today()} answers. */
	protected int $now;

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

	/** A totem (a kiosk), with a printer at the host when one is given. */
	protected function totem(string $slug, string $name, ?string $host = null): Totem {
		$totem = new Totem();
		$totem->setSlug($slug);
		$totem->setName($name);
		$totem->setPrinterHost($host);

		return Server::get(TotemMapper::class)->insert($totem);
	}

	/** A pantalla (a waiting-room display) at full volume with the woman's voice. */
	protected function pantalla(string $slug, string $name): Pantalla {
		$pantalla = new Pantalla();
		$pantalla->setSlug($slug);
		$pantalla->setName($name);
		$pantalla->setVolume(100);
		$pantalla->setVoice('mujer');

		return Server::get(PantallaMapper::class)->insert($pantalla);
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

	/** A turno of the servicio that has already left the queue in this estado. */
	protected function turnoEnded(Servicio $s, bool $preferente, int $seq, Estado $estado, string $day): Turno {
		$t = $this->turno($s, $preferente, $seq, 1000, $day);
		$t->setEstado($estado->value);

		return Server::get(TurnoMapper::class)->update($t);
	}

	/** A moment of a Día, as Unix seconds in the instance's time zone. */
	protected function momentOf(string $day, string $time): int {
		return (new \DateTimeImmutable("$day $time", Server::get(IDateTimeZone::class)->getDefaultTimeZone()))->getTimestamp();
	}

	/** The instance's Today on a stand-in clock that answers {@see self::$now}. */
	protected function today(): Today {
		$clock = $this->createStub(ITimeFactory::class);
		$clock->method('getTime')->willReturnCallback(fn (): int => $this->now);

		return new Today($clock, Server::get(IDateTimeZone::class));
	}

	/** The calls a box makes, on the clock of {@see self::today()}. */
	protected function calls(): CallService {
		return new CallService(Server::get(BoxMapper::class), Server::get(ServicioLinks::class), Server::get(ServicioMapper::class), Server::get(TurnoMapper::class), Server::get(LlamadoMapper::class), $this->today());
	}

	/** The feed a Pantalla polls, on the clock of {@see self::today()}. */
	protected function feed(): DisplayFeed {
		return new DisplayFeed(Server::get(PantallaMapper::class), Server::get(ServicioLinks::class), Server::get(LlamadoMapper::class), Server::get(TurnoMapper::class), Server::get(ServicioMapper::class), Server::get(BoxMapper::class), $this->today());
	}

	/** The sentence the request was refused with; null when it went through. */
	protected function refusal(\Closure $request): ?string {
		try {
			$request();
		} catch (Refused $e) {
			return $e->getMessage();
		}

		return null;
	}

	private function emptyTables(): void {
		foreach (self::TABLES as $table) {
			$this->db()->getQueryBuilder()->delete($table)->executeStatement();
		}
	}
}
