<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Tests\Unit\Domain;

use OCA\Totem\Domain\Tally;
use PHPUnit\Framework\TestCase;

class TallyTest extends TestCase {
	private const DAYS = ['2026-10-19', '2026-10-20', '2026-10-21'];

	/** @return array{id: int, name: string, prefix: string, color: string, isSector: bool, active: bool} */
	private function servicio(int $id, string $name, bool $isSector = false, bool $active = true): array {
		return ['id' => $id, 'name' => $name, 'prefix' => strtoupper(substr($name, 0, 3)), 'color' => '#0b6e69', 'isSector' => $isSector, 'active' => $active];
	}

	/** @return array{day: string, servicio_id: int, preferente: bool, estado: string, n: int} */
	private function row(string $day, int $servicioId, bool $preferente, string $estado, int $n): array {
		return ['day' => $day, 'servicio_id' => $servicioId, 'preferente' => $preferente, 'estado' => $estado, 'n' => $n];
	}

	/** @return array{emitidos: int, atendidos: int, noShow: int, sinAtender: int} */
	private function counts(int $emitidos, int $atendidos = 0, int $noShow = 0, int $sinAtender = 0): array {
		return ['emitidos' => $emitidos, 'atendidos' => $atendidos, 'noShow' => $noShow, 'sinAtender' => $sinAtender];
	}

	public function testItSplitsEachServicioIntoPreferenteGeneralAndTotal(): void {
		$board = Tally::fold(
			[$this->row('2026-10-19', 1, true, 'completed', 2), $this->row('2026-10-19', 1, false, 'completed', 5)],
			[$this->servicio(1, 'Farmacia')],
			self::DAYS,
		);

		$farmacia = $board['servicios']['rows'][0];
		$this->assertSame($this->counts(2, 2), $farmacia['preferente']);
		$this->assertSame($this->counts(5, 5), $farmacia['general']);
		$this->assertSame($this->counts(7, 7), $farmacia['total']);
		$this->assertSame('Farmacia', $farmacia['name']);
		$this->assertSame(1, $farmacia['id']);
	}

	public function testHowATurnoEndedDecidesWhichCountItJoins(): void {
		$board = Tally::fold(
			[
				$this->row('2026-10-19', 1, false, 'completed', 4),
				$this->row('2026-10-19', 1, false, 'no_show', 3),
				$this->row('2026-10-19', 1, false, 'waiting', 2),
				$this->row('2026-10-19', 1, false, 'called', 1),
				$this->row('2026-10-19', 1, false, 'in_service', 5),
			],
			[$this->servicio(1, 'Farmacia')],
			self::DAYS,
		);

		$this->assertSame($this->counts(15, 4, 3, 8), $board['servicios']['rows'][0]['total']);
	}

	public function testEveryTurnoIsInExactlyOneOfTheThreeOutcomes(): void {
		$board = Tally::fold(
			[
				$this->row('2026-10-19', 1, true, 'completed', 4),
				$this->row('2026-10-20', 1, false, 'no_show', 3),
				$this->row('2026-10-21', 2, true, 'waiting', 2),
				$this->row('2026-10-21', 2, false, 'in_service', 1),
			],
			[$this->servicio(1, 'Farmacia'), $this->servicio(2, 'Sector Azul', true)],
			self::DAYS,
		);

		$figures = [$board['total'], $board['sectores']['total'], $board['servicios']['total'], ...$board['sectores']['rows'], ...$board['servicios']['rows'], ...$board['days']];
		foreach ($figures as $figure) {
			foreach (['preferente', 'general', 'total'] as $part) {
				$counts = $figure[$part];
				$this->assertSame($counts['emitidos'], $counts['atendidos'] + $counts['noShow'] + $counts['sinAtender']);
			}
		}
	}

	public function testASectorIsCountedInItsOwnBlockAndNotInTheOthers(): void {
		$board = Tally::fold(
			[$this->row('2026-10-19', 1, false, 'completed', 3), $this->row('2026-10-19', 2, true, 'completed', 4)],
			[$this->servicio(1, 'Farmacia'), $this->servicio(2, 'Sector Azul', true)],
			self::DAYS,
		);

		$this->assertSame(['Sector Azul'], array_column($board['sectores']['rows'], 'name'));
		$this->assertSame(['Farmacia'], array_column($board['servicios']['rows'], 'name'));
		$this->assertSame($this->counts(4, 4), $board['sectores']['total']['total']);
		$this->assertSame($this->counts(3, 3), $board['servicios']['total']['total']);
		$this->assertSame($this->counts(7, 7), $board['total']['total']);
	}

	public function testAnActiveServicioWithoutTurnosStillShowsWithZeros(): void {
		$board = Tally::fold([], [$this->servicio(1, 'Farmacia')], self::DAYS);

		$this->assertSame($this->counts(0), $board['servicios']['rows'][0]['total']);
		$this->assertSame($this->counts(0), $board['servicios']['rows'][0]['preferente']);
	}

	public function testAnInactiveServicioShowsOnlyWhenItHasCounts(): void {
		$servicios = [$this->servicio(1, 'Farmacia'), $this->servicio(2, 'Vacunatorio', false, false)];

		$without = Tally::fold([], $servicios, self::DAYS);
		$with = Tally::fold([$this->row('2026-10-20', 2, false, 'completed', 1)], $servicios, self::DAYS);

		$this->assertSame(['Farmacia'], array_column($without['servicios']['rows'], 'name'));
		$this->assertSame(['Farmacia', 'Vacunatorio'], array_column($with['servicios']['rows'], 'name'));
	}

	public function testRowsKeepTheOrderTheServiciosWereGivenIn(): void {
		$board = Tally::fold([], [$this->servicio(2, 'Vacunatorio'), $this->servicio(1, 'Farmacia')], self::DAYS);

		$this->assertSame(['Vacunatorio', 'Farmacia'], array_column($board['servicios']['rows'], 'name'));
	}

	public function testEveryDayOfThePeriodHasARowWithItsOwnTotals(): void {
		$board = Tally::fold(
			[
				$this->row('2026-10-19', 1, true, 'completed', 2),
				$this->row('2026-10-21', 1, false, 'no_show', 3),
				$this->row('2026-10-21', 2, false, 'completed', 1),
			],
			[$this->servicio(1, 'Farmacia'), $this->servicio(2, 'Sector Azul', true)],
			self::DAYS,
		);

		$this->assertSame(self::DAYS, array_column($board['days'], 'day'));
		$this->assertSame($this->counts(2, 2), $board['days'][0]['total']);
		$this->assertSame($this->counts(2, 2), $board['days'][0]['preferente']);
		$this->assertSame($this->counts(0), $board['days'][1]['total']);
		$this->assertSame($this->counts(4, 1, 3), $board['days'][2]['total']);
	}

	public function testATurnoOfAnUnknownServicioIsLeftOutEverywhere(): void {
		$board = Tally::fold([$this->row('2026-10-19', 99, false, 'completed', 6)], [$this->servicio(1, 'Farmacia')], self::DAYS);

		$this->assertSame($this->counts(0), $board['total']['total']);
		$this->assertSame($this->counts(0), $board['days'][0]['total']);
	}

	public function testItRejectsAnEstadoItDoesNotKnow(): void {
		$this->expectException(\ValueError::class);
		Tally::fold([$this->row('2026-10-19', 1, false, 'cancelled', 1)], [$this->servicio(1, 'Farmacia')], self::DAYS);
	}

	public function testCellsFoldTheEstadosOfOneDayServicioAndAtencionIntoOneLine(): void {
		$cells = Tally::cells(
			[
				$this->row('2026-10-19', 1, false, 'completed', 4),
				$this->row('2026-10-19', 1, false, 'waiting', 2),
				$this->row('2026-10-19', 1, true, 'no_show', 1),
				$this->row('2026-10-20', 2, false, 'completed', 3),
			],
			[$this->servicio(1, 'Farmacia'), $this->servicio(2, 'Sector Azul', true)],
		);

		$this->assertSame([
			['day' => '2026-10-19', 'servicio_id' => 1, 'preferente' => false] + $this->counts(6, 4, 0, 2),
			['day' => '2026-10-19', 'servicio_id' => 1, 'preferente' => true] + $this->counts(1, 0, 1, 0),
			['day' => '2026-10-20', 'servicio_id' => 2, 'preferente' => false] + $this->counts(3, 3),
		], $cells);
	}

	public function testCellsLeaveOutAServicioTheBoardDoesNotKnow(): void {
		$this->assertSame([], Tally::cells([$this->row('2026-10-19', 99, false, 'completed', 6)], [$this->servicio(1, 'Farmacia')]));
	}
}
