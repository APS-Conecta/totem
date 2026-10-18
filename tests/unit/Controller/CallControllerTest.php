<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Tests\Unit\Controller;

use OCA\Totem\Controller\CallController;
use OCA\Totem\Exception\Refused;
use OCA\Totem\Service\BoxService;
use OCA\Totem\Service\CallService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use PHPUnit\Framework\Attributes\DataProvider;

class CallControllerTest extends ControllerTestCase {
	/** @return array<string, array{string}> */
	public static function actions(): array {
		return ['call' => ['call'], 'recall' => ['recall'], 'start' => ['start'], 'finish' => ['finish'], 'absent' => ['absent']];
	}

	private function controller(CallService $calls): CallController {
		return new CallController($this->request(), $calls, $this->createMock(BoxService::class));
	}

	/** The controller's `call` maps to the service's `callNext`; the rest share their names. */
	private static function serviceMethod(string $action): string {
		return $action === 'call' ? 'callNext' : $action;
	}

	#[DataProvider('actions')]
	public function testEachActionRunsTheServiceForTheBoxAndAnswersItsState(string $action): void {
		$calls = $this->createMock(CallService::class);
		$calls->expects($this->once())->method(self::serviceMethod($action))->with(4)->willReturn(['current' => null]);

		$response = $this->controller($calls)->$action(4);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['current' => null], $response->getData());
	}

	#[DataProvider('actions')]
	public function testARefusedActionIsA422(string $action): void {
		$calls = $this->createMock(CallService::class);
		$calls->method(self::serviceMethod($action))->willThrowException(new Refused('No hay turnos en espera.'));

		$response = $this->controller($calls)->$action(4);

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['message' => 'No hay turnos en espera.'], $response->getData());
	}

	public function testTheStateOfAnUnknownBoxIsA404(): void {
		$calls = $this->createMock(CallService::class);
		$calls->method('state')->willThrowException(new DoesNotExistException('x'));

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller($calls)->state(99)->getStatus());
	}

	public function testTheBoxPickerListsTheBoxes(): void {
		$boxes = $this->createMock(BoxService::class);
		$boxes->method('list')->willReturn([['id' => 4, 'name' => 'Box 3', 'servicioIds' => [3]]]);
		$controller = new CallController($this->request(), $this->createMock(CallService::class), $boxes);

		$this->assertSame('Box 3', $controller->boxes()->getData()[0]['name']);
	}
}
