<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Controller;

use APS\Common\Refusal;
use APS\Common\Refuses;
use OCA\Totem\AppInfo\Application;
use OCA\Totem\Service\StatsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * The statistics board: any logged-in user, read-only, counts and names only.
 * The CSV is a plain link, which carries no request token, so it is exempt from
 * the CSRF check; it changes nothing.
 */
class StatsController extends Controller {
	use Refuses;

	public function __construct(IRequest $request, private StatsService $stats) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function board(string $kind = 'day', ?string $date = null): JSONResponse {
		return $this->attempt(fn () => $this->stats->board($kind, $date));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 20, period: 60)]
	public function csv(string $kind = 'day', ?string $date = null): DataDownloadResponse|JSONResponse {
		try {
			$file = $this->stats->csv($kind, $date);
		} catch (Refusal $e) {
			return $this->refuse($e->getMessage());
		}

		return new DataDownloadResponse($file['content'], $file['filename'], 'text/csv; charset=utf-8');
	}
}
