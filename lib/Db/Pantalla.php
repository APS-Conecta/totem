<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A waiting-room display. The servicios it shows live in totem_pantalla_servicio
 * (ServicioLinks); none linked means it shows all.
 *
 * @method string getSlug()
 * @method void setSlug(string $slug)
 * @method string getName()
 * @method void setName(string $name)
 * @method int getVolume()
 * @method void setVolume(int $volume)
 * @method string getVoice()
 * @method void setVoice(string $voice)
 */
class Pantalla extends Entity {
	protected $slug = '';
	protected $name = '';
	protected $volume = 100;
	protected $voice = 'mujer';

	public function __construct() {
		$this->addType('volume', 'integer');
	}
}
