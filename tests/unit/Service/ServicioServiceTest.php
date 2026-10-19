<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Tests\Unit\Service;

use OCA\Totem\Db\Servicio;
use OCA\Totem\Db\ServicioMapper;
use OCA\Totem\Exception\Refused;
use OCA\Totem\Service\ServicioService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ServicioServiceTest extends TestCase {
	private ServicioMapper&MockObject $mapper;
	private ServicioService $service;

	protected function setUp(): void {
		$this->mapper = $this->createMock(ServicioMapper::class);
		$this->mapper->method('findBySlug')->willThrowException(new DoesNotExistException('free'));
		$this->mapper->method('findAll')->willReturn([]);
		$this->service = new ServicioService($this->mapper);
	}

	private function input(array $over = []): array {
		return $over + ['name' => ' Farmacia ', 'prefix' => 'far', 'color' => '#0B6E69', 'opens' => '07:30', 'closes' => '16:30', 'dailyQuota' => '', 'active' => true];
	}

	private function existing(int $id, string $name, string $prefix): Servicio {
		$s = new Servicio();
		$s->setId($id);
		$s->setSlug(strtolower($prefix));
		$s->setName($name);
		$s->setPrefix($prefix);
		$s->setColor('#4a4f57');
		$s->setOpens('08:00');
		$s->setCloses('17:00');
		$s->setActive(true);

		return $s;
	}

	public function testItStoresTheCleanedFieldsAndReturnsThemPresented(): void {
		$this->mapper->expects($this->once())->method('insert')->with($this->callback(
			static fn (Servicio $s) => $s->getName() === 'Farmacia' && $s->getSlug() === 'farmacia' && $s->getPrefix() === 'FAR'
				&& $s->getColor() === '#0b6e69' && $s->getDailyQuota() === null && $s->getActive() === true,
		))->willReturnCallback(static function (Servicio $s): Servicio {
			$s->setId(5);

			return $s;
		});

		$this->assertSame(
			['id' => 5, 'name' => 'Farmacia', 'prefix' => 'FAR', 'color' => '#0b6e69', 'opens' => '07:30', 'closes' => '16:30', 'dailyQuota' => null, 'active' => true, 'isSector' => false],
			$this->service->create($this->input()),
		);
	}

	public function testAServicioMarkedAsASectorIsStoredAndPresentedAsOne(): void {
		$this->mapper->expects($this->once())->method('insert')->with($this->callback(
			static fn (Servicio $s) => $s->getIsSector() === true,
		))->willReturnCallback(static function (Servicio $s): Servicio {
			$s->setId(6);

			return $s;
		});

		$this->assertTrue($this->service->create($this->input(['name' => 'Sector Azul', 'prefix' => 'azu', 'isSector' => true]))['isSector']);
	}

	public function testAServicioIsNotASectorUnlessItSaysSo(): void {
		$this->mapper->method('insert')->willReturnArgument(0);

		$this->assertFalse($this->service->create($this->input(['isSector' => 'yes']))['isSector']);
		$this->assertFalse($this->service->create($this->input())['isSector']);
	}

	public function testTheSlugFoldsAccentsAndCaseSoNamesCannotDifferByThem(): void {
		$this->mapper->expects($this->once())->method('insert')->with($this->callback(
			static fn (Servicio $s) => $s->getSlug() === 'toma_de_muestras',
		))->willReturnArgument(0);

		$this->service->create($this->input(['name' => 'Tóma de Muestras', 'prefix' => 'MUE']));
	}

	public function testAServicioWithTheSameNameIsRefused(): void {
		$mapper = $this->createMock(ServicioMapper::class);
		$mapper->method('findBySlug')->willReturn($this->existing(1, 'Farmacia', 'FAX'));
		$mapper->method('findAll')->willReturn([]);

		$this->expectException(Refused::class);
		$this->expectExceptionMessage('Ya existe una unidad con ese nombre.');
		(new ServicioService($mapper))->create($this->input());
	}

	public function testAServicioWithTheSamePrefixIsRefused(): void {
		$mapper = $this->createMock(ServicioMapper::class);
		$mapper->method('findBySlug')->willThrowException(new DoesNotExistException('free'));
		$mapper->method('findAll')->willReturn([$this->existing(1, 'Farmacia Central', 'FAR')]);

		$this->expectException(Refused::class);
		$this->expectExceptionMessage('Ya existe una unidad con el prefijo FAR.');
		(new ServicioService($mapper))->create($this->input());
	}

	public function testTheOpeningHourMustComeBeforeTheClosingHour(): void {
		$this->expectException(Refused::class);
		$this->expectExceptionMessage('La hora de apertura debe ser anterior a la de cierre.');
		$this->service->create($this->input(['opens' => '17:00', 'closes' => '08:00']));
	}

	public function testAnInvalidFieldRefusesBeforeAnythingIsStored(): void {
		$this->mapper->expects($this->never())->method('insert');

		$this->expectException(Refused::class);
		$this->service->create($this->input(['color' => 'verde']));
	}

	public function testUpdatingAServicioDoesNotCollideWithItself(): void {
		$mine = $this->existing(7, 'Farmacia', 'FAR');
		$mapper = $this->createMock(ServicioMapper::class);
		$mapper->method('find')->with(7)->willReturn($mine);
		$mapper->method('findBySlug')->willReturn($mine);
		$mapper->method('findAll')->willReturn([$mine]);
		$mapper->expects($this->once())->method('update')->willReturnArgument(0);

		$updated = (new ServicioService($mapper))->update(7, $this->input(['color' => '#a32035']));

		$this->assertSame('#a32035', $updated['color']);
		$this->assertSame(7, $updated['id']);
	}

	public function testAnUnknownServicioIsNotFoundOnUpdate(): void {
		$this->mapper->method('find')->willThrowException(new DoesNotExistException('x'));

		$this->expectException(DoesNotExistException::class);
		$this->service->update(99, $this->input());
	}

	public function testItListsServiciosPresented(): void {
		$mapper = $this->createMock(ServicioMapper::class);
		$mapper->method('findAll')->willReturn([$this->existing(1, 'Farmacia', 'FAR')]);

		$this->assertSame('FAR', (new ServicioService($mapper))->list()[0]['prefix']);
	}

	public function testTheTotemSeesOnlyActiveServiciosAndOnlyWhatItShows(): void {
		$open = $this->existing(1, 'Farmacia', 'FAR');
		$closed = $this->existing(2, 'Vacunatorio', 'VAC');
		$closed->setActive(false);
		$mapper = $this->createMock(ServicioMapper::class);
		$mapper->method('findAll')->willReturn([$open, $closed]);

		$this->assertSame(
			[['id' => 1, 'name' => 'Farmacia', 'prefix' => 'FAR', 'color' => '#4a4f57']],
			(new ServicioService($mapper))->forTotem(),
		);
	}
}
