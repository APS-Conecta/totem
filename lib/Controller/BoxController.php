<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Controller;

use APS\Common\Refuses;
use OCA\Totem\AppInfo\Application;
use OCA\Totem\Service\BoxService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/** Admin configuration of the boxs. Nextcloud's own admin guard applies: no attribute loosens it. */
class BoxController extends Controller {
	use Refuses;

	public function __construct(IRequest $request, private BoxService $service) {
		parent::__construct(Application::APP_ID, $request);
	}

	public function index(): JSONResponse {
		return new JSONResponse($this->service->list());
	}

	public function create(): JSONResponse {
		return $this->attempt(fn () => $this->service->create($this->request->getParams()));
	}

	public function update(int $id): JSONResponse {
		return $this->attempt(fn () => $this->service->update($id, $this->request->getParams()), 'Esto ya no existe.');
	}

	public function destroy(int $id): JSONResponse {
		return $this->attempt(function () use ($id): array {
			$this->service->delete($id);

			return [];
		}, 'Esto ya no existe.');
	}
}
