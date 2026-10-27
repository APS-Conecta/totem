<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Tests\Unit\Service;

use OCA\Totem\Db\Pantalla;
use OCA\Totem\Db\PantallaMapper;
use OCA\Totem\Db\ServicioLinks;
use OCA\Totem\Db\ServicioMapper;
use OCA\Totem\Exception\Refused;
use OCA\Totem\Service\PantallaService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PantallaServiceTest extends TestCase {
	private PantallaMapper&MockObject $pantallas;
	private ServicioLinks&MockObject $links;
	private ServicioMapper&MockObject $servicios;
	private PantallaService $service;

	protected function setUp(): void {
		$this->pantallas = $this->createMock(PantallaMapper::class);
		$this->pantallas->method('findBySlug')->willThrowException(new DoesNotExistException('free'));
		$this->links = $this->createMock(ServicioLinks::class);
		$this->servicios = $this->createMock(ServicioMapper::class);
		$this->service = new PantallaService($this->pantallas, $this->links, $this->servicios);
	}

	private function named(int $id, string $name, string $slug): Pantalla {
		$pantalla = new Pantalla();
		$pantalla->setId($id);
		$pantalla->setName($name);
		$pantalla->setSlug($slug);

		return $pantalla;
	}

	/**
	 * A mapper holding these pantallas: findAll lists them, findBySlug answers from them, and insert numbers a new
	 * pantalla and keeps it.
	 */
	private function holding(Pantalla ...$stored): PantallaMapper&MockObject {
		$pantallas = $this->createMock(PantallaMapper::class);
		$pantallas->method('findAll')->willReturnCallback(static function () use (&$stored): array {
			return $stored;
		});
		$pantallas->method('findBySlug')->willReturnCallback(static function (string $slug) use (&$stored): Pantalla {
			foreach ($stored as $pantalla) {
				if ($pantalla->getSlug() === $slug) {
					return $pantalla;
				}
			}
			throw new DoesNotExistException($slug);
		});
		$pantallas->method('insert')->willReturnCallback(static function (Pantalla $pantalla) use (&$stored): Pantalla {
			$pantalla->setId(100 + count($stored));
			$stored[] = $pantalla;

			return $pantalla;
		});

		return $pantallas;
	}

	private function serviceOver(PantallaMapper $pantallas): PantallaService {
		return new PantallaService($pantallas, $this->links, $this->servicios);
	}

	/** @return array<string, array{list<array{int, string, string}>, string}> */
	public static function renamedHolders(): array {
		return [
			'its address is held' => [[[1, 'Sala', 'hall']], 'hall-2'],
			'its first derived address is held too' => [[[1, 'Sala', 'hall'], [3, 'Farmacia', 'hall-2']], 'hall-3'],
		];
	}

	/** @param list<array{int, string, string}> $stored */
	#[DataProvider('renamedHolders')]
	public function testANewPantallaWhoseNameARenamedPantallaNoLongerUsesGetsTheNextFreeAddress(array $stored, string $slug): void {
		$pantallas = $this->holding(...array_map(fn (array $p): Pantalla => $this->named(...$p), $stored));

		$this->assertSame($slug, $this->serviceOver($pantallas)->create(['name' => 'Hall'])['slug']);
	}

	public function testADerivedAddressNeverTakesAnotherNamesOwnAddress(): void {
		$service = $this->serviceOver($this->holding($this->named(1, 'Sala', 'hall')));

		$this->assertSame(
			['hall-2', 'hall_2'],
			[$service->create(['name' => 'Hall'])['slug'], $service->create(['name' => 'Hall 2'])['slug']],
		);
	}

	public function testItCreatesAPantallaWithASlugItsSoundAndItsServicios(): void {
		$this->pantallas->expects($this->once())->method('insert')->with($this->callback(
			static fn (Pantalla $p) => $p->getName() === 'Sala Farmacia' && $p->getSlug() === 'sala_farmacia' && $p->getVolume() === 60 && $p->getVoice() === 'hombre',
		))->willReturnCallback(static function (Pantalla $p): Pantalla {
			$p->setId(2);

			return $p;
		});
		$this->links->expects($this->once())->method('replace')->with(ServicioLinks::PANTALLA, 2, [3]);
		$this->links->method('ids')->willReturn([3]);

		$this->assertSame(
			['id' => 2, 'slug' => 'sala_farmacia', 'name' => 'Sala Farmacia', 'volume' => 60, 'voice' => 'hombre', 'servicioIds' => [3]],
			$this->service->create(['name' => 'Sala Farmacia', 'volume' => 60, 'voice' => 'hombre', 'servicioIds' => [3]]),
		);
	}

	public function testNoServiciosMeansItShowsAllAndIsAllowed(): void {
		$this->pantallas->method('insert')->willReturnArgument(0);
		$this->links->expects($this->once())->method('replace')->with(ServicioLinks::PANTALLA, $this->anything(), []);

		$this->service->create(['name' => 'Central', 'volume' => 100, 'voice' => 'mujer', 'servicioIds' => []]);
	}

	public function testAPantallaCreatedWithoutAVoiceSpeaksWithMujer(): void {
		$this->pantallas->method('insert')->willReturnArgument(0);

		$created = $this->service->create(['name' => 'Central', 'volume' => 100, 'servicioIds' => []]);

		$this->assertSame('mujer', $created['voice']);
	}

	public function testAPantallaWithTheSameNameIsRefused(): void {
		$pantallas = $this->holding($this->named(1, 'Sala', 'sala'));
		$pantallas->expects($this->never())->method('insert');

		$this->expectException(Refused::class);
		$this->expectExceptionMessage('Ya existe una pantalla con ese nombre.');
		$this->serviceOver($pantallas)->create(['name' => 'Sala', 'volume' => 100, 'voice' => 'mujer']);
	}

	public function testANewPantallaCannotTakeARenamedPantallasCurrentName(): void {
		$pantallas = $this->holding($this->named(1, 'Sala', 'hall'));
		$pantallas->expects($this->never())->method('insert');

		$this->expectException(Refused::class);
		$this->expectExceptionMessage('Ya existe una pantalla con ese nombre.');
		$this->serviceOver($pantallas)->create(['name' => 'sala', 'volume' => 100, 'voice' => 'mujer']);
	}

	public function testABadVoiceOrVolumeRefusesBeforeStoring(): void {
		$this->pantallas->expects($this->never())->method('insert');

		$this->expectException(Refused::class);
		$this->expectExceptionMessage('El volumen debe estar entre 0 y 100.');
		$this->service->create(['name' => 'Sala', 'volume' => 300, 'voice' => 'mujer']);
	}

	public function testALinkedServicioThatNoLongerExistsRefuses(): void {
		$this->servicios->method('find')->willThrowException(new DoesNotExistException('x'));

		$this->expectException(Refused::class);
		$this->expectExceptionMessage('Alguna unidad elegida ya no existe.');
		$this->service->create(['name' => 'Sala', 'volume' => 100, 'voice' => 'mujer', 'servicioIds' => [99]]);
	}

	public function testUpdateChangesTheSoundAndKeepsTheSlugAndName(): void {
		$p = new Pantalla();
		$p->setId(2);
		$p->setSlug('sala');
		$p->setName('Sala');
		$p->setVolume(100);
		$p->setVoice('mujer');
		$this->pantallas->method('find')->with(2)->willReturn($p);
		$this->pantallas->expects($this->once())->method('update')->willReturnArgument(0);
		$this->links->method('ids')->willReturn([]);

		$updated = $this->service->update(2, ['name' => 'Sala', 'volume' => 30, 'voice' => 'off', 'servicioIds' => []]);

		$this->assertSame(30, $updated['volume']);
		$this->assertSame('off', $updated['voice']);
		$this->assertSame('sala', $updated['slug']);
	}

	/** @return array<string, array{string}> */
	public static function anotherPantallasName(): array {
		return ['as written' => ['Hall'], 'in capitals' => ['HALL'], 'with an accent' => ['Háll'], 'with punctuation' => ['hall!']];
	}

	#[DataProvider('anotherPantallasName')]
	public function testRenamingAPantallaToAnotherPantallasNameIsRefusedAndNothingIsStored(string $name): void {
		$pantallas = $this->holding($this->named(2, 'Sala', 'sala'), $this->named(5, 'Hall', 'hall'));
		$pantallas->method('find')->with(2)->willReturn($this->named(2, 'Sala', 'sala'));
		$pantallas->expects($this->never())->method('update');
		$this->links->expects($this->never())->method('replace');

		$this->expectException(Refused::class);
		$this->expectExceptionMessage('Ya existe una pantalla con ese nombre.');
		$this->serviceOver($pantallas)->update(2, ['name' => $name, 'volume' => 100, 'voice' => 'mujer', 'servicioIds' => []]);
	}

	public function testRenamingAPantallaToItsOwnNameOrACaseVariantKeepsItsSlug(): void {
		$pantallas = $this->holding($this->named(2, 'Sala', 'sala'));
		$pantallas->method('find')->with(2)->willReturn($this->named(2, 'Sala', 'sala'));
		$pantallas->expects($this->once())->method('update')->with($this->callback(
			static fn (Pantalla $p) => $p->getName() === 'SALA' && $p->getSlug() === 'sala',
		))->willReturnArgument(0);

		$this->assertSame('sala', $this->serviceOver($pantallas)->update(2, ['name' => 'SALA', 'volume' => 100, 'voice' => 'mujer', 'servicioIds' => []])['slug']);
	}

	public function testRenamingAPantallaToANameWhoseAddressAnotherPantallaHoldsKeepsItsSlug(): void {
		$pantallas = $this->holding($this->named(2, 'Sala', 'sala'), $this->named(5, 'Farmacia', 'hall'));
		$pantallas->method('find')->with(2)->willReturn($this->named(2, 'Sala', 'sala'));
		$pantallas->expects($this->once())->method('update')->with($this->callback(
			static fn (Pantalla $p) => $p->getName() === 'Hall' && $p->getSlug() === 'sala',
		))->willReturnArgument(0);

		$this->assertSame('sala', $this->serviceOver($pantallas)->update(2, ['name' => 'Hall', 'volume' => 100, 'voice' => 'mujer', 'servicioIds' => []])['slug']);
	}

	public function testItDeletesAPantallaWithItsLinks(): void {
		$p = new Pantalla();
		$p->setId(2);
		$this->pantallas->method('find')->willReturn($p);
		$this->pantallas->expects($this->once())->method('delete')->with($p);
		$this->links->expects($this->once())->method('replace')->with(ServicioLinks::PANTALLA, 2, []);

		$this->service->delete(2);
	}
}
