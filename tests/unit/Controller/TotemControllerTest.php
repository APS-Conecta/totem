<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Tests\Unit\Controller;

use OCA\Totem\Controller\TotemController;
use OCA\Totem\Exception\Refused;
use OCA\Totem\Service\TotemService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;

/** The totem controller is as thin as the other configuration ones: list, create, update, delete, and the test print. */
class TotemControllerTest extends ControllerTestCase {
	private function body(array $params): \OCP\IRequest {
		$request = $this->request();
		$request->method('getParams')->willReturn($params);

		return $request;
	}

	public function testListCreateUpdateAndDeleteAnswerTheService(): void {
		$service = $this->createMock(TotemService::class);
		$service->method('list')->willReturn([['id' => 4]]);
		$service->expects($this->once())->method('create')->with(['name' => 'Sala'])->willReturn(['id' => 4]);
		$service->expects($this->once())->method('update')->with(4, ['name' => 'Sala'])->willReturn(['id' => 4]);
		$service->expects($this->once())->method('delete')->with(4);
		$controller = new TotemController($this->body(['name' => 'Sala']), $service);

		$this->assertSame([['id' => 4]], $controller->index()->getData());
		$this->assertSame(['id' => 4], $controller->create()->getData());
		$this->assertSame(['id' => 4], $controller->update(4)->getData());
		$this->assertSame([], $controller->destroy(4)->getData());
	}

	public function testARefusalIsA422AndAMissingTotemA404(): void {
		$service = $this->createMock(TotemService::class);
		$service->method('create')->willThrowException(new Refused('Ya existe un tótem con ese nombre.'));
		$service->method('update')->willThrowException(new DoesNotExistException('x'));
		$controller = new TotemController($this->body([]), $service);

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $controller->create()->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->update(9)->getStatus());
	}

	public function testATestPrintAnswersWhetherThePrinterAnswered(): void {
		$service = $this->createMock(TotemService::class);
		$service->expects($this->once())->method('testPrint')->with(4)->willReturn(['printed' => true, 'totem' => ['id' => 4]]);
		$controller = new TotemController($this->request(), $service);

		$response = $controller->testPrint(4);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['printed']);
	}

	public function testATestPrintAtATotemWithoutAPrinterIsA422(): void {
		$service = $this->createMock(TotemService::class);
		$service->method('testPrint')->willThrowException(new Refused('Este tótem no tiene impresora de red. Escriba su dirección y guarde.'));
		$controller = new TotemController($this->request(), $service);

		$response = $controller->testPrint(4);

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertStringContainsString('impresora de red', $response->getData()['message']);
	}

	public function testATestPrintAtAnUnknownTotemIsA404(): void {
		$service = $this->createMock(TotemService::class);
		$service->method('testPrint')->willThrowException(new DoesNotExistException('x'));

		$this->assertSame(Http::STATUS_NOT_FOUND, (new TotemController($this->request(), $service))->testPrint(99)->getStatus());
	}
}
