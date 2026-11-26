<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Service;

use APS\Common\Text;
use OCA\Totem\Db\Box;
use OCA\Totem\Db\Pantalla;
use OCA\Totem\Db\PantallaMapper;
use OCA\Totem\Db\Totem;
use OCA\Totem\Db\TotemMapper;
use OCA\Totem\Exception\Refused;
use OCP\DB\Exception as DbException;

/**
 * The name rule tótems, pantallas and boxes share. A name needs an ASCII letter or digit once accents are dropped:
 * otherwise Text::slugFor makes nothing, which is no address and would compare equal to every other such name (#39).
 * A name is taken when another record's current name gives the same Text::slugFor (accents, case, spacing and
 * punctuation ignored); the record itself never counts. An update checks the name only when it changes: records
 * that shared a name before the rule existed stay editable, and so does a record that keeps its exact name when that
 * name makes no address. A tótem's or pantalla's address is made from the name at creation, with -2, -3… when a
 * renamed record still holds it, and never changes afterwards. slugFor never makes "-", so a derived address is never
 * another name's own address.
 *
 * The rule is checked in code, not enforced by the database: nothing makes the read and the write atomic. Two creates
 * of one name that overlap derive the same address, and the unique slug index refuses the later insert, which gets the
 * name sentence (insertNamed). Two renames that overlap, or two box creates (a box has no address), can still store a
 * duplicate name; it shows in the admin list and a rename fixes it (an accepted race, #72: a database backstop would
 * need a name column and index).
 */
trait ChecksNames {
	/** @param list<Totem|Pantalla|Box> $all */
	private function assertNameFree(Totem|Pantalla|Box $record, array $all, string $sentence): void {
		$slug = Text::slugFor($record->getName());
		if ($slug === '') {
			throw new Refused('El nombre debe tener al menos una letra o un número.');
		}
		foreach ($all as $other) {
			if ($other->getId() !== $record->getId() && Text::slugFor($other->getName()) === $slug) {
				throw new Refused($sentence);
			}
		}
	}

	/**
	 * The update's check: a kept name (same Text::slugFor as the stored one) is never refused. A new name that makes
	 * no address is kept only when it is exactly the stored name, so a record named so before the rule stays editable
	 * and any other rename to such a name is refused.
	 *
	 * @param list<Totem|Pantalla|Box> $all
	 */
	private function assertRenameFree(string $storedName, Totem|Pantalla|Box $record, array $all, string $sentence): void {
		$newSlug = Text::slugFor($record->getName());
		$renamed = $newSlug === '' ? $record->getName() !== $storedName : Text::slugFor($storedName) !== $newSlug;
		if ($renamed) {
			$this->assertNameFree($record, $all, $sentence);
		}
	}

	/**
	 * The create's insert: an address another create took since the read is the name's refusal, not a 500. No retry:
	 * saving again re-runs the name check. Any other database error propagates unchanged.
	 *
	 * @throws Refused when the unique slug index refuses the insert
	 */
	private function insertNamed(TotemMapper|PantallaMapper $mapper, Totem|Pantalla $record, string $sentence): Totem|Pantalla {
		try {
			return $mapper->insert($record);
		} catch (DbException $e) {
			if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
			throw new Refused($sentence);
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
