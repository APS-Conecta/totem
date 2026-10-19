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
		$totems = $this->createMock(TotemMapper::class);
		$totems->method('findBySlug')->willReturn(new Totem());

		$this->expectException(Refused::class);
		$this->expectExceptionMessage('Ya existe un tótem con ese nombre.');
		(new TotemService($totems, $this->links, $this->servicios, $this->printing))->create(['name' => 'Entrada']);
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
}
