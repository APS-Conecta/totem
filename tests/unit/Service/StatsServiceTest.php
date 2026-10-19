<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Tests\Unit\Service;

use OCA\Totem\Db\Servicio;
use OCA\Totem\Db\ServicioMapper;
use OCA\Totem\Db\TurnoMapper;
use OCA\Totem\Exception\Refused;
use OCA\Totem\Service\StatsCsv;
use OCA\Totem\Service\StatsService;
use OCA\Totem\Service\Today;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class StatsServiceTest extends TestCase {
	private TurnoMapper&MockObject $turnos;
	private ServicioMapper&MockObject $servicios;
	private StatsService $service;

	protected function setUp(): void {
		$this->turnos = $this->createMock(TurnoMapper::class);
		$this->servicios = $this->createMock(ServicioMapper::class);
		$this->servicios->method('findAll')->willReturn([
			$this->servicio(1, 'Farmacia', 'FAR', false),
			$this->servicio(2, 'Sector Azul', 'AZU', true),
		]);
		$today = $this->createMock(Today::class);
		$today->method('day')->willReturn('2026-10-21'); // a Wednesday
		$this->service = new StatsService($this->turnos, $this->servicios, $today, new StatsCsv());
	}

	private function servicio(int $id, string $name, string $prefix, bool $isSector): Servicio {
		$s = new Servicio();
		$s->setId($id);
		$s->setName($name);
		$s->setPrefix($prefix);
		$s->setColor('#0b6e69');
		$s->setActive(true);
		$s->setIsSector($isSector);

		return $s;
	}

	/** @return array{day: string, servicio_id: int, preferente: bool, estado: string, n: int} */
	private function row(string $day, int $servicioId, bool $preferente, string $estado, int $n): array {
		return ['day' => $day, 'servicio_id' => $servicioId, 'preferente' => $preferente, 'estado' => $estado, 'n' => $n];
	}

	public function testItCountsTheWholeWeekFromMondayToSunday(): void {
		$this->turnos->expects($this->once())->method('countsBetween')->with('2026-10-19', '2026-10-25')->willReturn([]);

		$board = $this->service->board('week', '2026-10-21');

		$this->assertSame(['kind' => 'week', 'from' => '2026-10-19', 'to' => '2026-10-25', 'today' => '2026-10-21', 'previous' => '2026-10-12', 'next' => null], $board['period']);
		$this->assertCount(7, $board['days']);
	}

	public function testThereIsANextPeriodOnlyWhenItHasStarted(): void {
		$this->turnos->method('countsBetween')->willReturn([]);

		$this->assertSame('2026-10-19', $this->service->board('week', '2026-10-14')['period']['next']);
		$this->assertSame('2026-10-21', $this->service->board('day', '2026-10-20')['period']['next']);
		$this->assertNull($this->service->board('day', '2026-10-21')['period']['next']);
	}

	public function testWithoutADateItIsThePeriodOfToday(): void {
		$this->turnos->expects($this->once())->method('countsBetween')->with('2026-10-01', '2026-10-31')->willReturn([]);

		$this->assertSame('2026-10-01', $this->service->board('month', null)['period']['from']);
	}

	public function testSectoresAndOtherServiciosAreCountedInTheirOwnBlocks(): void {
		$this->turnos->method('countsBetween')->willReturn([
			$this->row('2026-10-19', 1, false, 'completed', 3),
			$this->row('2026-10-20', 2, true, 'waiting', 2),
		]);

		$board = $this->service->board('week', '2026-10-21');

		$this->assertSame(['Sector Azul'], array_column($board['sectores']['rows'], 'name'));
		$this->assertSame(2, $board['sectores']['total']['preferente']['sinAtender']);
		$this->assertSame(['Farmacia'], array_column($board['servicios']['rows'], 'name'));
		$this->assertSame(3, $board['servicios']['total']['general']['atendidos']);
		$this->assertSame(5, $board['total']['total']['emitidos']);
	}

	public function testAPeriodThatHasNotStartedHasNothingToCount(): void {
		$this->turnos->expects($this->never())->method('countsBetween');

		$this->expectException(Refused::class);
		$this->expectExceptionMessage('Todavía no hay datos de ese período.');
		$this->service->board('day', '2026-10-22');
	}

	/** @return array<string, array{string, string}> */
	public static function unreadable(): array {
		return [
			'an unknown kind' => ['year', '2026-10-21'],
			'no kind' => ['', '2026-10-21'],
			'a date that does not exist' => ['day', '2026-02-30'],
			'text for a date' => ['day', 'ayer'],
		];
	}

	#[DataProvider('unreadable')]
	public function testAnUnreadableRequestRefusesWithASentence(string $kind, string $date): void {
		$this->turnos->expects($this->never())->method('countsBetween');

		$this->expectException(Refused::class);
		$this->expectExceptionMessage('Elija día, semana o mes y una fecha válida.');
		$this->service->board($kind, $date);
	}

	public function testTheCsvIsNamedAfterThePeriodAndHoldsItsDetail(): void {
		$this->turnos->method('countsBetween')->willReturn([$this->row('2026-10-20', 2, true, 'completed', 4)]);

		$csv = $this->service->csv('week', '2026-10-21');

		$this->assertSame('tickets-semana-2026-10-19.csv', $csv['filename']);
		$this->assertStringContainsString("\n2026-10-20;Sector;Sector Azul;Preferente;4;4;0;0\n", $csv['content']);
	}

	public function testTheCsvOfAFuturePeriodIsRefusedToo(): void {
		$this->expectException(Refused::class);
		$this->service->csv('month', '2026-11-03');
	}

	public function testTheCsvFilenameSaysDayOrMonthInSpanish(): void {
		$this->turnos->method('countsBetween')->willReturn([]);

		$this->assertSame('tickets-dia-2026-10-21.csv', $this->service->csv('day', '2026-10-21')['filename']);
		$this->assertSame('tickets-mes-2026-10-01.csv', $this->service->csv('month', '2026-10-21')['filename']);
	}
}
