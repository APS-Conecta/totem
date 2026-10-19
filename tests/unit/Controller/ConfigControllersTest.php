<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Tests\Unit\Controller;

use OCA\Totem\Controller\BoxController;
use OCA\Totem\Controller\PantallaController;
use OCA\Totem\Controller\ServicioController;
use OCA\Totem\Exception\Refused;
use OCA\Totem\Service\BoxService;
use OCA\Totem\Service\PantallaService;
use OCA\Totem\Service\ServicioService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;

/** The three admin controllers are thin and identical in shape: list, create, update (boxes and pantallas also delete). */
class ConfigControllersTest extends ControllerTestCase {
	/** The body of the request, as Nextcloud hands it to a controller. */
	private function body(array $params): \OCP\IRequest {
		$request = $this->request();
		$request->method('getParams')->willReturn($params);

		return $request;
	}

	public function testServiciosListCreateAndUpdateAnswerTheService(): void {
		$service = $this->createMock(ServicioService::class);
		$service->method('list')->willReturn([['id' => 1]]);
		$service->expects($this->once())->method('create')->with(['name' => 'Farmacia'])->willReturn(['id' => 1]);
		$service->expects($this->once())->method('update')->with(1, ['name' => 'Farmacia'])->willReturn(['id' => 1]);
		$controller = new ServicioController($this->body(['name' => 'Farmacia']), $service);

		$this->assertSame([['id' => 1]], $controller->index()->getData());
		$this->assertSame(['id' => 1], $controller->create()->getData());
		$this->assertSame(['id' => 1], $controller->update(1)->getData());
	}

	public function testAServicioRefusalIsA422AndAMissingOneA404(): void {
		$service = $this->createMock(ServicioService::class);
		$service->method('create')->willThrowException(new Refused('Ya existe una unidad con ese nombre.'));
		$service->method('update')->willThrowException(new DoesNotExistException('x'));
		$controller = new ServicioController($this->body([]), $service);

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $controller->create()->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->update(9)->getStatus());
	}

	public function testBoxesListCreateUpdateAndDelete(): void {
		$service = $this->createMock(BoxService::class);
		$service->method('list')->willReturn([['id' => 4]]);
		$service->expects($this->once())->method('create')->with(['name' => 'Box 3', 'servicioIds' => [1]])->willReturn(['id' => 4]);
		$service->expects($this->once())->method('update')->with(4, ['name' => 'Box 3', 'servicioIds' => [1]])->willReturn(['id' => 4]);
		$service->expects($this->once())->method('delete')->with(4);
		$controller = new BoxController($this->body(['name' => 'Box 3', 'servicioIds' => [1]]), $service);

		$this->assertSame([['id' => 4]], $controller->index()->getData());
		$this->assertSame(['id' => 4], $controller->create()->getData());
		$this->assertSame(['id' => 4], $controller->update(4)->getData());
		$this->assertSame(Http::STATUS_OK, $controller->destroy(4)->getStatus());
	}

	public function testABoxHoldingATurnoRefusesToBeDeletedWithA422(): void {
		$service = $this->createMock(BoxService::class);
		$service->method('delete')->willThrowException(new Refused('Esta ventanilla tiene un turno en curso.'));

		$response = (new BoxController($this->request(), $service))->destroy(4);

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['message' => 'Esta ventanilla tiene un turno en curso.'], $response->getData());
	}

	public function testPantallasListCreateUpdateAndDelete(): void {
		$service = $this->createMock(PantallaService::class);
		$service->method('list')->willReturn([['id' => 2]]);
		$service->expects($this->once())->method('create')->willReturn(['id' => 2, 'slug' => 'sala']);
		$service->expects($this->once())->method('update')->with(2, ['volume' => 30])->willReturn(['id' => 2]);
		$service->expects($this->once())->method('delete')->with(2);
		$controller = new PantallaController($this->body(['volume' => 30]), $service);

		$this->assertSame([['id' => 2]], $controller->index()->getData());
		$this->assertSame('sala', $controller->create()->getData()['slug']);
		$this->assertSame(['id' => 2], $controller->update(2)->getData());
		$this->assertSame(Http::STATUS_OK, $controller->destroy(2)->getStatus());
	}
}
