<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Tests\Integration;

use OCA\Totem\Db\BoxMapper;
use OCA\Totem\Db\PantallaMapper;
use OCA\Totem\Db\TotemMapper;
use OCA\Totem\Service\BoxService;
use OCA\Totem\Service\PantallaService;
use OCA\Totem\Service\TotemService;
use OCP\Server;

/**
 * A name with no ASCII letter or digit makes no address (#39): it is refused on create and on rename, before the
 * duplicate check, so two such names are never refused as the same name and nothing is stored.
 */
final class NameWithoutAddressTest extends IntegrationTestCase {
	private const SENTENCE = 'El nombre debe tener al menos una letra o un número.';

	public function testATotemNamedOnlyWithPunctuationIsRefusedAndNotStored(): void {
		$this->assertSame(self::SENTENCE, $this->refusal(fn () => Server::get(TotemService::class)->create(['name' => '!!!'])));
		$this->assertCount(0, Server::get(TotemMapper::class)->findAll());
	}

	public function testAPantallaWithANonLatinNameIsRefusedAndNotStored(): void {
		$this->assertSame(self::SENTENCE, $this->refusal(fn () => Server::get(PantallaService::class)->create(['name' => 'Зал'])));
		$this->assertCount(0, Server::get(PantallaMapper::class)->findAll());
	}

	public function testABoxWithAnEmojiNameIsRefusedAndNotStored(): void {
		$servicio = $this->servicio();

		$this->assertSame(self::SENTENCE, $this->refusal(fn () => Server::get(BoxService::class)->create(['name' => '★', 'servicioIds' => [$servicio->getId()]])));
		$this->assertCount(0, Server::get(BoxMapper::class)->findAll());
	}

	public function testRenamingATotemToSuchANameIsRefusedAndKeepsItsName(): void {
		$hall = $this->totem('hall', 'Hall');

		$this->assertSame(self::SENTENCE, $this->refusal(fn () => Server::get(TotemService::class)->update($hall->getId(), ['name' => '日本'])));
		$this->assertSame('Hall', Server::get(TotemMapper::class)->find($hall->getId())->getName());
	}

	public function testRenamingAPantallaToSuchANameIsRefusedAndKeepsItsName(): void {
		$hall = $this->pantalla('hall', 'Hall');

		$this->assertSame(self::SENTENCE, $this->refusal(fn () => Server::get(PantallaService::class)->update($hall->getId(), ['name' => '¡¡'])));
		$this->assertSame('Hall', Server::get(PantallaMapper::class)->find($hall->getId())->getName());
	}

	public function testBesideAStoredHallSuchANameGetsTheNewSentence(): void {
		$this->totem('hall', 'Hall');

		$this->assertSame(self::SENTENCE, $this->refusal(fn () => Server::get(TotemService::class)->create(['name' => 'Зал'])));
	}

	public function testBesideATotemRenamedToSuchANameBeforeTheRuleAnotherIsNotCalledTaken(): void {
		$this->totem('hall', 'Зал');

		$this->assertSame(self::SENTENCE, $this->refusal(fn () => Server::get(TotemService::class)->create(['name' => '日本'])));
	}

	public function testAnAccentedSpanishNameStillMakesItsAddress(): void {
		$saved = Server::get(TotemService::class)->create(['name' => 'Ñuñoa']);

		$this->assertSame('nunoa', $saved['slug']);
	}
}
