<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Tests\Unit\Domain;

use OCA\Totem\Domain\Estado;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EstadoTest extends TestCase {
	/** @return array<string, array{Estado, Estado, bool}> */
	public static function transitions(): array {
		return [
			'a waiting turno is called' => [Estado::Waiting, Estado::Called, true],
			'a called turno starts service' => [Estado::Called, Estado::InService, true],
			'a called turno that never shows is a no-show' => [Estado::Called, Estado::NoShow, true],
			'a turno in service completes' => [Estado::InService, Estado::Completed, true],
			'a waiting turno cannot skip the call' => [Estado::Waiting, Estado::InService, false],
			'a waiting turno cannot be a no-show' => [Estado::Waiting, Estado::NoShow, false],
			'a called turno cannot complete without service' => [Estado::Called, Estado::Completed, false],
			'a turno in service cannot go back to called' => [Estado::InService, Estado::Called, false],
			'a completed turno is final' => [Estado::Completed, Estado::Waiting, false],
			'a no-show is final' => [Estado::NoShow, Estado::Called, false],
		];
	}

	#[DataProvider('transitions')]
	public function testTransitionRules(Estado $from, Estado $to, bool $allowed): void {
		$this->assertSame($allowed, $from->canBecome($to));
	}

	public function testIsStoredAsItsLowercaseName(): void {
		$this->assertSame('in_service', Estado::InService->value);
		$this->assertSame(Estado::NoShow, Estado::from('no_show'));
	}
}
