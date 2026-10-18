<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Tests\Unit\Service;

use OCA\Totem\Db\Servicio;
use OCA\Totem\Db\ServicioMapper;
use OCA\Totem\Db\Turno;
use OCA\Totem\Db\TurnoMapper;
use OCA\Totem\Exception\Refused;
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
	private TurnoService $service;

	protected function setUp(): void {
		$this->servicios = $this->createMock(ServicioMapper::class);
		$this->turnos = $this->createMock(TurnoMapper::class);
		$this->today = $this->createMock(Today::class);
		$this->today->method('now')->willReturn(1_000_000);
		$this->today->method('day')->willReturn('2026-10-18');
		$this->today->method('time')->willReturn('10:00');
		$this->service = new TurnoService($this->servicios, $this->turnos, $this->today);
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

		$this->assertSame(['id' => 77, 'label' => 'FAR-P-014', 'servicio' => 'Farmacia', 'preferente' => true], $issued);
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
		$this->expectExceptionMessage('Este servicio no está disponible.');
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
}
