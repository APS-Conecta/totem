<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Tests\Unit\Service;

use APS\Common\Csv;
use OCA\Totem\Service\StatsCsv;
use PHPUnit\Framework\TestCase;

class StatsCsvTest extends TestCase {
	private const HEADING = 'Fecha;Bloque;Unidad;Atención;Emitidos;Atendidos;No se presentó;Sin atender';

	/** @return array{id: int, name: string, prefix: string, color: string, isSector: bool, active: bool} */
	private function servicio(int $id, string $name, bool $isSector = false): array {
		return ['id' => $id, 'name' => $name, 'prefix' => 'XXX', 'color' => '#000000', 'isSector' => $isSector, 'active' => true];
	}

	/** @return array{day: string, servicio_id: int, preferente: bool, emitidos: int, atendidos: int, noShow: int, sinAtender: int} */
	private function cell(string $day, int $servicioId, bool $preferente, int $emitidos, int $atendidos, int $noShow, int $sinAtender): array {
		return ['day' => $day, 'servicio_id' => $servicioId, 'preferente' => $preferente, 'emitidos' => $emitidos, 'atendidos' => $atendidos, 'noShow' => $noShow, 'sinAtender' => $sinAtender];
	}

	/** @return list<string> */
	private function lines(string $csv): array {
		return explode("\n", rtrim(substr($csv, strlen(Csv::BOM)), "\n"));
	}

	public function testItOpensWithTheByteOrderMarkAndTheHeading(): void {
		$csv = (new StatsCsv())->render([], []);

		$this->assertStringStartsWith(Csv::BOM, $csv);
		$this->assertSame([self::HEADING], $this->lines($csv));
	}

	public function testEachCellIsOneLineWithItsBlockAndItsAtencion(): void {
		$csv = (new StatsCsv())->render(
			[$this->cell('2026-10-19', 2, true, 3, 2, 1, 0), $this->cell('2026-10-19', 1, false, 5, 5, 0, 0)],
			[$this->servicio(1, 'Farmacia'), $this->servicio(2, 'Sector Azul', true)],
		);

		$this->assertSame([
			self::HEADING,
			'2026-10-19;Sector;Sector Azul;Preferente;3;2;1;0',
			'2026-10-19;Unidad;Farmacia;General;5;5;0;0',
		], $this->lines($csv));
	}

	public function testANameWithTheSeparatorOrQuotesIsEnclosedTheRfcWay(): void {
		$csv = (new StatsCsv())->render(
			[$this->cell('2026-10-19', 1, false, 1, 1, 0, 0)],
			[$this->servicio(1, 'Box "A"; sala')],
		);

		$this->assertSame('2026-10-19;Unidad;"Box ""A""; sala";General;1;1;0;0', $this->lines($csv)[1]);
	}

	public function testANameASpreadsheetWouldRunAsAFormulaIsDefused(): void {
		$csv = (new StatsCsv())->render(
			[$this->cell('2026-10-19', 1, false, 1, 1, 0, 0)],
			[$this->servicio(1, '=HYPERLINK("x")')],
		);

		$this->assertStringContainsString(";\"'=HYPERLINK(\"\"x\"\")\";", $this->lines($csv)[1]);
	}
}
