<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Tests\Unit\Service;

use OCA\Totem\Db\Box;
use OCA\Totem\Db\BoxMapper;
use OCA\Totem\Db\ServicioLinks;
use OCA\Totem\Db\ServicioMapper;
use OCA\Totem\Db\Turno;
use OCA\Totem\Db\TurnoMapper;
use OCA\Totem\Exception\Refused;
use OCA\Totem\Service\BoxService;
use OCA\Totem\Service\Today;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class BoxServiceTest extends TestCase {
	private BoxMapper&MockObject $boxes;
	private ServicioLinks&MockObject $links;
	private ServicioMapper&MockObject $servicios;
	private TurnoMapper&MockObject $turnos;
	private BoxService $service;

	protected function setUp(): void {
		$this->boxes = $this->createMock(BoxMapper::class);
		$this->links = $this->createMock(ServicioLinks::class);
		$this->servicios = $this->createMock(ServicioMapper::class);
		$this->turnos = $this->createMock(TurnoMapper::class);
		$today = $this->createMock(Today::class);
		$today->method('day')->willReturn('2026-10-18');
		$this->service = new BoxService($this->boxes, $this->links, $this->servicios, $this->turnos, $today);
	}

	private function box(int $id, string $name): Box {
		$b = new Box();
		$b->setId($id);
		$b->setName($name);

		return $b;
	}

	public function testItCreatesABoxAndLinksItsServicios(): void {
		$this->boxes->expects($this->once())->method('insert')->with($this->callback(static fn (Box $b) => $b->getName() === 'Box 3'))
			->willReturnCallback(static function (Box $b): Box {
				$b->setId(4);

				return $b;
			});
		$this->links->expects($this->once())->method('replace')->with(ServicioLinks::BOX, 4, [3, 4]);
		$this->links->method('ids')->willReturn([3, 4]);

		$this->assertSame(['id' => 4, 'name' => 'Box 3', 'servicioIds' => [3, 4]], $this->service->create(['name' => ' Box 3 ', 'servicioIds' => [3, 4, 3]]));
	}

	public function testABoxNeedsAtLeastOneServicio(): void {
		$this->boxes->expects($this->never())->method('insert');

		$this->expectException(Refused::class);
		$this->expectExceptionMessage('Elija al menos una unidad para la ventanilla.');
		$this->service->create(['name' => 'Box 3', 'servicioIds' => []]);
	}

	public function testALinkedServicioThatNoLongerExistsRefuses(): void {
		$this->servicios->method('find')->willThrowException(new DoesNotExistException('x'));
		$this->boxes->expects($this->never())->method('insert');

		$this->expectException(Refused::class);
		$this->expectExceptionMessage('Alguna unidad elegida ya no existe.');
		$this->service->create(['name' => 'Box 3', 'servicioIds' => [99]]);
	}

	public function testTheNameIsCappedAtFourteenCharacters(): void {
		$this->expectException(Refused::class);
		$this->expectExceptionMessage('El nombre admite hasta 14 caracteres.');
		$this->service->create(['name' => 'Box de procedimientos', 'servicioIds' => [3]]);
	}

	public function testUpdateRenamesAndRelinks(): void {
		$this->boxes->method('find')->with(4)->willReturn($this->box(4, 'Box 3'));
		$this->boxes->expects($this->once())->method('update')->willReturnArgument(0);
		$this->links->expects($this->once())->method('replace')->with(ServicioLinks::BOX, 4, [5]);
		$this->links->method('ids')->willReturn([5]);

		$this->assertSame(['id' => 4, 'name' => 'Box 4', 'servicioIds' => [5]], $this->service->update(4, ['name' => 'Box 4', 'servicioIds' => [5]]));
	}

	public function testItListsBoxesWithTheirServicioIds(): void {
		$this->boxes->method('findAll')->willReturn([$this->box(4, 'Box 3')]);
		$this->links->method('ids')->with(ServicioLinks::BOX, 4)->willReturn([3, 4]);

		$this->assertSame([['id' => 4, 'name' => 'Box 3', 'servicioIds' => [3, 4]]], $this->service->list());
	}

	public function testABoxHoldingATurnoCannotBeDeleted(): void {
		$this->boxes->method('find')->willReturn($this->box(4, 'Box 3'));
		$this->turnos->method('findActiveForBox')->with(4, '2026-10-18')->willReturn(new Turno());
		$this->boxes->expects($this->never())->method('delete');

		$this->expectException(Refused::class);
		$this->expectExceptionMessage('Esta ventanilla tiene un turno en curso.');
		$this->service->delete(4);
	}

	public function testAnIdleBoxIsDeletedWithItsLinks(): void {
		$box = $this->box(4, 'Box 3');
		$this->boxes->method('find')->willReturn($box);
		$this->turnos->method('findActiveForBox')->willReturn(null);
		$this->boxes->expects($this->once())->method('delete')->with($box);
		$this->links->expects($this->once())->method('replace')->with(ServicioLinks::BOX, 4, []);

		$this->service->delete(4);
	}
}
