<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Tests\Integration;

use OCA\Totem\Db\PantallaMapper;
use OCA\Totem\Db\TotemMapper;
use OCA\Totem\Exception\Refused;
use OCA\Totem\Service\PantallaService;
use OCA\Totem\Service\TotemService;
use OCP\Server;

/**
 * Tótems and pantallas that already share a name (renames allowed it before the name rule existed) stay editable:
 * an update that keeps the name is never refused because of it, while a rename onto a taken name still is.
 */
final class SharedNameTest extends IntegrationTestCase {
	public function testEitherTotemSharingANameTakesANewPrinter(): void {
		$first = $this->totem('sala_1', 'Sala 1');
		$second = $this->totem('sala_2', 'Sala 1');
		$service = Server::get(TotemService::class);

		$service->update($first->getId(), ['name' => 'Sala 1', 'printerHost' => '192.168.1.60']);
		$service->update($second->getId(), ['name' => 'Sala 1', 'printerHost' => '192.168.1.61']);

		$totems = Server::get(TotemMapper::class);
		$this->assertSame('192.168.1.60', $totems->find($first->getId())->getPrinterHost());
		$this->assertSame('192.168.1.61', $totems->find($second->getId())->getPrinterHost());
	}

	public function testEitherPantallaSharingANameTakesANewVolumeOrVoice(): void {
		$first = $this->pantalla('sala_1', 'Sala 1');
		$second = $this->pantalla('sala_2', 'Sala 1');
		$service = Server::get(PantallaService::class);

		$service->update($first->getId(), ['name' => 'Sala 1', 'volume' => 40, 'voice' => 'mujer']);
		$service->update($second->getId(), ['name' => 'Sala 1', 'volume' => 100, 'voice' => 'hombre']);

		$pantallas = Server::get(PantallaMapper::class);
		$this->assertSame(40, $pantallas->find($first->getId())->getVolume());
		$this->assertSame('hombre', $pantallas->find($second->getId())->getVoice());
	}

	public function testRenamingATotemOntoAnotherTotemsNameIsStillRefused(): void {
		$this->totem('sala_1', 'Sala 1');
		$entrada = $this->totem('entrada', 'Entrada');

		$this->expectExceptionObject(new Refused('Ya existe un tótem con ese nombre.'));
		Server::get(TotemService::class)->update($entrada->getId(), ['name' => 'SALA 1']);
	}

	public function testRenamingAPantallaOntoAnotherPantallasNameIsStillRefused(): void {
		$this->pantalla('sala_1', 'Sala 1');
		$entrada = $this->pantalla('entrada', 'Entrada');

		$this->expectExceptionObject(new Refused('Ya existe una pantalla con ese nombre.'));
		Server::get(PantallaService::class)->update($entrada->getId(), ['name' => 'sala  1']);
	}

	public function testRenamingOneOfTwoSharingTotemsToAFreeNameKeepsItsAddress(): void {
		$this->totem('sala_1', 'Sala 1');
		$second = $this->totem('sala_2', 'Sala 1');

		$saved = Server::get(TotemService::class)->update($second->getId(), ['name' => 'Sala 2']);

		$this->assertSame(['Sala 2', 'sala_2'], [$saved['name'], $saved['slug']]);
	}

	public function testRenamingOneOfTwoSharingPantallasToAFreeNameKeepsItsAddress(): void {
		$this->pantalla('sala_1', 'Sala 1');
		$second = $this->pantalla('sala_2', 'Sala 1');

		$saved = Server::get(PantallaService::class)->update($second->getId(), ['name' => 'Sala 2']);

		$this->assertSame(['Sala 2', 'sala_2'], [$saved['name'], $saved['slug']]);
	}
}
