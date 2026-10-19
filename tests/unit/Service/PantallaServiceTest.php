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
		$pantallas = $this->createMock(PantallaMapper::class);
		$pantallas->method('findBySlug')->willReturn(new Pantalla());

		$this->expectException(Refused::class);
		$this->expectExceptionMessage('Ya existe una pantalla con ese nombre.');
		(new PantallaService($pantallas, $this->links, $this->servicios))->create(['name' => 'Sala', 'volume' => 100, 'voice' => 'mujer']);
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

	public function testItDeletesAPantallaWithItsLinks(): void {
		$p = new Pantalla();
		$p->setId(2);
		$this->pantallas->method('find')->willReturn($p);
		$this->pantallas->expects($this->once())->method('delete')->with($p);
		$this->links->expects($this->once())->method('replace')->with(ServicioLinks::PANTALLA, 2, []);

		$this->service->delete(2);
	}
}
