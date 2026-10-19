<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Controller;

use APS\Common\Refuses;
use OCA\Totem\AppInfo\Application;
use OCA\Totem\Service\ServicioService;
use OCA\Totem\Service\TotemService;
use OCA\Totem\Service\TurnoService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * The totem's two calls, both anonymous: which servicios to offer, and issue a
 * turno (`totem` names the kiosk they are for: its unidades and its printer). Every patient shares the kiosk's IP, so the rate limit is generous
 * (docs/threat-model.md); the real bounds are the servicio's hours and quota.
 */
class TurnoController extends Controller {
	use Refuses;

	public function __construct(
		IRequest $request,
		private TurnoService $turnos,
		private ServicioService $servicios,
		private TotemService $totems,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function create(int $servicioId, bool $preferente, ?string $totem = null): JSONResponse {
		return $this->attempt(fn () => $this->turnos->issue($servicioId, $preferente, $totem), 'Esa unidad ya no existe.');
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function servicios(?string $totem = null): JSONResponse {
		try {
			$only = $totem === null ? [] : $this->totems->offeredBy($totem);
		} catch (DoesNotExistException) {
			return $this->missing('Este tótem ya no existe.');
		}

		return new JSONResponse($this->servicios->forTotem($only));
	}
}
