<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Controller;

use APS\Common\Refuses;
use OCA\Totem\AppInfo\Application;
use OCA\Totem\Service\TotemService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Configuration of the totems (the kiosks) and their printers: any logged-in user, like the other
 * configuration (docs/adr/0008). The test print costs paper, so it has a tighter limit.
 */
class TotemController extends Controller {
	use Refuses;

	public function __construct(IRequest $request, private TotemService $service) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function index(): JSONResponse {
		return new JSONResponse($this->service->list());
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function create(): JSONResponse {
		return $this->attempt(fn () => $this->service->create($this->request->getParams()));
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function update(int $id): JSONResponse {
		return $this->attempt(fn () => $this->service->update($id, $this->request->getParams()), 'Este tótem ya no existe.');
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function destroy(int $id): JSONResponse {
		return $this->attempt(function () use ($id): array {
			$this->service->delete($id);

			return [];
		}, 'Este tótem ya no existe.');
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 10, period: 60)]
	public function testPrint(int $id): JSONResponse {
		return $this->attempt(fn () => $this->service->testPrint($id), 'Este tótem ya no existe.');
	}
}
