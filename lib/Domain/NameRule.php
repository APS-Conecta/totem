<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Domain;

use APS\Common\Text;

/**
 * The name rule Tótems, Pantallas and Boxes share. A name needs an ASCII letter or digit once accents are dropped:
 * otherwise Text::slugFor makes nothing, which is no address and would compare equal to every other such name (#39).
 * A name is taken when another record's current name gives the same Text::slugFor (accents, case, spacing and
 * punctuation ignored); the record itself never counts. An update checks the name only when it changes: records
 * that shared a name before the rule existed stay editable, and so does a record that keeps its exact name when that
 * name makes no address. A Tótem's or Pantalla's address is made from the name at creation, with -2, -3… when a
 * renamed record still holds it, and never changes afterwards. slugFor never makes "-", so a derived address is never
 * another name's own address.
 *
 * Pure: it reads names and addresses, never records, so one test covers the rule for every kind of record.
 */
final class NameRule {
	private const NO_ADDRESS = 'El nombre debe tener al menos una letra o un número.';

	/**
	 * The Spanish sentence refusing this name to the record $id (null for a new record), or null when it may have it.
	 *
	 * @param array<int, string> $names every stored record's current name, by id
	 * @param string $taken the sentence for a name another record holds
	 */
	public static function refusal(string $name, ?int $id, array $names, string $taken): ?string {
		$slug = Text::slugFor($name);
		if ($slug === '') {
			return self::NO_ADDRESS;
		}
		foreach ($names as $otherId => $otherName) {
			if ($otherId !== $id && Text::slugFor($otherName) === $slug) {
				return $taken;
			}
		}

		return null;
	}

	/**
	 * The update's refusal: a kept name (same Text::slugFor as the stored one) is never refused. A new name that makes
	 * no address is kept only when it is exactly the stored name, so a record named so before the rule stays editable
	 * and any other rename to such a name is refused.
	 *
	 * @param array<int, string> $names every stored record's current name, by id
	 * @param string $taken the sentence for a name another record holds
	 */
	public static function renameRefusal(string $storedName, string $name, int $id, array $names, string $taken): ?string {
		$newSlug = Text::slugFor($name);
		$renamed = $newSlug === '' ? $name !== $storedName : Text::slugFor($storedName) !== $newSlug;

		return $renamed ? self::refusal($name, $id, $names, $taken) : null;
	}

	/**
	 * The address a new record with this name gets: the name's own, or the first of -2, -3… no record holds.
	 *
	 * @param list<string> $held every stored record's address
	 */
	public static function freeAddress(string $name, array $held): string {
		$address = $base = Text::slugFor($name);
		for ($n = 2; in_array($address, $held, true); $n++) {
			$address = "$base-$n";
		}

		return $address;
	}
}
