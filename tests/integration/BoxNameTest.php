<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Tests\Integration;

use OCA\Totem\Db\BoxMapper;
use OCA\Totem\Db\Servicio;
use OCA\Totem\Exception\Refused;
use OCA\Totem\Service\BoxService;
use OCP\Server;

/**
 * Two boxes never take one name: the Pantalla and the voice would announce both alike (#38). Names compare the way
 * tótem and pantalla names do, accents, case, spacing and punctuation ignored; a box keeping its own name is accepted.
 */
final class BoxNameTest extends IntegrationTestCase {
	private BoxService $service;
	private Servicio $servicio;

	protected function setUp(): void {
		parent::setUp();
		$this->service = Server::get(BoxService::class);
		$this->servicio = $this->servicio();
	}

	public function testASecondBoxWithATakenNameIsRefusedAndNotStored(): void {
		$this->service->create($this->data('Box 2'));

		$this->assertSame('Ya existe una ventanilla con ese nombre.', $this->refusal(fn () => $this->service->create($this->data('Box 2'))));
		$this->assertCount(1, Server::get(BoxMapper::class)->findAll());
	}

	public function testRenamingABoxOntoAnotherBoxsNameInOtherCaseOrAccentsIsRefused(): void {
		$this->service->create($this->data('Box 2'));
		$other = $this->service->create($this->data('Box 3'));

		$this->expectExceptionObject(new Refused('Ya existe una ventanilla con ese nombre.'));
		$this->service->update($other['id'], $this->data('bóx 2'));
	}

	public function testABoxKeepingItsOwnNameIsSaved(): void {
		$box = $this->service->create($this->data('Box 2'));
		$second = $this->servicio('Dental', 'DEN');

		$saved = $this->service->update($box['id'], ['name' => 'Box 2', 'servicioIds' => [$second->getId()]]);

		$this->assertSame(['Box 2', [$second->getId()]], [$saved['name'], $saved['servicioIds']]);
	}

	/** @return array{name: string, servicioIds: list<int>} */
	private function data(string $name): array {
		return ['name' => $name, 'servicioIds' => [$this->servicio->getId()]];
	}
}
