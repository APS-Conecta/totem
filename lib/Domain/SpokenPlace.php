<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Domain;

/**
 * Where a patient is told to go, as it is said aloud: "ventanilla 2" when the box's name holds one number
 * ("Box 2", "Ventanilla 2") that no other box's name holds; just "ventanilla" when the clinic has only one box;
 * otherwise the name as the screen shows it ("Caja principal", "Sector 2 Box 1"). So the voice never names a
 * number that points to another box. Pure.
 */
final class SpokenPlace {
	/** @param list<string> $boxNames every box name in the instance; $boxName need not be in it */
	public static function of(string $boxName, array $boxNames): string {
		if (count($boxNames) === 1) {
			return 'ventanilla';
		}
		$numbers = self::numbersIn($boxName);
		if (count($numbers) !== 1) {
			return trim($boxName);
		}
		foreach ($boxNames as $other) {
			if ($other !== $boxName && in_array($numbers[0], self::numbersIn($other), true)) {
				return trim($boxName);
			}
		}

		return 'ventanilla ' . $numbers[0];
	}

	/** @return list<int> */
	private static function numbersIn(string $name): array {
		preg_match_all('/\d+/', $name, $matches);

		return array_map('intval', $matches[0]);
	}
}
