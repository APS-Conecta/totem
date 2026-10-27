<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Tests\Unit\Domain;

use OCA\Totem\Domain\SpokenPlace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SpokenPlaceTest extends TestCase {
	/** @return array<string, array{string, string}> */
	public static function numbered(): array {
		return [
			'a box' => ['Box 3', 'ventanilla 3'],
			'a ventanilla' => ['Ventanilla 12', 'ventanilla 12'],
			'no space before the number' => ['Box3', 'ventanilla 3'],
			'one number inside the name' => ['Ventanilla 2 Norte', 'ventanilla 2'],
			'lowercase' => ['caja 7', 'ventanilla 7'],
			'only the number' => ['4', 'ventanilla 4'],
		];
	}

	#[DataProvider('numbered')]
	public function testItSaysVentanillaAndTheNumberTheNameCarries(string $name, string $spoken): void {
		$this->assertSame($spoken, SpokenPlace::of($name, [$name, 'Caja principal']));
	}

	public function testANameWithTwoNumbersIsSaidAsItIs(): void {
		$this->assertSame('Sector 2 Box 1', SpokenPlace::of('Sector 2 Box 1', ['Sector 2 Box 1', 'Caja principal']));
	}

	/** @return array<string, array{string, list<string>}> */
	public static function sharedNumber(): array {
		return [
			'the same number, north' => ['Ventanilla 2 Norte', ['Ventanilla 2 Norte', 'Ventanilla 2 Sur']],
			'the same number, south' => ['Ventanilla 2 Sur', ['Ventanilla 2 Norte', 'Ventanilla 2 Sur']],
			'the same number, different words' => ['Box 2', ['Box 2', 'Ventanilla 2']],
			'a leading zero, zero first' => ['Box 02', ['Box 02', 'Box 2']],
			'a leading zero, zero second' => ['Box 2', ['Box 02', 'Box 2']],
			'its number inside a two-number name' => ['Box 2', ['Box 2', 'Sector 2 Box 1']],
			'its number second in a two-number name' => ['Box 1', ['Box 1', 'Sector 2 Box 1']],
		];
	}

	/** @param list<string> $boxNames */
	#[DataProvider('sharedNumber')]
	public function testANumberAnotherBoxsNameHoldsIsNotSpoken(string $name, array $boxNames): void {
		$this->assertSame($name, SpokenPlace::of($name, $boxNames));
	}

	public function testNumbersAreComparedAsNumbersNotAsText(): void {
		$this->assertSame('ventanilla 2', SpokenPlace::of('Box 2', ['Box 2', 'Box 12']));
	}

	public function testOnlyTheBoxesThatShareANumberAreSaidInFull(): void {
		$this->assertSame('ventanilla 3', SpokenPlace::of('Box 3', ['Box 2', 'Box 3', 'Ventanilla 2 Norte', 'Ventanilla 2 Sur']));
	}

	public function testANameWithoutANumberIsSaidAsItIs(): void {
		$this->assertSame('Caja principal', SpokenPlace::of('Caja principal', ['Caja principal', 'Box 2']));
		$this->assertSame('Ventanilla', SpokenPlace::of('Ventanilla', ['Box 2', 'Box 3']), 'a deleted box is not among the names');
	}

	/** @return array<string, array{string}> */
	public static function names(): array {
		return [['Box 1'], ['Ventanilla 1'], ['Caja principal'], ['Ventanilla'], ['Sector 2 Box 1']];
	}

	#[DataProvider('names')]
	public function testWhenThereIsOnlyOneVentanillaNoNumberIsNeeded(string $name): void {
		$this->assertSame('ventanilla', SpokenPlace::of($name, [$name]));
	}
}
