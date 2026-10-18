<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Controller;

use OCA\Totem\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Defaults;
use OCP\IRequest;
use OCP\Util;

/**
 * The four pages, each its own small bundle. A top-level navigation carries no
 * request token, so every page is NoCSRFRequired (NC 34 would answer 412).
 * The kiosk and the display are chromeless (the base layout: scripts but no Nextcloud chrome) and public. Thin glue
 * around framework statics, so it is covered by the e2e walking skeleton rather
 * than a unit test (farmacia's PageController is the same).
 */
class PageController extends Controller {
	public function __construct(IRequest $request, private IInitialState $initialState, private Defaults $defaults) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function totem(): TemplateResponse {
		// One establishment per instance (gestion#ADR-0013): the instance name is the CESFAM's name.
		$this->initialState->provideInitialState('establishment', $this->defaults->getName());

		return $this->page('totem', TemplateResponse::RENDER_AS_BASE);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function display(string $slug): TemplateResponse {
		$this->initialState->provideInitialState('slug', $slug);

		return $this->withVoice($this->page('display', TemplateResponse::RENDER_AS_BASE));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function box(): TemplateResponse {
		return $this->page('box');
	}

	#[NoCSRFRequired]
	public function admin(): TemplateResponse {
		// "Probar aviso" plays the voice, so the admin page needs the worker too.
		return $this->withVoice($this->page('admin'));
	}

	/**
	 * The bundled voice runs in a Web Worker served by this app (docs/adr/0005). Nextcloud's default
	 * policy has no worker-src, which falls back to script-src (nonces only) and blocks it.
	 */
	private function withVoice(TemplateResponse $response): TemplateResponse {
		$policy = new ContentSecurityPolicy();
		$policy->addAllowedWorkerSrcDomain("'self'");
		$response->setContentSecurityPolicy($policy);

		return $response;
	}

	private function page(string $name, string $renderAs = TemplateResponse::RENDER_AS_USER): TemplateResponse {
		Util::addScript(Application::APP_ID, Application::APP_ID . '-' . $name);

		return new TemplateResponse(Application::APP_ID, $name, [], $renderAs);
	}
}
