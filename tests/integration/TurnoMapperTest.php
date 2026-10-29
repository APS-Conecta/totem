<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Tests\Integration;

use OCA\Totem\Db\Llamado;
use OCA\Totem\Db\LlamadoMapper;
use OCA\Totem\Db\Servicio;
use OCA\Totem\Db\ServicioMapper;
use OCA\Totem\Db\Turno;
use OCA\Totem\Db\TurnoMapper;
use OCA\Totem\Domain\Estado;
use OCP\DB\Exception;
use OCP\Server;

/**
 * The SQL the services lean on, against real PostgreSQL: the unique sequence
 * index, the atomic claim, the "last two called" read and the display cursor.
 */
final class TurnoMapperTest extends IntegrationTestCase {
	private ServicioMapper $servicios;
	private TurnoMapper $turnos;
	private LlamadoMapper $llamados;

	protected function setUp(): void {
		parent::setUp();
		$this->servicios = Server::get(ServicioMapper::class);
		$this->turnos = Server::get(TurnoMapper::class);
		$this->llamados = Server::get(LlamadoMapper::class);
	}

	public function testAServicioRoundTripsWithItsNullableQuota(): void {
		$s = $this->servicio();
		$found = $this->servicios->find($s->getId());

		$this->assertSame('Farmacia', $found->getName());
		$this->assertSame('#0b6e69', $found->getColor());
		$this->assertNull($found->getDailyQuota());
		$this->assertTrue($found->getActive());
	}

	public function testTheSequenceIsUniquePerServicioDayAndType(): void {
		$s = $this->servicio();
		$this->turno($s, true, 1);

		$this->turno($s, false, 1); // the other type has its own sequence
		$this->turno($s, true, 1, 1000, '2026-10-19'); // and so does the next day

		$this->expectException(Exception::class);
		$this->turno($s, true, 1);
	}

	public function testMaxSeqAndCountReadOnlyTheServiciosDayAndType(): void {
		$s = $this->servicio();
		$this->turno($s, true, 1);
		$this->turno($s, true, 2);
		$this->turno($s, false, 1);
		$this->turno($s, true, 9, 1000, '2026-10-19');

		$this->assertSame(2, $this->turnos->maxSeq($s->getId(), self::DAY, true));
		$this->assertSame(1, $this->turnos->maxSeq($s->getId(), self::DAY, false));
		$this->assertSame(0, $this->turnos->maxSeq($s->getId(), '2026-10-20', true));
		$this->assertSame(3, $this->turnos->countForDay($s->getId(), self::DAY));
	}

	public function testWaitingTurnosComeBackOldestFirst(): void {
		$s = $this->servicio();
		$late = $this->turno($s, true, 2, 2000);
		$early = $this->turno($s, false, 1, 1000);

		$ids = array_map(static fn (Turno $t) => $t->getId(), $this->turnos->findWaiting($s->getId(), self::DAY));

		$this->assertSame([$early->getId(), $late->getId()], $ids);
	}

	public function testAWaitingTurnoCanBeClaimedExactlyOnce(): void {
		$s = $this->servicio();
		$t = $this->turno($s, true, 1);

		$this->assertTrue($this->turnos->claim($t->getId(), 7, 5000));
		$this->assertFalse($this->turnos->claim($t->getId(), 8, 5001), 'a second box must lose the race');

		$after = $this->turnos->find($t->getId());
		$this->assertSame('called', $after->getEstado());
		$this->assertSame(7, $after->getBoxId());
		$this->assertSame(5000, $after->getCalledAt());
	}

	public function testATransitionOnlyAppliesFromTheExpectedEstado(): void {
		$s = $this->servicio();
		$t = $this->turno($s, true, 1);
		$this->turnos->claim($t->getId(), 7, 5000);

		$this->assertFalse($this->turnos->transition($t->getId(), Estado::InService, Estado::Completed, 6000));
		$this->assertTrue($this->turnos->transition($t->getId(), Estado::Called, Estado::InService, 6000));
		$this->assertSame(6000, $this->turnos->find($t->getId())->getServedAt());
		$this->assertTrue($this->turnos->transition($t->getId(), Estado::InService, Estado::Completed, 7000));
		$this->assertSame(7000, $this->turnos->find($t->getId())->getFinishedAt());
	}

	public function testTheBoxsActiveTurnoIsTheOneCalledOrInService(): void {
		$s = $this->servicio();
		$done = $this->turno($s, true, 1);
		$open = $this->turno($s, true, 2);
		$this->turnos->claim($done->getId(), 7, 5000);
		$this->turnos->transition($done->getId(), Estado::Called, Estado::InService, 5100);
		$this->turnos->transition($done->getId(), Estado::InService, Estado::Completed, 5200);
		$this->turnos->claim($open->getId(), 7, 5300);
		$yesterdays = $this->turno($s, true, 9, 1000, '2026-10-17');
		$this->turnos->claim($yesterdays->getId(), 8, 900);

		$this->assertSame($open->getId(), $this->turnos->findActiveForBox(7)->getId());
		$this->assertSame($yesterdays->getId(), $this->turnos->findActiveForBox(8)?->getId(), 'a box keeps its turno whatever its Día');
		$this->assertNull($this->turnos->findActiveForBox(9));
	}

	public function testCountActiveForBoxCountsOnlyThatBoxsCalledAndInServiceTurnos(): void {
		$s = $this->servicio();
		$called = $this->turno($s, false, 1);
		$inService = $this->turno($s, false, 2);
		$completed = $this->turno($s, false, 3);
		$this->turno($s, false, 4); // still waiting
		$otherBox = $this->turno($s, false, 5);
		$yesterdays = $this->turno($s, false, 1, 1000, '2026-10-17');
		$this->turnos->claim($called->getId(), 7, 5000);
		$this->turnos->claim($inService->getId(), 7, 5100);
		$this->turnos->transition($inService->getId(), Estado::Called, Estado::InService, 5200);
		$this->turnos->claim($completed->getId(), 7, 5300);
		$this->turnos->transition($completed->getId(), Estado::Called, Estado::InService, 5400);
		$this->turnos->transition($completed->getId(), Estado::InService, Estado::Completed, 5500);
		$this->turnos->claim($otherBox->getId(), 8, 5600);
		$this->turnos->claim($yesterdays->getId(), 7, 100);

		$this->assertSame(3, $this->turnos->countActiveForBox(7), 'a turno the box holds counts whatever its Día');
	}

	public function testReleaseGivesBackOnlyACalledTurnoOfThatBox(): void {
		$s = $this->servicio();
		$called = $this->turno($s, false, 1);
		$inService = $this->turno($s, false, 2);
		$this->turnos->claim($called->getId(), 7, 5000);
		$this->turnos->claim($inService->getId(), 7, 5100);
		$this->turnos->transition($inService->getId(), Estado::Called, Estado::InService, 5200);

		$this->assertFalse($this->turnos->release($called->getId(), 8), 'another box cannot give it back');
		$this->assertFalse($this->turnos->release($inService->getId(), 7), 'a turno in service is not given back');
		$this->assertTrue($this->turnos->release($called->getId(), 7));

		$after = $this->turnos->find($called->getId());
		$this->assertSame('waiting', $after->getEstado());
		$this->assertNull($after->getBoxId());
		$this->assertNull($after->getCalledAt());
		$this->assertSame('in_service', $this->turnos->find($inService->getId())->getEstado());
	}

	public function testTheLastCalledPreferenteFlagsAreNewestFirstAndAnswerForTodayOnly(): void {
		$s = $this->servicio();
		$a = $this->turno($s, true, 1);
		$b = $this->turno($s, false, 1);
		$c = $this->turno($s, true, 2);
		$old = $this->turno($s, false, 7, 1000, '2026-10-17');
		$this->turnos->claim($old->getId(), 7, 100);
		$this->turnos->claim($a->getId(), 7, 5000);
		$this->turnos->claim($b->getId(), 7, 5100);
		$this->turnos->claim($c->getId(), 7, 5200);

		$this->assertSame([true, false], $this->turnos->lastCalledPreferente($s->getId(), self::DAY, 2));
		$this->assertSame([], $this->turnos->lastCalledPreferente($s->getId(), '2026-10-20', 2));
	}

	public function testTheDisplayFeedReturnsLlamadosAfterTheCursorForTheChosenServicios(): void {
		$far = $this->servicio('Farmacia', 'FAR');
		$mue = $this->servicio('Toma de Muestras', 'MUE');
		$t1 = $this->turno($far, true, 1);
		$t2 = $this->turno($mue, false, 1);
		$l1 = $this->llamado($t1, 7, $this->momentOf(self::DAY, '10:00'));
		$l2 = $this->llamado($t2, 8, $this->momentOf(self::DAY, '10:01'));
		$this->llamado($t1, 7, $this->momentOf(self::DAY, '10:02')); // a re-llamado is another row
		// The feed scopes by when the llamado was made, so a llamado of the same turno made the night
		// before stays out of this Día's feed even though the turno belongs to it.
		$this->llamado($t1, 7, $this->momentOf('2026-10-17', '23:00'));

		$all = $this->llamados->findSince([], self::DAY, 0, 50);
		$this->assertCount(3, $all);
		$this->assertSame([$l1->getId(), $l2->getId()], array_slice(array_map(static fn (Llamado $l) => $l->getId(), $all), 0, 2));

		$afterFirst = $this->llamados->findSince([], self::DAY, $l1->getId(), 50);
		$this->assertCount(2, $afterFirst);

		$onlyMuestras = $this->llamados->findSince([$mue->getId()], self::DAY, 0, 50);
		$this->assertSame([$l2->getId()], array_map(static fn (Llamado $l) => $l->getId(), $onlyMuestras));
	}

	public function testCountsBetweenGroupsTheTurnosOfThePeriodByDayServicioPreferenteAndEstado(): void {
		$far = $this->servicio('Farmacia', 'FAR');
		$mue = $this->servicio('Toma de Muestras', 'MUE');
		$this->turno($far, false, 1, 1000, '2026-10-19');
		$this->turno($far, false, 2, 1001, '2026-10-19');
		$this->turnoEnded($far, true, 1, Estado::Completed, '2026-10-19');
		$this->turnoEnded($mue, false, 1, Estado::NoShow, '2026-10-20');
		$this->turno($far, false, 3, 1002, '2026-10-18'); // the day before: outside
		$this->turno($far, false, 4, 1003, '2026-10-27'); // the day after: outside

		$counts = $this->turnos->countsBetween('2026-10-19', '2026-10-26');

		$this->assertSame([
			['day' => '2026-10-19', 'servicio_id' => $far->getId(), 'preferente' => false, 'estado' => 'waiting', 'n' => 2],
			['day' => '2026-10-19', 'servicio_id' => $far->getId(), 'preferente' => true, 'estado' => 'completed', 'n' => 1],
			['day' => '2026-10-20', 'servicio_id' => $mue->getId(), 'preferente' => false, 'estado' => 'no_show', 'n' => 1],
		], $counts);
	}

	public function testCountsBetweenIsEmptyWhenNothingWasIssued(): void {
		$this->assertSame([], $this->turnos->countsBetween('2026-10-19', '2026-10-26'));
	}

	public function testAServicioKeepsItsSectorFlagThroughTheDatabase(): void {
		$azul = $this->servicio('Sector Azul', 'AZU');
		$azul->setIsSector(true);
		$this->servicios->update($azul);

		$this->assertTrue($this->servicios->find($azul->getId())->getIsSector());
		$this->assertFalse($this->servicios->find($this->servicio('Farmacia', 'FAR')->getId())->getIsSector());
	}

	private function turnoEnded(Servicio $s, bool $preferente, int $seq, Estado $estado, string $day): Turno {
		$t = $this->turno($s, $preferente, $seq, 1000, $day);
		$t->setEstado($estado->value);

		return $this->turnos->update($t);
	}

	private function llamado(Turno $t, int $boxId, int $at): Llamado {
		$l = new Llamado();
		$l->setTurnoId($t->getId());
		$l->setServicioId($t->getServicioId());
		$l->setBoxId($boxId);
		$l->setCalledAt($at);

		return $this->llamados->insert($l);
	}
}
