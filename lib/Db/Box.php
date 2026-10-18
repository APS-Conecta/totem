<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A desk. The servicios it covers live in totem_box_servicio (ServicioLinks).
 *
 * @method string getName()
 * @method void setName(string $name)
 */
class Box extends Entity {
	protected $name = '';
}
