<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Controller;

use APS\Common\Refuses;
use OCA\Totem\AppInfo\Application;
use OCA\Totem\Service\DisplayFeed;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/** The waiting-room display's poll: anonymous, read-only, no personal data. */
class DisplayController extends Controller {
	use Refuses;

	public function __construct(IRequest $request, private DisplayFeed $feed) {
		parent::__construct(Application::APP_ID, $request);
	}

	/** Several displays can share the clinic's IP and each polls every 2 s: 30 a minute each. */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 900, period: 60)]
	public function feed(string $slug, int $since = 0): JSONResponse {
		return $this->attempt(fn () => $this->feed->since($slug, $since), 'Esa pantalla no existe.');
	}
}
