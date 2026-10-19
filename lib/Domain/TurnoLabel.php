<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Domain;

/**
 * The two ways a turno is named, so the totem, the ticket, the box, the display
 * and the announcement cannot spell it differently: the written label
 * ("FAR-P-014") and the sentence the room hears.
 *
 * Pure: no framework class, so the unit suite runs it bare.
 */
final class TurnoLabel {
	/** FAR-P-014: prefix, P (preferente) or G (general), the sequence padded to three digits. */
	public static function of(string $prefix, bool $preferente, int $seq): string {
		return sprintf('%s-%s-%03d', $prefix, $preferente ? 'P' : 'G', $seq);
	}

	/**
	 * The announcement. "preferente" and "general" are spoken as words: the
	 * letters P and G sound alike across a room.
	 */
	public static function spoken(string $servicio, bool $preferente, int $seq, string $box): string {
		return sprintf('Turno %s, %s %d. Diríjase a %s.', $servicio, $preferente ? 'preferente' : 'general', $seq, $box);
	}
}
