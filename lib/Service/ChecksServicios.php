<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Service;

use OCA\Totem\Db\ServicioMapper;
use OCA\Totem\Exception\Refused;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * The write-time reference check boxes and pantallas share: there are no
 * foreign keys (the schema is portable), so a link to a servicio is proven to
 * exist here, as a sentence, instead of failing in the database.
 *
 * @property ServicioMapper $servicios
 */
trait ChecksServicios {
	/** @param list<int> $ids */
	private function assertServiciosExist(array $ids): void {
		foreach ($ids as $id) {
			try {
				$this->servicios->find($id);
			} catch (DoesNotExistException) {
				throw new Refused('Alguna unidad elegida ya no existe.');
			}
		}
	}
}
