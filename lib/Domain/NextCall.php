<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Domain;

/**
 * Which turno is called next. Stateless: it derives the answer from what the
 * database already says (the waiting turnos and the last two called today), so
 * there is no streak counter to drift, reset or race.
 *
 * Within one servicio, two preferentes are called for every general: after two
 * preferentes in a row the next call is a general. When the type that is due has
 * nobody waiting, the other type is called. A box covering several servicios
 * takes each servicio's own candidate and calls the one that has waited longest.
 *
 * Pure: no framework class, so the unit suite runs it bare.
 */
final class NextCall {
	/**
	 * One servicio's candidate.
	 *
	 * @param list<QueuedTurno> $waiting the servicio's waiting turnos, any order
	 * @param list<bool> $lastCalledPreferente whether each of the last two turnos called today was preferente, newest first
	 */
	public static function candidate(array $waiting, array $lastCalledPreferente): ?QueuedTurno {
		$earliest = static fn (bool $preferente): ?QueuedTurno => self::oldest(
			array_values(array_filter($waiting, static fn (QueuedTurno $t) => $t->preferente === $preferente)),
		);
		$preferente = $earliest(true);
		$general = $earliest(false);

		return self::generalIsDue($lastCalledPreferente) ? ($general ?? $preferente) : ($preferente ?? $general);
	}

	/**
	 * Whether the servicio owes a general: its last two calls were both preferente.
	 *
	 * @param list<bool> $lastCalledPreferente newest first
	 */
	public static function generalIsDue(array $lastCalledPreferente): bool {
		return count($lastCalledPreferente) === 2 && !in_array(false, $lastCalledPreferente, true);
	}

	/**
	 * Why this turno is the one up next, as a clause for the box console.
	 * $generalWasDue is generalIsDue() for the turno's servicio.
	 */
	public static function why(QueuedTurno $turno, bool $generalWasDue): string {
		if ($turno->preferente) {
			return $generalWasDue ? 'no hay atención general en espera, sigue la preferente' : 'toca atención preferente';
		}

		return $generalWasDue ? 'las 2 últimas llamadas fueron preferentes, toca atención general' : 'no hay atención preferente en espera';
	}

	/**
	 * The turno that has waited longest; the lower id breaks a tie.
	 *
	 * @param list<QueuedTurno> $candidates
	 */
	public static function oldest(array $candidates): ?QueuedTurno {
		$best = null;
		foreach ($candidates as $t) {
			if ($best === null || $t->createdAt < $best->createdAt || ($t->createdAt === $best->createdAt && $t->id < $best->id)) {
				$best = $t;
			}
		}

		return $best;
	}
}
