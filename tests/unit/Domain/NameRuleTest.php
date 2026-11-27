<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Tests\Unit\Domain;

use OCA\Totem\Domain\NameRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NameRuleTest extends TestCase {
	private const TAKEN = 'Ya existe un tótem con ese nombre.';
	private const NO_ADDRESS = 'El nombre debe tener al menos una letra o un número.';

	/** @return array<string, array{string}> */
	public static function anotherRecordsName(): array {
		return ['as written' => ['Entrada'], 'in capitals' => ['ENTRADA'], 'with an accent' => ['Éntrada'], 'with punctuation' => ['entrada!']];
	}

	#[DataProvider('anotherRecordsName')]
	public function testANameAnotherRecordHoldsIsTaken(string $name): void {
		$this->assertSame(self::TAKEN, NameRule::refusal($name, null, [4 => 'Sala', 5 => 'Entrada'], self::TAKEN));
	}

	public function testTheRecordsOwnNameNeverCountsAgainstIt(): void {
		$this->assertNull(NameRule::refusal('Entrada', 5, [4 => 'Sala', 5 => 'Entrada'], self::TAKEN));
	}

	public function testARenamedRecordsCurrentNameIsTakenAndItsOldNameIsFree(): void {
		// Tótem 5 was created as Hall (address hall) and renamed to Entrada.
		$names = [5 => 'Entrada'];

		$this->assertSame(
			[self::TAKEN, null],
			[NameRule::refusal('entrada', null, $names, self::TAKEN), NameRule::refusal('Hall', null, $names, self::TAKEN)],
		);
	}

	/** @return array<string, array{string}> */
	public static function noAddress(): array {
		return ['only punctuation' => ['!!!'], 'not Latin' => ['Сала'], 'an emoji' => ['🚪']];
	}

	#[DataProvider('noAddress')]
	public function testANameThatMakesNoAddressIsRefusedBesideSuchANameToo(string $name): void {
		$this->assertSame(self::NO_ADDRESS, NameRule::refusal($name, null, [4 => 'Hall', 5 => '???'], self::TAKEN));
	}

	public function testAnAccentedSpanishNameMakesItsAddress(): void {
		$this->assertSame(['recepcion', null], [NameRule::freeAddress('Recepción', []), NameRule::refusal('Recepción', null, [], self::TAKEN)]);
	}

	/** @return array<string, array{string}> */
	public static function keptName(): array {
		return ['its own name' => ['Entrada'], 'a case variant' => ['ENTRADA']];
	}

	#[DataProvider('keptName')]
	public function testARecordKeepingItsNameIsNeverRefusedEvenWhenAnotherSharesIt(string $name): void {
		// Tótems 4 and 5 shared the name before the rule existed.
		$this->assertNull(NameRule::renameRefusal('Entrada', $name, 4, [4 => 'Entrada', 5 => 'Entrada'], self::TAKEN));
	}

	public function testARenameToAnotherRecordsNameIsTaken(): void {
		$this->assertSame(self::TAKEN, NameRule::renameRefusal('Sala', 'éntrada', 4, [4 => 'Sala', 5 => 'Entrada'], self::TAKEN));
	}

	public function testARenameToAFreeNameIsAllowed(): void {
		$this->assertNull(NameRule::renameRefusal('Sala', 'Hall', 4, [4 => 'Sala', 5 => 'Entrada'], self::TAKEN));
	}

	/** @return array<string, array{string, string, ?string}> */
	public static function renamesWithoutAddress(): array {
		return [
			'to such a name' => ['Sala', '!!!', self::NO_ADDRESS],
			'from such a name to another' => ['!!!', '???', self::NO_ADDRESS],
			'keeping such a name exactly' => ['!!!', '!!!', null],
		];
	}

	#[DataProvider('renamesWithoutAddress')]
	public function testARenameToANameThatMakesNoAddressIsRefusedUnlessTheNameIsKeptExactly(string $stored, string $name, ?string $refusal): void {
		$this->assertSame($refusal, NameRule::renameRefusal($stored, $name, 4, [4 => $stored, 5 => '!!!'], self::TAKEN));
	}

	/** @return array<string, array{list<string>, string}> */
	public static function heldAddresses(): array {
		return [
			'nothing holds it' => [['sala'], 'hall'],
			'its address is held' => [['hall'], 'hall-2'],
			'its first derived address is held too' => [['hall', 'hall-2'], 'hall-3'],
		];
	}

	/** @param list<string> $held */
	#[DataProvider('heldAddresses')]
	public function testTheFreeAddressIsTheNamesOwnOrTheNextNumberedOne(array $held, string $address): void {
		$this->assertSame($address, NameRule::freeAddress('Hall', $held));
	}

	public function testADerivedAddressNeverTakesAnotherNamesOwnAddress(): void {
		$this->assertSame(['hall-2', 'hall_2'], [NameRule::freeAddress('Hall', ['hall']), NameRule::freeAddress('Hall 2', ['hall'])]);
	}
}
