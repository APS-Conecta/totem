<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Tests\Unit\Controller;

use OCP\IRequest;
use PHPUnit\Framework\TestCase;

abstract class ControllerTestCase extends TestCase {
	final protected function request(): IRequest {
		return $this->createMock(IRequest::class);
	}
}
