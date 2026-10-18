<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Service;

use APS\Common\Csv;

/**
 * The statistics board as a planilla: one line per day, servicio and atención,
 * of which every figure on screen is a sum. The dialect is the one a Chilean
 * spreadsheet speaks (farmacia's CsvWriter): `;` between cells, because the
 * comma is the decimal separator in es-CL, and a BOM so Excel reads the
 * accents. Quoting is written here (RFC 4180: enclose a cell that carries the
 * delimiter, a quote or a line break) because fputcsv's quoting differs
 * between PHP 8.2 and 8.4. Names are admin text, so they are defused against
 * spreadsheet formulas. Depends on no OCP class.
 */
class StatsCsv {
	private const HEADING = ['Fecha', 'Bloque', 'Servicio', 'Atención', 'Emitidos', 'Atendidos', 'No se presentó', 'Sin atender'];

	/**
	 * @param list<array{day: string, servicio_id: int, preferente: bool, emitidos: int, atendidos: int, noShow: int, sinAtender: int}> $cells see Tally::cells()
	 * @param list<array{id: int, name: string, isSector: bool}> $servicios
	 */
	public function render(array $cells, array $servicios): string {
		$byId = array_column($servicios, null, 'id');
		$lines = [self::line(self::HEADING)];
		foreach ($cells as $cell) {
			$servicio = $byId[$cell['servicio_id']];
			$lines[] = self::line([
				$cell['day'],
				$servicio['isSector'] ? 'Sector' : 'Servicio',
				Csv::text($servicio['name']),
				$cell['preferente'] ? 'Preferente' : 'General',
				(string)$cell['emitidos'],
				(string)$cell['atendidos'],
				(string)$cell['noShow'],
				(string)$cell['sinAtender'],
			]);
		}

		return Csv::BOM . implode("\n", $lines) . "\n";
	}

	/** @param list<string> $cells */
	private static function line(array $cells): string {
		return implode(';', array_map(
			static fn (string $cell): string => strpbrk($cell, ";\"\n\r") !== false
				? '"' . str_replace('"', '""', $cell) . '"'
				: $cell,
			$cells,
		));
	}
}
