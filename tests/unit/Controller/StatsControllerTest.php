<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Tests\Unit\Controller;

use OCA\Totem\Controller\StatsController;
use OCA\Totem\Exception\Refused;
use OCA\Totem\Service\StatsService;
use OCP\AppFramework\Http;

class StatsControllerTest extends ControllerTestCase {
	private function controller(StatsService $stats): StatsController {
		return new StatsController($this->request(), $stats);
	}

	public function testTheBoardIsTheServicesAnswerForTheKindAndDateAsked(): void {
		$stats = $this->createMock(StatsService::class);
		$stats->expects($this->once())->method('board')->with('week', '2026-10-21')->willReturn(['period' => ['kind' => 'week']]);

		$response = $this->controller($stats)->board('week', '2026-10-21');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['period' => ['kind' => 'week']], $response->getData());
	}

	public function testWithoutAKindOrDateItAsksForToday(): void {
		$stats = $this->createMock(StatsService::class);
		$stats->expects($this->once())->method('board')->with('day', null)->willReturn([]);

		$this->controller($stats)->board();
	}

	public function testARefusedBoardIsA422WithItsSentence(): void {
		$stats = $this->createMock(StatsService::class);
		$stats->method('board')->willThrowException(new Refused('Todavía no hay datos de ese período.'));

		$response = $this->controller($stats)->board('day', '2030-01-01');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['message' => 'Todavía no hay datos de ese período.'], $response->getData());
	}

	// The download itself (DataDownloadResponse needs symfony/string, which the bare unit container
	// does not have) is asserted by e2e/stats.spec.js: file name, content type and rows.

	public function testARefusedCsvIsA422NotAFile(): void {
		$stats = $this->createMock(StatsService::class);
		$stats->method('csv')->willThrowException(new Refused('Elija día, semana o mes y una fecha válida.'));

		$response = $this->controller($stats)->csv('year', 'x');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['message' => 'Elija día, semana o mes y una fecha válida.'], $response->getData());
	}
}
