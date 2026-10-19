<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Controller;

use APS\Common\Refuses;
use OCA\Totem\AppInfo\Application;
use OCA\Totem\Service\BoxService;
use OCA\Totem\Service\CallService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * The box console: any logged-in user, not only admins. Each action answers
 * with the box's state, so the screen always shows what the server holds.
 */
class CallController extends Controller {
	use Refuses;

	public function __construct(
		IRequest $request,
		private CallService $calls,
		private BoxService $boxes,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 60)]
	public function boxes(): JSONResponse {
		return new JSONResponse($this->boxes->list());
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 60)]
	public function state(int $boxId): JSONResponse {
		return $this->attempt(fn () => $this->calls->state($boxId), 'Esa ventanilla ya no existe.');
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 60)]
	public function call(int $boxId): JSONResponse {
		return $this->attempt(fn () => $this->calls->callNext($boxId), 'Esa ventanilla ya no existe.');
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 60)]
	public function recall(int $boxId): JSONResponse {
		return $this->attempt(fn () => $this->calls->recall($boxId), 'Esa ventanilla ya no existe.');
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 60)]
	public function start(int $boxId): JSONResponse {
		return $this->attempt(fn () => $this->calls->start($boxId), 'Esa ventanilla ya no existe.');
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 60)]
	public function finish(int $boxId): JSONResponse {
		return $this->attempt(fn () => $this->calls->finish($boxId), 'Esa ventanilla ya no existe.');
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 60)]
	public function absent(int $boxId): JSONResponse {
		return $this->attempt(fn () => $this->calls->absent($boxId), 'Esa ventanilla ya no existe.');
	}
}
