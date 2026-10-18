<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Tests\Unit\Controller;

use OCA\Totem\Controller\DisplayController;
use OCA\Totem\Service\DisplayFeed;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;

class DisplayControllerTest extends ControllerTestCase {
	public function testItAnswersTheFeedAfterTheCursor(): void {
		$feed = $this->createMock(DisplayFeed::class);
		$feed->expects($this->once())->method('since')->with('sala', 17)->willReturn(['cursor' => 20, 'llamados' => [], 'pantalla' => []]);

		$response = (new DisplayController($this->request(), $feed))->feed('sala', 17);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(20, $response->getData()['cursor']);
	}

	public function testAnUnknownPantallaIsA404(): void {
		$feed = $this->createMock(DisplayFeed::class);
		$feed->method('since')->willThrowException(new DoesNotExistException('x'));

		$this->assertSame(Http::STATUS_NOT_FOUND, (new DisplayController($this->request(), $feed))->feed('otra', 0)->getStatus());
	}
}
