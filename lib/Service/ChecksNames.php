<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Service;

use APS\Common\Text;
use OCA\Totem\Db\Pantalla;
use OCA\Totem\Db\Totem;
use OCA\Totem\Exception\Refused;

/**
 * The name rule tótems and pantallas share. A name is taken when another record's current name gives the same
 * Text::slugFor (accents, case, spacing and punctuation ignored); the record itself never counts. The address is
 * made from the name at creation, with -2, -3… when a renamed record still holds it, and never changes afterwards.
 * slugFor never makes "-", so a derived address is never another name's own address.
 */
trait ChecksNames {
	/** @param list<Totem|Pantalla> $all */
	private function assertNameFree(Totem|Pantalla $record, array $all, string $sentence): void {
		$slug = Text::slugFor($record->getName());
		foreach ($all as $other) {
			if ($other->getId() !== $record->getId() && Text::slugFor($other->getName()) === $slug) {
				throw new Refused($sentence);
			}
		}
	}

	/** @param list<Totem|Pantalla> $all */
	private function freeAddress(string $name, array $all): string {
		$taken = array_map(static fn (Totem|Pantalla $r): string => $r->getSlug(), $all);
		$slug = $base = Text::slugFor($name);
		for ($n = 2; in_array($slug, $taken, true); $n++) {
			$slug = "$base-$n";
		}

		return $slug;
	}
}
