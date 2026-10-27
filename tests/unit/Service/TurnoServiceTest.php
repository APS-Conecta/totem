<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Tests\Unit\Service;

use OCA\Totem\Db\Servicio;
use OCA\Totem\Db\ServicioLinks;
use OCA\Totem\Db\ServicioMapper;
use OCA\Totem\Db\Totem;
use OCA\Totem\Db\TotemMapper;
use OCA\Totem\Db\Turno;
use OCA\Totem\Db\TurnoMapper;
use OCA\Totem\Exception\Refused;
use OCA\Totem\Service\TicketPrinting;
use OCA\Totem\Service\Today;
use OCA\Totem\Service\TurnoService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception as DbException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class TurnoServiceTest extends TestCase {
	private ServicioMapper&MockObject $servicios;
	private TurnoMapper&MockObject $turnos;
	private Today&MockObject $today;
	private TotemMapper&MockObject $totems;
	private ServicioLinks&MockObject $links;
	private TicketPrinting&MockObject $printing;
	private TurnoService $service;

	protected function setUp(): void {
		$this->servicios = $this->createMock(ServicioMapper::class);
		$this->turnos = $this->createMock(TurnoMapper::class);
		$this->today = $this->createMock(Today::class);
		$this->today->method('now')->willReturn(1_000_000);
		$this->today->method('day')->willReturn('2026-10-18');
		$this->today->method('time')->willReturn('10:00');
		$this->totems = $this->createMock(TotemMapper::class);
		$this->links = $this->createMock(ServicioLinks::class);
		$this->printing = $this->createMock(TicketPrinting::class);
		$this->service = new TurnoService($this->servicios, $this->turnos, $this->today, $this->totems, $this->links, $this->printing);
	}

	private function servicio(array $over = []): Servicio {
		$s = new Servicio();
		$s->setId(3);
		$s->setName('Farmacia');
		$s->setPrefix('FAR');
		$s->setOpens($over['opens'] ?? '07:30');
		$s->setCloses($over['closes'] ?? '16:30');
		$s->setDailyQuota($over['quota'] ?? null);
		$s->setActive($over['active'] ?? true);
		$this->servicios->method('find')->with(3)->willReturn($s);

		return $s;
	}

	private function uniqueViolation(): DbException {
		return new class ('duplicate seq') extends DbException {
			public function getReason(): ?int {
				return DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION;
			}
		};
	}

	public function testItIssuesTheNextSequenceOfTheServiciosDayAndType(): void {
		$this->servicio();
		$this->turnos->method('maxSeq')->with(3, '2026-10-18', true)->willReturn(13);
		$this->turnos->expects($this->once())->method('insert')->willReturnCallback(static function (Turno $t): Turno {
			$t->setId(77);

			return $t;
		});

		$issued = $this->service->issue(3, true);

		$this->assertSame(['id' => 77, 'label' => 'FAR-P-014', 'servicio' => 'Farmacia', 'preferente' => true, 'printed' => null], $issued);
	}

	public function testTheStoredTurnoIsWaitingForTheDayWithTheClocksTime(): void {
		$this->servicio();
		$this->turnos->method('maxSeq')->willReturn(0);
		$this->turnos->expects($this->once())->method('insert')->with($this->callback(function (Turno $t): bool {
			return $t->getServicioId() === 3 && $t->getDay() === '2026-10-18' && $t->getPreferente() === false
				&& $t->getSeq() === 1 && $t->getEstado() === 'waiting' && $t->getCreatedAt() === 1_000_000;
		}))->willReturnArgument(0);

		$this->service->issue(3, false);
	}

	public function testAServicioThatDoesNotExistIsNotFound(): void {
		$this->servicios->method('find')->willThrowException(new DoesNotExistException('x'));

		$this->expectException(DoesNotExistException::class);
		$this->service->issue(99, true);
	}

	public function testAnInactiveServicioRefuses(): void {
		$this->servicio(['active' => false]);

		$this->expectException(Refused::class);
		$this->expectExceptionMessage('Esta unidad no está disponible.');
		$this->service->issue(3, true);
	}

	public function testBeforeOpeningTheRefusalSaysTheHours(): void {
		$this->servicio(['opens' => '10:30']);

		$this->expectException(Refused::class);
		$this->expectExceptionMessage('Farmacia atiende de 10:30 a 16:30.');
		$this->service->issue(3, true);
	}

	public function testAfterClosingTheRefusalSaysTheHours(): void {
		$this->servicio(['closes' => '09:59']);

		$this->expectException(Refused::class);
		$this->expectExceptionMessage('Farmacia atiende de 07:30 a 09:59.');
		$this->service->issue(3, true);
	}

	public function testTheClosingMinuteStillIssues(): void {
		$this->servicio(['closes' => '10:00']);
		$this->turnos->method('maxSeq')->willReturn(0);
		$this->turnos->method('insert')->willReturnArgument(0);

		$this->assertSame('FAR-G-001', $this->service->issue(3, false)['label']);
	}

	public function testAReachedDailyQuotaRefuses(): void {
		$this->servicio(['quota' => 80]);
		$this->turnos->method('countForDay')->with(3, '2026-10-18')->willReturn(80);

		$this->expectException(Refused::class);
		$this->expectExceptionMessage('Farmacia ya entregó todos los turnos de hoy.');
		$this->service->issue(3, true);
	}

	public function testBelowTheQuotaStillIssues(): void {
		$this->servicio(['quota' => 80]);
		$this->turnos->method('countForDay')->willReturn(79);
		$this->turnos->method('maxSeq')->willReturn(0);
		$this->turnos->method('insert')->willReturnArgument(0);

		$this->assertSame('FAR-P-001', $this->service->issue(3, true)['label']);
	}

	public function testAnIssueThatEndsPastTheQuotaIsDeletedAndRefusedBeforePrinting(): void {
		$this->servicio(['quota' => 80]);
		$this->turnos->method('countForDay')->with(3, '2026-10-18')->willReturnOnConsecutiveCalls(79, 81);
		$this->storing();
		$this->totem();
		$this->turnos->expects($this->once())->method('delete')->with($this->callback(
			static fn (Turno $t) => $t->getId() === 77,
		))->willReturnArgument(0);
		$this->printing->expects($this->never())->method('printTurno');

		$this->expectException(Refused::class);
		$this->expectExceptionMessage('Farmacia ya entregó todos los turnos de hoy.');
		$this->service->issue(3, true, 'sala');
	}

	public function testTheTurnoThatFillsTheQuotaIsIssued(): void {
		$this->servicio(['quota' => 80]);
		$this->turnos->method('countForDay')->willReturnOnConsecutiveCalls(79, 80);
		$this->storing();
		$this->turnos->expects($this->never())->method('delete');

		$this->assertSame(77, $this->service->issue(3, true)['id']);
	}

	public function testWithoutAQuotaTheDayIsNeverCounted(): void {
		$this->servicio(['quota' => null]);
		$this->turnos->expects($this->never())->method('countForDay');
		$this->turnos->method('maxSeq')->willReturn(0);
		$this->turnos->method('insert')->willReturnArgument(0);

		$this->service->issue(3, true);
	}

	public function testACollisionOnTheSequenceRetriesWithAFreshOne(): void {
		$this->servicio();
		$this->turnos->method('maxSeq')->willReturnOnConsecutiveCalls(4, 5);
		$calls = 0;
		$this->turnos->method('insert')->willReturnCallback(function (Turno $t) use (&$calls): Turno {
			if (++$calls === 1) {
				throw $this->uniqueViolation();
			}

			return $t;
		});

		$this->assertSame('FAR-P-006', $this->service->issue(3, true)['label']);
	}

	public function testThreeCollisionsInARowRefuseWithASentence(): void {
		$this->servicio();
		$this->turnos->method('maxSeq')->willReturn(4);
		$this->turnos->method('insert')->willThrowException($this->uniqueViolation());

		$this->expectException(Refused::class);
		$this->expectExceptionMessage('No pudimos entregar su turno. Intente de nuevo.');
		$this->service->issue(3, true);
	}

	public function testAnyOtherDatabaseErrorIsNotSwallowed(): void {
		$this->servicio();
		$this->turnos->method('maxSeq')->willReturn(0);
		$this->turnos->method('insert')->willThrowException(new DbException('connection lost'));

		$this->expectException(DbException::class);
		$this->service->issue(3, true);
	}

	private function totem(?string $host = '192.168.1.50'): Totem {
		$totem = new Totem();
		$totem->setId(4);
		$totem->setSlug('sala');
		$totem->setPrinterHost($host);
		$this->totems->method('findBySlug')->with('sala')->willReturn($totem);

		return $totem;
	}

	private function storing(): void {
		$this->turnos->method('maxSeq')->willReturn(13);
		$this->turnos->method('insert')->willReturnCallback(static function (Turno $t): Turno {
			$t->setId(77);

			return $t;
		});
	}

	public function testATotemWithAPrinterPrintsTheTicketOnceAndSaysSo(): void {
		$this->servicio();
		$this->storing();
		$totem = $this->totem();
		$this->printing->expects($this->once())->method('printTurno')->with($totem, 'FAR-P-014', 'Farmacia', true)->willReturn(true);

		$this->assertTrue($this->service->issue(3, true, 'sala')['printed']);
	}

	public function testTheTicketIsPrintedOnlyAfterTheTurnoIsStored(): void {
		$this->servicio();
		$this->totem();
		$order = [];
		$this->turnos->method('maxSeq')->willReturn(13);
		$this->turnos->method('insert')->willReturnCallback(static function (Turno $t) use (&$order): Turno {
			$order[] = 'stored';
			$t->setId(77);

			return $t;
		});
		$this->printing->method('printTurno')->willReturnCallback(static function () use (&$order): bool {
			$order[] = 'printed';

			return true;
		});

		$this->service->issue(3, true, 'sala');

		$this->assertSame(['stored', 'printed'], $order);
	}

	public function testAPrinterThatFailsNeverStopsTheTurnoFromBeingIssued(): void {
		$this->servicio();
		$this->storing();
		$this->totem();
		$this->printing->method('printTurno')->willReturn(false);

		$issued = $this->service->issue(3, true, 'sala');

		$this->assertFalse($issued['printed']);
		$this->assertSame(77, $issued['id']);
		$this->assertSame('FAR-P-014', $issued['label']);
	}

	public function testWithoutATotemTheBrowserPrintsAndNothingIsSentToAPrinter(): void {
		$this->servicio();
		$this->storing();
		$this->printing->expects($this->never())->method('printTurno');

		$this->assertNull($this->service->issue(3, true)['printed']);
	}

	public function testATotemWithoutAPrinterLeavesPrintingToTheBrowserToo(): void {
		$this->servicio();
		$this->storing();
		$this->totem(host: null);
		$this->printing->expects($this->never())->method('printTurno');

		$this->assertNull($this->service->issue(3, true, 'sala')['printed']);
	}

	public function testATotemThatListsUnidadesRefusesOneItDoesNotOffer(): void {
		$this->servicio();
		$this->totem();
		$this->links->method('ids')->with(ServicioLinks::TOTEM, 4)->willReturn([5, 6]);
		$this->turnos->expects($this->never())->method('insert');
		$this->printing->expects($this->never())->method('printTurno');

		$this->expectException(Refused::class);
		$this->expectExceptionMessage('Este tótem no ofrece esa unidad.');
		$this->service->issue(3, true, 'sala');
	}

	public function testATotemThatListsNoUnidadOffersAll(): void {
		$this->servicio();
		$this->storing();
		$this->totem();
		$this->links->method('ids')->willReturn([]);

		$this->assertSame(77, $this->service->issue(3, true, 'sala')['id']);
	}

	public function testATotemThatOffersTheUnidadIssuesIt(): void {
		$this->servicio();
		$this->storing();
		$this->totem();
		$this->links->method('ids')->willReturn([3, 5]);

		$this->assertSame(77, $this->service->issue(3, true, 'sala')['id']);
	}

	public function testATotemThatNoLongerExistsRefuses(): void {
		$this->servicio();
		$this->totems->method('findBySlug')->willThrowException(new DoesNotExistException('gone'));

		$this->expectException(Refused::class);
		$this->expectExceptionMessage('Este tótem ya no existe.');
		$this->service->issue(3, true, 'borrado');
	}

	public function testARefusedIssuePrintsNothing(): void {
		$this->servicio(['active' => false]);
		$this->totem();
		$this->printing->expects($this->never())->method('printTurno');

		$this->expectException(Refused::class);
		$this->service->issue(3, true, 'sala');
	}
}
