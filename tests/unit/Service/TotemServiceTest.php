<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Tests\Unit\Service;

use OCA\Totem\Db\ServicioLinks;
use OCA\Totem\Db\ServicioMapper;
use OCA\Totem\Db\Totem;
use OCA\Totem\Db\TotemMapper;
use OCA\Totem\Exception\Refused;
use OCA\Totem\Service\TicketPrinting;
use OCA\Totem\Service\TotemService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class TotemServiceTest extends TestCase {
	private TotemMapper&MockObject $totems;
	private ServicioLinks&MockObject $links;
	private ServicioMapper&MockObject $servicios;
	private TicketPrinting&MockObject $printing;
	private TotemService $service;

	protected function setUp(): void {
		$this->totems = $this->createMock(TotemMapper::class);
		$this->totems->method('findBySlug')->willThrowException(new DoesNotExistException('free'));
		$this->links = $this->createMock(ServicioLinks::class);
		$this->servicios = $this->createMock(ServicioMapper::class);
		$this->printing = $this->createMock(TicketPrinting::class);
		$this->service = new TotemService($this->totems, $this->links, $this->servicios, $this->printing);
	}

	private function stored(int $id = 4, ?string $host = '192.168.1.50', int $port = 9100): Totem {
		$totem = new Totem();
		$totem->setId($id);
		$totem->setSlug('sala');
		$totem->setName('Sala');
		$totem->setPrinterHost($host);
		$totem->setPrinterPort($port);
		$totem->setPrinterOk(true);
		$totem->setPrinterCheckedAt(1_792_400_000);

		return $totem;
	}

	private function named(int $id, string $name, string $slug): Totem {
		$totem = new Totem();
		$totem->setId($id);
		$totem->setName($name);
		$totem->setSlug($slug);

		return $totem;
	}

	/**
	 * A mapper holding these totems: findAll lists them, findBySlug answers from them, and insert numbers a new
	 * totem and keeps it.
	 */
	private function holding(Totem ...$stored): TotemMapper&MockObject {
		$totems = $this->createMock(TotemMapper::class);
		$totems->method('findAll')->willReturnCallback(static function () use (&$stored): array {
			return $stored;
		});
		$totems->method('findBySlug')->willReturnCallback(static function (string $slug) use (&$stored): Totem {
			foreach ($stored as $totem) {
				if ($totem->getSlug() === $slug) {
					return $totem;
				}
			}
			throw new DoesNotExistException($slug);
		});
		$totems->method('insert')->willReturnCallback(static function (Totem $totem) use (&$stored): Totem {
			$totem->setId(100 + count($stored));
			$stored[] = $totem;

			return $totem;
		});

		return $totems;
	}

	private function serviceOver(TotemMapper $totems): TotemService {
		return new TotemService($totems, $this->links, $this->servicios, $this->printing);
	}

	/** @return array<string, array{list<array{int, string, string}>, string}> */
	public static function renamedHolders(): array {
		return [
			'its address is held' => [[[5, 'Entrada', 'hall']], 'hall-2'],
			'its first derived address is held too' => [[[5, 'Entrada', 'hall'], [6, 'Recepción', 'hall-2']], 'hall-3'],
		];
	}

	/** @param list<array{int, string, string}> $stored */
	#[DataProvider('renamedHolders')]
	public function testANewTotemWhoseNameARenamedTotemNoLongerUsesGetsTheNextFreeAddress(array $stored, string $slug): void {
		$totems = $this->holding(...array_map(fn (array $t): Totem => $this->named(...$t), $stored));

		$this->assertSame($slug, $this->serviceOver($totems)->create(['name' => 'Hall'])['slug']);
	}

	public function testItCreatesATotemWithASlugItsPrinterAndItsUnidades(): void {
		$this->totems->expects($this->once())->method('insert')->with($this->callback(
			static fn (Totem $t) => $t->getName() === 'Entrada principal' && $t->getSlug() === 'entrada_principal'
				&& $t->getPrinterHost() === '192.168.1.50' && $t->getPrinterPort() === 9101,
		))->willReturnCallback(static function (Totem $t): Totem {
			$t->setId(4);

			return $t;
		});
		$this->links->expects($this->once())->method('replace')->with(ServicioLinks::TOTEM, 4, [3]);
		$this->links->method('ids')->willReturn([3]);

		$this->assertSame(
			['id' => 4, 'slug' => 'entrada_principal', 'name' => 'Entrada principal', 'servicioIds' => [3], 'printerHost' => '192.168.1.50', 'printerPort' => 9101, 'printerOk' => null, 'printerCheckedAt' => null],
			$this->service->create(['name' => 'Entrada principal', 'servicioIds' => [3], 'printerHost' => '192.168.1.50', 'printerPort' => 9101]),
		);
	}

	public function testADerivedAddressNeverTakesAnotherNamesOwnAddress(): void {
		$service = $this->serviceOver($this->holding($this->named(5, 'Entrada', 'hall')));

		$this->assertSame(
			['hall-2', 'hall_2'],
			[$service->create(['name' => 'Hall'])['slug'], $service->create(['name' => 'Hall 2'])['slug']],
		);
	}

	public function testATotemWithoutAPrinterPrintsThroughTheBrowser(): void {
		$this->totems->method('insert')->willReturnArgument(0);

		$created = $this->service->create(['name' => 'Entrada', 'servicioIds' => [], 'printerHost' => '']);

		$this->assertNull($created['printerHost']);
		$this->assertSame(9100, $created['printerPort']);
	}

	public function testNoUnidadesMeansItOffersAll(): void {
		$this->totems->method('insert')->willReturnArgument(0);
		$this->links->expects($this->once())->method('replace')->with(ServicioLinks::TOTEM, $this->anything(), []);

		$this->service->create(['name' => 'Entrada', 'servicioIds' => []]);
	}

	public function testATotemWithTheSameNameIsRefused(): void {
		$totems = $this->holding($this->named(5, 'Entrada', 'entrada'));
		$totems->expects($this->never())->method('insert');

		$this->expectException(Refused::class);
		$this->expectExceptionMessage('Ya existe un tótem con ese nombre.');
		$this->serviceOver($totems)->create(['name' => 'Entrada']);
	}

	public function testANewTotemCannotTakeARenamedTotemsCurrentName(): void {
		$totems = $this->holding($this->named(5, 'Entrada', 'hall'));
		$totems->expects($this->never())->method('insert');

		$this->expectException(Refused::class);
		$this->expectExceptionMessage('Ya existe un tótem con ese nombre.');
		$this->serviceOver($totems)->create(['name' => 'entrada']);
	}

	public function testAPrinterOutsideTheClinicNetworkRefusesBeforeAnythingIsStored(): void {
		$this->totems->expects($this->never())->method('insert');

		$this->expectException(Refused::class);
		$this->expectExceptionMessage('La impresora debe estar en la red de la clínica');
		$this->service->create(['name' => 'Entrada', 'printerHost' => '8.8.8.8']);
	}

	public function testALinkedUnidadThatNoLongerExistsRefuses(): void {
		$this->servicios->method('find')->willThrowException(new DoesNotExistException('x'));

		$this->expectException(Refused::class);
		$this->expectExceptionMessage('Alguna unidad elegida ya no existe.');
		$this->service->create(['name' => 'Entrada', 'servicioIds' => [99]]);
	}

	public function testUpdateKeepsTheSlugAndForgetsTheLastResultWhenThePrinterChanges(): void {
		$this->totems->method('find')->with(4)->willReturn($this->stored());
		$this->totems->expects($this->once())->method('update')->willReturnArgument(0);
		$this->links->method('ids')->willReturn([]);

		$updated = $this->service->update(4, ['name' => 'Sala', 'servicioIds' => [], 'printerHost' => '192.168.1.60', 'printerPort' => 9100]);

		$this->assertSame('sala', $updated['slug']);
		$this->assertSame('192.168.1.60', $updated['printerHost']);
		$this->assertNull($updated['printerOk']);
		$this->assertNull($updated['printerCheckedAt']);
	}

	public function testUpdateKeepsTheLastResultWhenThePrinterIsTheSame(): void {
		$this->totems->method('find')->willReturn($this->stored());
		$this->totems->method('update')->willReturnArgument(0);
		$this->links->method('ids')->willReturn([]);

		$updated = $this->service->update(4, ['name' => 'Sala nueva', 'servicioIds' => [], 'printerHost' => '192.168.1.50', 'printerPort' => 9100]);

		$this->assertTrue($updated['printerOk']);
		$this->assertSame(1_792_400_000, $updated['printerCheckedAt']);
	}

	/** @return array<string, array{string}> */
	public static function anotherTotemsName(): array {
		return ['as written' => ['Entrada'], 'in capitals' => ['ENTRADA'], 'with an accent' => ['Éntrada'], 'with punctuation' => ['entrada!']];
	}

	#[DataProvider('anotherTotemsName')]
	public function testRenamingATotemToAnotherTotemsNameIsRefusedAndNothingIsStored(string $name): void {
		$totems = $this->holding($this->named(4, 'Sala', 'sala'), $this->named(5, 'Entrada', 'entrada'));
		$totems->method('find')->with(4)->willReturn($this->named(4, 'Sala', 'sala'));
		$totems->expects($this->never())->method('update');
		$this->links->expects($this->never())->method('replace');

		$this->expectException(Refused::class);
		$this->expectExceptionMessage('Ya existe un tótem con ese nombre.');
		$this->serviceOver($totems)->update(4, ['name' => $name, 'servicioIds' => []]);
	}

	public function testRenamingATotemToItsOwnNameOrACaseVariantKeepsItsSlug(): void {
		$totems = $this->holding($this->named(4, 'Sala', 'sala'));
		$totems->method('find')->with(4)->willReturn($this->named(4, 'Sala', 'sala'));
		$totems->expects($this->once())->method('update')->with($this->callback(
			static fn (Totem $t) => $t->getName() === 'SALA' && $t->getSlug() === 'sala',
		))->willReturnArgument(0);

		$this->assertSame('sala', $this->serviceOver($totems)->update(4, ['name' => 'SALA', 'servicioIds' => []])['slug']);
	}

	public function testRenamingATotemToANameWhoseAddressAnotherTotemHoldsKeepsItsSlug(): void {
		$totems = $this->holding($this->named(4, 'Sala', 'sala'), $this->named(5, 'Entrada', 'hall'));
		$totems->method('find')->with(4)->willReturn($this->named(4, 'Sala', 'sala'));
		$totems->expects($this->once())->method('update')->with($this->callback(
			static fn (Totem $t) => $t->getName() === 'Hall' && $t->getSlug() === 'sala',
		))->willReturnArgument(0);

		$this->assertSame('sala', $this->serviceOver($totems)->update(4, ['name' => 'Hall', 'servicioIds' => []])['slug']);
	}

	public function testItDeletesATotemWithItsLinks(): void {
		$totem = $this->stored();
		$this->totems->method('find')->with(4)->willReturn($totem);
		$this->totems->expects($this->once())->method('delete')->with($totem);
		$this->links->expects($this->once())->method('replace')->with(ServicioLinks::TOTEM, 4, []);

		$this->service->delete(4);
	}

	public function testItListsTotemsPresented(): void {
		$this->totems->method('findAll')->willReturn([$this->stored()]);
		$this->links->method('ids')->willReturn([3]);

		$this->assertSame('Sala', $this->service->list()[0]['name']);
		$this->assertSame([3], $this->service->list()[0]['servicioIds']);
	}

	public function testATestTicketReportsWhetherThePrinterAnsweredAndTheStatusItLeft(): void {
		$totem = $this->stored();
		$this->totems->method('find')->with(4)->willReturn($totem);
		$this->printing->expects($this->once())->method('printTest')->with($totem)->willReturnCallback(static function (Totem $t): bool {
			$t->setPrinterOk(false);

			return false;
		});
		$this->links->method('ids')->willReturn([]);

		$answer = $this->service->testPrint(4);

		$this->assertFalse($answer['printed']);
		$this->assertFalse($answer['totem']['printerOk']);
	}

	public function testATestTicketNeedsAPrinter(): void {
		$this->totems->method('find')->willReturn($this->stored(host: null));
		$this->printing->expects($this->never())->method('printTest');

		$this->expectException(Refused::class);
		$this->expectExceptionMessage('Este tótem no tiene impresora de red. Escriba su dirección y guarde.');
		$this->service->testPrint(4);
	}

	public function testATestTicketForAnUnknownTotemIsNotFound(): void {
		$this->totems->method('find')->willThrowException(new DoesNotExistException('x'));

		$this->expectException(DoesNotExistException::class);
		$this->service->testPrint(99);
	}

	public function testItSaysWhichUnidadesATotemOffersByItsAddress(): void {
		$totems = $this->createMock(TotemMapper::class);
		$totems->method('findBySlug')->with('sala')->willReturn($this->stored());
		$this->links->method('ids')->with(ServicioLinks::TOTEM, 4)->willReturn([3, 5]);

		$this->assertSame([3, 5], (new TotemService($totems, $this->links, $this->servicios, $this->printing))->offeredBy('sala'));
	}

	public function testAnUnknownTotemAddressIsNotFound(): void {
		$this->expectException(DoesNotExistException::class);
		$this->service->offeredBy('nadie');
	}
}
