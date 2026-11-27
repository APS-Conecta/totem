<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Tests\Integration;

use OCA\Totem\Controller\DisplayController;
use OCA\Totem\Db\ServicioLinks;
use OCA\Totem\Service\BoxService;
use OCP\IRequest;
use OCP\Server;

/**
 * The public answer a Pantalla polls, against real PostgreSQL. Every key it
 * sends is in FIELDS, and the threat model names each one, so a new field
 * fails here until someone adds it to the threat model on purpose.
 */
final class DisplayFeedTest extends IntegrationTestCase {
	private const FIELDS = [
		'answer' => ['pantalla', 'day', 'llamados', 'cursor'],
		'pantalla' => ['name', 'volume', 'voice'],
		'llamado' => ['id', 'label', 'spoken', 'servicio', 'color', 'preferente', 'box', 'calledAt'],
	];

	public function testNoPersonalDataIsEverInTheFeed(): void {
		$this->now = $this->momentOf(self::DAY, '10:00');
		$farmacia = $this->servicio();
		$boxId = Server::get(BoxService::class)->create(['name' => 'Box 1', 'servicioIds' => [$farmacia->getId()]])['id'];
		Server::get(ServicioLinks::class)->replace(ServicioLinks::PANTALLA, (int)$this->pantalla('sala', 'Sala')->getId(), [(int)$farmacia->getId()]);
		$this->turno($farmacia, false, 40, $this->now);
		$this->calls()->callNext($boxId);

		$answer = (new DisplayController(Server::get(IRequest::class), $this->feed()))->feed('sala')->getData();

		$this->assertSame(self::FIELDS['answer'], array_keys($answer));
		$this->assertSame(self::FIELDS['pantalla'], array_keys($answer['pantalla']));
		$this->assertCount(1, $answer['llamados']);
		$this->assertSame(self::FIELDS['llamado'], array_keys($answer['llamados'][0]));
	}

	public function testTheThreatModelNamesEveryFieldTheFeedSends(): void {
		$threatModel = (string)file_get_contents(__DIR__ . '/../../docs/threat-model.md');
		$this->assertSame(1, preg_match('/^- The display feed .*$/m', $threatModel, $bullet), 'the threat model has a display feed bullet');

		foreach (array_merge(...array_values(self::FIELDS)) as $key) {
			$this->assertStringContainsString("`$key`", $bullet[0]);
		}
	}
}
