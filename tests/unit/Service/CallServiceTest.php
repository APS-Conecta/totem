<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Tests\Unit\Service;

use OCA\Totem\Db\Box;
use OCA\Totem\Db\BoxMapper;
use OCA\Totem\Db\Llamado;
use OCA\Totem\Db\LlamadoMapper;
use OCA\Totem\Db\Servicio;
use OCA\Totem\Db\ServicioLinks;
use OCA\Totem\Db\ServicioMapper;
use OCA\Totem\Db\Turno;
use OCA\Totem\Db\TurnoMapper;
use OCA\Totem\Domain\Estado;
use OCA\Totem\Exception\Refused;
use OCA\Totem\Service\CallService;
use OCA\Totem\Service\Today;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CallServiceTest extends TestCase {
	private BoxMapper&MockObject $boxes;
	private ServicioLinks&MockObject $links;
	private ServicioMapper&MockObject $servicios;
	private TurnoMapper&MockObject $turnos;
	private LlamadoMapper&MockObject $llamados;
	private CallService $service;
	/** @var array<int, list<Turno>> waiting turnos by servicio id */
	private array $waiting = [];
	/** @var array<int, list<bool>> last called preferente flags by servicio id */
	private array $history = [];

	protected function setUp(): void {
		$this->boxes = $this->createMock(BoxMapper::class);
		$this->links = $this->createMock(ServicioLinks::class);
		$this->servicios = $this->createMock(ServicioMapper::class);
		$this->turnos = $this->createMock(TurnoMapper::class);
		$this->llamados = $this->createMock(LlamadoMapper::class);
		$today = $this->createMock(Today::class);
		$today->method('now')->willReturn(10_000);
		$today->method('day')->willReturn('2026-10-18');

		$box = new Box();
		$box->setId(1);
		$box->setName('Box 3');
		$this->boxes->method('find')->willReturnCallback(static fn (int $id) => $id === 1 ? $box : throw new DoesNotExistException('x'));
		$this->links->method('ids')->willReturn([3, 4]);
		$this->servicios->method('find')->willReturnCallback(fn (int $id) => $this->servicio($id));
		$this->turnos->method('findWaiting')->willReturnCallback(fn (int $id) => $this->waiting[$id] ?? []);
		$this->turnos->method('lastCalledPreferente')->willReturnCallback(fn (int $id) => $this->history[$id] ?? []);

		$this->service = new CallService($this->boxes, $this->links, $this->servicios, $this->turnos, $this->llamados, $today);
	}

	private function servicio(int $id): Servicio {
		$s = new Servicio();
		$s->setId($id);
		$s->setName($id === 3 ? 'Farmacia' : 'Toma de Muestras');
		$s->setPrefix($id === 3 ? 'FAR' : 'MUE');
		$s->setColor($id === 3 ? '#0b6e69' : '#a32035');

		return $s;
	}

	private function turno(int $id, int $servicioId, bool $preferente, int $seq, int $createdAt, string $estado = 'waiting'): Turno {
		$t = new Turno();
		$t->setId($id);
		$t->setServicioId($servicioId);
		$t->setPreferente($preferente);
		$t->setSeq($seq);
		$t->setCreatedAt($createdAt);
		$t->setEstado($estado);
		$t->setBoxId(1);
		$t->setCalledAt(9_000);

		return $t;
	}

	private function waits(Turno ...$turnos): void {
		foreach ($turnos as $t) {
			$this->waiting[$t->getServicioId()][] = $t;
		}
	}

	/** What the box holds on each successive read: the database changes under a call, a mock does not. */
	private function active(?Turno ...$reads): void {
		if (count($reads) === 1) {
			$this->turnos->method('findActiveForBox')->willReturn($reads[0]);

			return;
		}
		$this->turnos->method('findActiveForBox')->willReturnOnConsecutiveCalls(...$reads);
	}

	public function testItCallsTheBoxsCandidateRecordsTheLlamadoAndReportsIt(): void {
		$this->active(null, $this->turno(10, 3, true, 14, 500, 'called'));
		$this->waits($this->turno(10, 3, true, 14, 500));
		$this->turnos->expects($this->once())->method('claim')->with(10, 1, 10_000)->willReturn(true);
		$this->llamados->expects($this->once())->method('insert')->with($this->callback(
			static fn (Llamado $l) => $l->getTurnoId() === 10 && $l->getServicioId() === 3 && $l->getBoxId() === 1 && $l->getCalledAt() === 10_000,
		))->willReturnArgument(0);

		$state = $this->service->callNext(1);

		$this->assertSame('FAR-P-014', $state['current']['label']);
		$this->assertSame('called', $state['current']['estado']);
	}

	public function testABoxCoveringTwoServiciosCallsTheOneThatHasWaitedLongest(): void {
		$this->active(null, $this->turno(20, 4, false, 6, 300, 'called'));
		$this->waits($this->turno(10, 3, true, 14, 500), $this->turno(20, 4, false, 6, 300));
		$this->turnos->expects($this->once())->method('claim')->with(20, 1, 10_000)->willReturn(true);
		$this->llamados->method('insert')->willReturnArgument(0);

		$this->assertSame('MUE-G-006', $this->service->callNext(1)['current']['label']);
	}

	public function testTheTwoToOneRuleAppliesWithinEachServicio(): void {
		$this->active(null, $this->turno(11, 3, false, 30, 200, 'called'));
		$this->history[3] = [true, true];
		$this->waits($this->turno(10, 3, true, 14, 100), $this->turno(11, 3, false, 30, 200));
		$this->turnos->expects($this->once())->method('claim')->with(11, 1, 10_000)->willReturn(true);
		$this->llamados->method('insert')->willReturnArgument(0);

		$this->assertSame('FAR-G-030', $this->service->callNext(1)['current']['label']);
	}

	public function testItRefusesWhileTheBoxStillHoldsATurno(): void {
		$this->active($this->turno(9, 3, true, 12, 100, 'called'));

		$this->expectException(Refused::class);
		$this->expectExceptionMessage('Termine o marque como ausente el turno actual antes de llamar otro.');
		$this->service->callNext(1);
	}

	public function testItRefusesWhenNobodyIsWaiting(): void {
		$this->active(null);

		$this->expectException(Refused::class);
		$this->expectExceptionMessage('No hay turnos en espera.');
		$this->service->callNext(1);
	}

	public function testALostRaceMovesOnToTheNextTurno(): void {
		$this->active(null, $this->turno(10, 3, true, 14, 100, 'called'));
		$this->waits($this->turno(10, 3, true, 14, 100));
		$this->turnos->method('claim')->willReturnOnConsecutiveCalls(false, true);
		$this->llamados->expects($this->once())->method('insert')->willReturnArgument(0);

		$this->assertSame('FAR-P-014', $this->service->callNext(1)['current']['label']);
	}

	public function testAClaimThatLeavesTheBoxHoldingTwoIsReleasedAndRefused(): void {
		$this->active(null);
		$this->waits($this->turno(10, 3, true, 14, 100));
		$this->turnos->method('claim')->willReturn(true);
		$this->turnos->expects($this->once())->method('countActiveForBox')->with(1)->willReturn(2);
		$this->turnos->expects($this->once())->method('release')->with(10, 1)->willReturn(true);
		$this->llamados->expects($this->never())->method('insert');

		$this->expectException(Refused::class);
		$this->expectExceptionMessage('Termine o marque como ausente el turno actual antes de llamar otro.');
		$this->service->callNext(1);
	}

	public function testThreeLostRacesRefuse(): void {
		$this->active(null);
		$this->waits($this->turno(10, 3, true, 14, 100));
		$this->turnos->method('claim')->willReturn(false);
		$this->llamados->expects($this->never())->method('insert');

		$this->expectException(Refused::class);
		$this->expectExceptionMessage('Otra ventanilla tomó ese turno. Intente de nuevo.');
		$this->service->callNext(1);
	}

	public function testABoxWithNoServiciosRefuses(): void {
		$links = $this->createMock(ServicioLinks::class);
		$links->method('ids')->willReturn([]);
		$service = new CallService($this->boxes, $links, $this->servicios, $this->turnos, $this->llamados, $this->createMock(Today::class));

		$this->expectException(Refused::class);
		$this->expectExceptionMessage('Esta ventanilla no tiene unidades asignadas.');
		$service->callNext(1);
	}

	public function testAnUnknownBoxIsNotFound(): void {
		$this->expectException(DoesNotExistException::class);
		$this->service->callNext(99);
	}

	public function testRecallAddsALlamadoWithoutTouchingTheTurno(): void {
		$this->active($this->turno(10, 3, true, 14, 100, 'called'));
		$this->turnos->expects($this->never())->method('claim');
		$this->llamados->expects($this->once())->method('insert')->with($this->callback(
			static fn (Llamado $l) => $l->getTurnoId() === 10 && $l->getBoxId() === 1 && $l->getCalledAt() === 10_000,
		))->willReturnArgument(0);

		$this->assertSame('called', $this->service->recall(1)['current']['estado']);
	}

	public function testRecallRefusesWhenTheTurnoIsAlreadyInService(): void {
		$this->active($this->turno(10, 3, true, 14, 100, 'in_service'));

		$this->expectException(Refused::class);
		$this->expectExceptionMessage('No hay un turno llamado para repetir.');
		$this->service->recall(1);
	}

	public function testStartMovesACalledTurnoIntoService(): void {
		$this->active($this->turno(10, 3, true, 14, 100, 'called'), $this->turno(10, 3, true, 14, 100, 'in_service'));
		$this->turnos->expects($this->once())->method('transition')->with(10, Estado::Called, Estado::InService, 10_000)->willReturn(true);

		$this->assertSame('in_service', $this->service->start(1)['current']['estado']);
	}

	public function testFinishCompletesATurnoInService(): void {
		$this->active($this->turno(10, 3, true, 14, 100, 'in_service'), null);
		$this->turnos->expects($this->once())->method('transition')->with(10, Estado::InService, Estado::Completed, 10_000)->willReturn(true);

		$state = $this->service->finish(1);

		$this->assertNull($state['current']);
	}

	public function testAbsentMarksACalledTurnoAsNoShow(): void {
		$this->active($this->turno(10, 3, true, 14, 100, 'called'), null);
		$this->turnos->expects($this->once())->method('transition')->with(10, Estado::Called, Estado::NoShow, 10_000)->willReturn(true);

		$this->assertNull($this->service->absent(1)['current']);
	}

	public function testStartRefusesWhenNothingIsCalled(): void {
		$this->active(null);

		$this->expectException(Refused::class);
		$this->expectExceptionMessage('No hay un turno llamado para iniciar.');
		$this->service->start(1);
	}

	public function testFinishRefusesWhenNothingIsInService(): void {
		$this->active($this->turno(10, 3, true, 14, 100, 'called'));

		$this->expectException(Refused::class);
		$this->expectExceptionMessage('No hay un turno en atención para finalizar.');
		$this->service->finish(1);
	}

	public function testATransitionThatLostARaceAsksForARefresh(): void {
		$this->active($this->turno(10, 3, true, 14, 100, 'called'));
		$this->turnos->method('transition')->willReturn(false);

		$this->expectException(Refused::class);
		$this->expectExceptionMessage('El turno ya cambió. Actualice la pantalla.');
		$this->service->start(1);
	}

	public function testTheIdleStateListsBothColasOldestFirstAndExplainsTheNext(): void {
		$this->active(null);
		$this->history[3] = [];
		$this->waits($this->turno(10, 3, true, 14, 500), $this->turno(20, 4, false, 6, 300));

		$state = $this->service->state(1);

		$this->assertSame('Box 3', $state['box']['name']);
		$this->assertSame(['Farmacia', 'Toma de Muestras'], $state['box']['servicios']);
		$this->assertNull($state['current']);
		$this->assertSame(['MUE-G-006', 'FAR-P-014'], array_column($state['waiting'], 'label'));
		$this->assertSame('MUE-G-006', $state['next']['label']);
		$this->assertSame(
			'Siguiente: MUE-G-006 (Toma de Muestras): no hay atención preferente en espera. Entre las unidades de la ventanilla, es el que más espera.',
			$state['why'],
		);
	}

	public function testWhileATurnoIsHeldThereIsNoNextAndNoExplanation(): void {
		$this->active($this->turno(9, 3, true, 12, 100, 'called'));
		$this->waits($this->turno(10, 3, true, 14, 500));

		$state = $this->service->state(1);

		$this->assertNull($state['next']);
		$this->assertSame('', $state['why']);
		$this->assertSame('FAR-P-012', $state['current']['label']);
		$this->assertSame('#0b6e69', $state['current']['color']);
		$this->assertTrue($state['current']['preferente']);
		$this->assertSame(9_000, $state['current']['since']);
	}

	public function testAnEmptyBoxHasNothingToExplain(): void {
		$this->active(null);

		$state = $this->service->state(1);

		$this->assertNull($state['next']);
		$this->assertSame('No hay turnos en espera en las unidades de esta ventanilla.', $state['why']);
	}
}
