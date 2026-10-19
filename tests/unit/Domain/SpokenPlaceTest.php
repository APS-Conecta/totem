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
			'a number inside the name' => ['Ventanilla 2 Norte', 'ventanilla 2'],
			'lowercase' => ['caja 7', 'ventanilla 7'],
			'only the number' => ['4', 'ventanilla 4'],
		];
	}

	#[DataProvider('numbered')]
	public function testItSaysVentanillaAndTheNumberTheNameCarries(string $name, string $spoken): void {
		$this->assertSame($spoken, SpokenPlace::of($name, false));
	}

	public function testANameWithoutANumberIsSaidAsItIs(): void {
		$this->assertSame('Caja principal', SpokenPlace::of('Caja principal', false));
		$this->assertSame('Ventanilla', SpokenPlace::of('Ventanilla', false));
	}

	/** @return array<string, array{string}> */
	public static function names(): array {
		return [['Box 1'], ['Ventanilla 1'], ['Caja principal'], ['Ventanilla']];
	}

	#[DataProvider('names')]
	public function testWhenThereIsOnlyOneVentanillaNoNumberIsNeeded(string $name): void {
		$this->assertSame('ventanilla', SpokenPlace::of($name, true));
	}
}
