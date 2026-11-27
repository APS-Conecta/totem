<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Service;

use OCA\Totem\Db\Box;
use OCA\Totem\Db\Pantalla;
use OCA\Totem\Db\PantallaMapper;
use OCA\Totem\Db\Totem;
use OCA\Totem\Db\TotemMapper;
use OCA\Totem\Domain\NameRule;
use OCA\Totem\Exception\Refused;
use OCP\DB\Exception as DbException;

/**
 * How the services apply NameRule to Tótems, Pantallas and Boxes: each refusal is a Refused carrying its sentence.
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
		self::refuse(NameRule::refusal($record->getName(), $record->getId(), self::namesById($all), $sentence));
	}

	/** @param list<Totem|Pantalla|Box> $all */
	private function assertRenameFree(string $storedName, Totem|Pantalla|Box $record, array $all, string $sentence): void {
		self::refuse(NameRule::renameRefusal($storedName, $record->getName(), (int)$record->getId(), self::namesById($all), $sentence));
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
		return NameRule::freeAddress($name, array_map(static fn (Totem|Pantalla $r): string => $r->getSlug(), $all));
	}

	/**
	 * @param list<Totem|Pantalla|Box> $all
	 * @return array<int, string>
	 */
	private static function namesById(array $all): array {
		$names = [];
		foreach ($all as $record) {
			$names[(int)$record->getId()] = $record->getName();
		}

		return $names;
	}

	private static function refuse(?string $refusal): void {
		if ($refusal !== null) {
			throw new Refused($refusal);
		}
	}
}
