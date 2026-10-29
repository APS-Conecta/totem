<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Tests\Integration;

use OCA\Totem\Db\Box;
use OCA\Totem\Db\BoxMapper;
use OCA\Totem\Db\Llamado;
use OCA\Totem\Db\LlamadoMapper;
use OCA\Totem\Db\Servicio;
use OCA\Totem\Db\ServicioLinks;
use OCA\Totem\Db\ServicioMapper;
use OCA\Totem\Db\TotemMapper;
use OCA\Totem\Db\Turno;
use OCA\Totem\Db\TurnoMapper;
use OCA\Totem\Domain\Estado;
use OCA\Totem\Exception\Refused;
use OCA\Totem\Service\CallService;
use OCA\Totem\Service\TicketPrinting;
use OCA\Totem\Service\Today;
use OCA\Totem\Service\TurnoService;
use OCP\IDBConnection;
use OCP\Server;

/**
 * Two requests that overlap, against real PostgreSQL. A TurnoMapper subclass plays the other request: when one
 * of this request's mapper calls runs, a hook writes what the competitor wrote in that moment. Each hook fires
 * once, so the reads that follow (the re-check, the box's state) see the database as it is.
 */
final class RacingRequestsTest extends IntegrationTestCase {
	private const NOW = 50_000;

	private Today $today;
	private TurnoMapper $turnos;
	private LlamadoMapper $llamados;

	protected function setUp(): void {
		parent::setUp();
		$this->today = $this->createMock(Today::class);
		$this->today->method('day')->willReturn(self::DAY);
		$this->today->method('now')->willReturn(self::NOW);
		$this->today->method('time')->willReturn('12:00');
		// Every moment of this suite fits one window: the Día scoping of the feed is not what races here.
		$this->today->method('dayWindow')->willReturn([0, PHP_INT_MAX]);
		$this->llamados = new LlamadoMapper(Server::get(IDBConnection::class), $this->today);
		$this->turnos = new class(Server::get(IDBConnection::class)) extends TurnoMapper {
			/** @var array<string, \Closure> the competitor's write, by the mapper method it runs inside */
			private array $hooks = [];

			/** The hook gets the parent call and this call's arguments, and answers for it. */
			public function once(string $method, \Closure $hook): void {
				$this->hooks[$method] = $hook;
			}

			public function findActiveForBox(int $boxId): ?Turno {
				return $this->run(__FUNCTION__, fn () => parent::findActiveForBox($boxId), $boxId);
			}

			public function claim(int $id, int $boxId, int $now): bool {
				return $this->run(__FUNCTION__, fn () => parent::claim($id, $boxId, $now), $id, $boxId, $now);
			}

			public function maxSeq(int $servicioId, string $day, bool $preferente): int {
				return $this->run(__FUNCTION__, fn () => parent::maxSeq($servicioId, $day, $preferente), $servicioId, $day, $preferente);
			}

			public function countForDay(int $servicioId, string $day): int {
				return $this->run(__FUNCTION__, fn () => parent::countForDay($servicioId, $day), $servicioId, $day);
			}

			private function run(string $method, \Closure $parent, mixed ...$args): mixed {
				$hook = $this->hooks[$method] ?? null;
				unset($this->hooks[$method]);

				return $hook === null ? $parent() : $hook($parent, ...$args);
			}
		};
	}

	public function testASecondCallOnTheSameBoxIsGivenBackAndRefused(): void {
		$s = $this->servicio();
		$boxId = $this->box('Box 1', $s);
		$x = $this->waiting($s, false, 1);
		$y = $this->waiting($s, false, 2);
		$this->turnos->once('findActiveForBox', function (\Closure $parent) use ($x, $boxId): ?Turno {
			$held = $parent();
			$this->turnos->claim($x->getId(), $boxId, self::NOW); // the box's other console calls X meanwhile

			return $held;
		});

		$refusal = $this->refusal(fn () => $this->calls()->callNext($boxId));

		$given = $this->turnos->find($y->getId());
		$this->assertSame(Estado::Waiting->value, $given->getEstado(), 'the turno this call took is waiting again');
		$this->assertNull($given->getBoxId());
		$this->assertNull($given->getCalledAt());
		$this->assertSame(0, $this->llamadosOf($y));
		$this->assertSame($x->getId(), $this->turnos->findActiveForBox($boxId)?->getId());
		$this->assertSame('Termine o marque como ausente el turno actual antes de llamar otro.', $refusal);
	}

	public function testAnotherBoxWinningTheTurnoStillLetsThisBoxCallTheNext(): void {
		$s = $this->servicio();
		$boxId = $this->box('Box 1', $s);
		$otherId = $this->box('Box 2', $s);
		$this->waiting($s, false, 1);
		$next = $this->waiting($s, false, 2);
		$this->turnos->once('claim', function (\Closure $parent, int $id, int $box, int $now) use ($otherId): bool {
			$this->turnos->claim($id, $otherId, $now); // the other box takes this turno a moment earlier

			return $parent();
		});

		$state = $this->calls()->callNext($boxId);

		$this->assertSame($next->getId(), $state['current']['id']);
		$this->assertSame(1, $this->turnos->countActiveForBox($boxId));
		$this->assertSame(1, $this->turnos->countActiveForBox($otherId));
		$this->assertSame(1, $this->llamadosOf($next));
	}

	public function testAnIssueThatCollidesOnTheSequencePastTheQuotaIsWithdrawn(): void {
		$s = $this->servicioWithQuota(3);
		$this->waiting($s, false, 1);
		$this->waiting($s, false, 2);
		$competitor = null;
		$this->turnos->once('maxSeq', function (\Closure $parent) use ($s, &$competitor): int {
			$max = $parent();
			$competitor = $this->waiting($s, false, $max + 1); // another totem issues the same sequence first

			return $max;
		});

		$refusal = $this->refusal(fn () => $this->issuing()->issue($s->getId(), false));

		$this->assertSame(3, $this->turnos->countForDay($s->getId(), self::DAY));
		$this->assertSame(Estado::Waiting->value, $this->turnos->find($competitor->getId())->getEstado(), 'the competitor keeps its turno');
		$this->assertSame(3, $this->turnos->maxSeq($s->getId(), self::DAY, false), 'the retry left no turno with sequence 4');
		$this->assertSame('Farmacia ya entregó todos los turnos de hoy.', $refusal);
	}

	public function testAnIssueRacingAcrossPreferenteAndGeneralPastTheQuotaIsWithdrawn(): void {
		$s = $this->servicioWithQuota(3);
		$this->waiting($s, false, 1);
		$this->waiting($s, false, 2);
		$this->turnos->once('countForDay', function (\Closure $parent) use ($s): int {
			$issued = $parent();
			$this->waiting($s, true, 1); // a preferente turno issued meanwhile: its own sequence, so nothing collides

			return $issued;
		});

		$refusal = $this->refusal(fn () => $this->issuing()->issue($s->getId(), false));

		$this->assertSame(3, $this->turnos->countForDay($s->getId(), self::DAY));
		$this->assertSame('Farmacia ya entregó todos los turnos de hoy.', $refusal);
	}

	private function calls(): CallService {
		return new CallService(
			Server::get(BoxMapper::class),
			Server::get(ServicioLinks::class),
			Server::get(ServicioMapper::class),
			$this->turnos,
			$this->llamados,
			$this->today,
		);
	}

	private function issuing(): TurnoService {
		return new TurnoService(
			Server::get(ServicioMapper::class),
			$this->turnos,
			$this->today,
			Server::get(TotemMapper::class),
			Server::get(ServicioLinks::class),
			Server::get(TicketPrinting::class),
		);
	}

	/** The sentence the request was refused with; null when it went through. */
	private function refusal(\Closure $request): ?string {
		try {
			$request();
		} catch (Refused $e) {
			return $e->getMessage();
		}

		return null;
	}

	private function servicioWithQuota(int $quota): Servicio {
		$s = $this->servicio();
		$s->setDailyQuota($quota);

		return Server::get(ServicioMapper::class)->update($s);
	}

	/** A box covering this servicio; its id. */
	private function box(string $name, Servicio $s): int {
		$box = new Box();
		$box->setName($name);
		$id = (int)Server::get(BoxMapper::class)->insert($box)->getId();
		Server::get(ServicioLinks::class)->replace(ServicioLinks::BOX, $id, [$s->getId()]);

		return $id;
	}

	/** A waiting turno issued in sequence order. */
	private function waiting(Servicio $s, bool $preferente, int $seq): Turno {
		return $this->turno($s, $preferente, $seq, 1000 + $seq);
	}

	/** How many llamados were logged for this turno. */
	private function llamadosOf(Turno $t): int {
		$all = $this->llamados->findSince([], self::DAY, 0, 50);

		return count(array_filter($all, static fn (Llamado $l) => $l->getTurnoId() === $t->getId()));
	}
}
