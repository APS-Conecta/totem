<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Service;

use OCA\Totem\Db\Servicio;
use OCA\Totem\Db\ServicioMapper;
use OCA\Totem\Db\TurnoMapper;
use OCA\Totem\Domain\Period;
use OCA\Totem\Domain\Tally;
use OCA\Totem\Exception\Refused;

/**
 * The statistics board: absolute counts of turnos for a day, a week or a
 * month, aggregated by the database on request. Turnos carry no identity, so
 * there is nothing to protect in the answer and nothing to hide: every number
 * is exact (docs/adr/0007).
 */
class StatsService {
	private const FILE_KIND = ['day' => 'dia', 'week' => 'semana', 'month' => 'mes'];

	public function __construct(
		private TurnoMapper $turnos,
		private ServicioMapper $servicios,
		private Today $today,
		private StatsCsv $csv,
	) {
	}

	/**
	 * @param ?string $date any day of the period, Y-m-d; null = today
	 * @throws Refused when the request is unreadable or the period has not started
	 */
	public function board(string $kind, ?string $date): array {
		$period = $this->period($kind, $date);
		$today = $this->today->day();
		$next = $period->shift(1);

		return [
			'period' => [
				'kind' => $period->kind(),
				'from' => $period->from(),
				'to' => $period->to(),
				'today' => $today,
				'previous' => $period->shift(-1)->from(),
				'next' => $next->startsAfter($today) ? null : $next->from(),
			],
		] + Tally::fold($this->turnos->countsBetween($period->from(), $period->to()), $this->known(), $period->days());
	}

	/**
	 * The day-by-day detail of the period as a planilla.
	 *
	 * @return array{filename: string, content: string}
	 * @throws Refused
	 */
	public function csv(string $kind, ?string $date): array {
		$period = $this->period($kind, $date);
		$servicios = $this->known();

		return [
			'filename' => sprintf('tickets-%s-%s.csv', self::FILE_KIND[$period->kind()], $period->from()),
			'content' => $this->csv->render(Tally::cells($this->turnos->countsBetween($period->from(), $period->to()), $servicios), $servicios),
		];
	}

	/** @throws Refused */
	private function period(string $kind, ?string $date): Period {
		try {
			$period = Period::of($kind, $date ?? $this->today->day());
			$period->shift(-1); // a period whose previous one cannot be represented is unreadable too: the board links to it
		} catch (\InvalidArgumentException) {
			throw new Refused('Elija día, semana o mes y una fecha válida.');
		}
		if ($period->startsAfter($this->today->day())) {
			throw new Refused('Todavía no hay datos de ese período.');
		}

		return $period;
	}

	/** @return list<array{id: int, name: string, prefix: string, color: string, isSector: bool, active: bool}> */
	private function known(): array {
		return array_map(static fn (Servicio $s): array => [
			'id' => (int)$s->getId(),
			'name' => $s->getName(),
			'prefix' => $s->getPrefix(),
			'color' => $s->getColor(),
			'isSector' => $s->getIsSector(),
			'active' => $s->getActive(),
		], $this->servicios->findAll());
	}
}
