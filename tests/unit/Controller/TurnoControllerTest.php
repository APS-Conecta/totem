<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Tests\Unit\Controller;

use OCA\Totem\Controller\TurnoController;
use OCA\Totem\Exception\Refused;
use OCA\Totem\Service\ServicioService;
use OCA\Totem\Service\TurnoService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;

class TurnoControllerTest extends ControllerTestCase {
	public function testItIssuesATurnoAndAnswersItsLabel(): void {
		$turnos = $this->createMock(TurnoService::class);
		$turnos->expects($this->once())->method('issue')->with(3, true)->willReturn(['id' => 1, 'label' => 'FAR-P-001', 'servicio' => 'Farmacia', 'preferente' => true]);
		$controller = new TurnoController($this->request(), $turnos, $this->createMock(ServicioService::class));

		$response = $controller->create(3, true);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('FAR-P-001', $response->getData()['label']);
	}

	public function testARefusalIsA422WithItsSentence(): void {
		$turnos = $this->createMock(TurnoService::class);
		$turnos->method('issue')->willThrowException(new Refused('Farmacia atiende de 07:30 a 16:30.'));
		$controller = new TurnoController($this->request(), $turnos, $this->createMock(ServicioService::class));

		$response = $controller->create(3, true);

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['message' => 'Farmacia atiende de 07:30 a 16:30.'], $response->getData());
	}

	public function testAnUnknownServicioIsA404(): void {
		$turnos = $this->createMock(TurnoService::class);
		$turnos->method('issue')->willThrowException(new DoesNotExistException('x'));
		$controller = new TurnoController($this->request(), $turnos, $this->createMock(ServicioService::class));

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->create(99, false)->getStatus());
	}

	public function testTheTotemListsTheServiciosItMayOffer(): void {
		$servicios = $this->createMock(ServicioService::class);
		$servicios->method('forTotem')->willReturn([['id' => 1, 'name' => 'Farmacia', 'prefix' => 'FAR', 'color' => '#0b6e69']]);
		$controller = new TurnoController($this->request(), $this->createMock(TurnoService::class), $servicios);

		$this->assertSame('Farmacia', $controller->servicios()->getData()[0]['name']);
	}
}
