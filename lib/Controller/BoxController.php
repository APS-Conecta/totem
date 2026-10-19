<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Controller;

use APS\Common\Refuses;
use OCA\Totem\AppInfo\Application;
use OCA\Totem\Service\BoxService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/** Configuration of the boxs: any logged-in user, by the owner's decision (docs/adr/0008). */
class BoxController extends Controller {
	use Refuses;

	public function __construct(IRequest $request, private BoxService $service) {
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
		return $this->attempt(fn () => $this->service->update($id, $this->request->getParams()), 'Esto ya no existe.');
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function destroy(int $id): JSONResponse {
		return $this->attempt(function () use ($id): array {
			$this->service->delete($id);

			return [];
		}, 'Esto ya no existe.');
	}
}
