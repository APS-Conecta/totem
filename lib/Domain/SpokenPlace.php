<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Domain;

/**
 * Where a patient is told to go, as it is said aloud: "ventanilla 2", where the 2 is the number in the
 * ventanilla's name ("Box 2", "Ventanilla 2"); just "ventanilla" when the clinic has only one; and the name as
 * typed when it carries no number ("Caja principal"). Pure.
 */
final class SpokenPlace {
	public static function of(string $ventanillaName, bool $onlyOne): string {
		if ($onlyOne) {
			return 'ventanilla';
		}
		if (preg_match('/\d+/', $ventanillaName, $number) === 1) {
			return 'ventanilla ' . (int)$number[0];
		}

		return trim($ventanillaName);
	}
}
