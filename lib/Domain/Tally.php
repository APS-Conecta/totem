<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Domain;

/**
 * Folds the database's grouped counts into the statistics board: per servicio,
 * per block (the sectors and the other servicios) and per day, each split into
 * preferente, general and total.
 *
 * It counts turnos, never people: nothing here can tell two turnos of one
 * person apart (ADR-0004). Every turno is in exactly one outcome, so
 * emitidos = atendidos + noShow + sinAtender wherever a figure appears.
 *
 * @phpstan-type Counts array{emitidos: int, atendidos: int, noShow: int, sinAtender: int}
 * @phpstan-type Figure array{preferente: Counts, general: Counts, total: Counts}
 */
final class Tally {
	/**
	 * @param list<array{day: string, servicio_id: int, preferente: bool, estado: string, n: int}> $rows one per (day, servicio, preferente, estado)
	 * @param list<array{id: int, name: string, prefix: string, color: string, isSector: bool, active: bool}> $servicios in the order the board lists them
	 * @param list<string> $days every day of the period, oldest first
	 * @return array{sectores: array{rows: list<array<string, mixed>>, total: Figure}, servicios: array{rows: list<array<string, mixed>>, total: Figure}, total: Figure, days: list<array<string, mixed>>}
	 */
	public static function fold(array $rows, array $servicios, array $days): array {
		$known = array_flip(array_column($servicios, 'id'));
		$perServicio = [];
		$perDay = array_fill_keys($days, self::emptyFigure());
		foreach ($rows as $row) {
			$id = $row['servicio_id'];
			if (!isset($known[$id])) {
				continue;
			}
			$perServicio[$id] ??= self::emptyFigure();
			self::add($perServicio[$id], $row);
			if (isset($perDay[$row['day']])) {
				self::add($perDay[$row['day']], $row);
			}
		}

		$blocks = ['sectores' => [], 'servicios' => []];
		foreach ($servicios as $servicio) {
			$figure = $perServicio[$servicio['id']] ?? null;
			if ($figure === null && !$servicio['active']) {
				continue;
			}
			$blocks[$servicio['isSector'] ? 'sectores' : 'servicios'][] = [
				'id' => $servicio['id'],
				'name' => $servicio['name'],
				'prefix' => $servicio['prefix'],
				'color' => $servicio['color'],
				'active' => $servicio['active'],
			] + ($figure ?? self::emptyFigure());
		}

		$sectores = self::block($blocks['sectores']);
		$otros = self::block($blocks['servicios']);

		return [
			'sectores' => $sectores,
			'servicios' => $otros,
			'total' => self::sum([$sectores['total'], $otros['total']]),
			'days' => array_map(static fn (string $day): array => ['day' => $day] + $perDay[$day], $days),
		];
	}

	/**
	 * The same counts as one line per (day, servicio, preferente), in the order
	 * the rows came in: the detail the CSV carries, of which every figure on the
	 * board is a sum. A servicio the board does not know is left out, as in fold().
	 *
	 * @param list<array{day: string, servicio_id: int, preferente: bool, estado: string, n: int}> $rows
	 * @param list<array{id: int, name: string, prefix: string, color: string, isSector: bool, active: bool}> $servicios
	 * @return list<array{day: string, servicio_id: int, preferente: bool, emitidos: int, atendidos: int, noShow: int, sinAtender: int}>
	 */
	public static function cells(array $rows, array $servicios): array {
		$known = array_flip(array_column($servicios, 'id'));
		$cells = [];
		foreach ($rows as $row) {
			if (!isset($known[$row['servicio_id']])) {
				continue;
			}
			$key = $row['day'] . '|' . $row['servicio_id'] . '|' . (int)$row['preferente'];
			$figure = $cells[$key] ?? ['day' => $row['day'], 'servicio_id' => $row['servicio_id'], 'preferente' => $row['preferente']] + self::emptyCounts();
			$cells[$key] = self::addCounts($figure, $row);
		}

		return array_values($cells);
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 * @return array{rows: list<array<string, mixed>>, total: Figure}
	 */
	private static function block(array $rows): array {
		return ['rows' => $rows, 'total' => self::sum($rows)];
	}

	/**
	 * @param list<array<string, mixed>> $figures anything carrying preferente, general and total
	 * @return Figure
	 */
	private static function sum(array $figures): array {
		$sum = self::emptyFigure();
		foreach ($figures as $figure) {
			foreach (['preferente', 'general', 'total'] as $part) {
				foreach ($figure[$part] as $name => $n) {
					$sum[$part][$name] += $n;
				}
			}
		}

		return $sum;
	}

	/**
	 * @param Figure $figure
	 * @param array{preferente: bool, estado: string, n: int} $row
	 */
	private static function add(array &$figure, array $row): void {
		foreach ([$row['preferente'] ? 'preferente' : 'general', 'total'] as $part) {
			$figure[$part] = self::addCounts($figure[$part], $row);
		}
	}

	/**
	 * @param Counts $counts
	 * @param array{estado: string, n: int} $row
	 * @return Counts
	 */
	private static function addCounts(array $counts, array $row): array {
		$outcome = match (Estado::from($row['estado'])) {
			Estado::Completed => 'atendidos',
			Estado::NoShow => 'noShow',
			Estado::Waiting, Estado::Called, Estado::InService => 'sinAtender',
		};
		$counts['emitidos'] += $row['n'];
		$counts[$outcome] += $row['n'];

		return $counts;
	}

	/** @return Counts */
	private static function emptyCounts(): array {
		return ['emitidos' => 0, 'atendidos' => 0, 'noShow' => 0, 'sinAtender' => 0];
	}

	/** @return Figure */
	private static function emptyFigure(): array {
		$zero = self::emptyCounts();

		return ['preferente' => $zero, 'general' => $zero, 'total' => $zero];
	}
}
