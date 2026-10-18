<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One call of a turno to a box. Append-only: the display feed and the audit
 * trail. A re-llamado is another row for the same turno.
 *
 * @method int getTurnoId()
 * @method void setTurnoId(int $turnoId)
 * @method int getServicioId()
 * @method void setServicioId(int $servicioId)
 * @method int getBoxId()
 * @method void setBoxId(int $boxId)
 * @method int getCalledAt()
 * @method void setCalledAt(int $calledAt)
 */
class Llamado extends Entity {
	protected $turnoId = 0;
	protected $servicioId = 0;
	protected $boxId = 0;
	protected $calledAt = 0;

	public function __construct() {
		$this->addType('turnoId', 'integer');
		$this->addType('servicioId', 'integer');
		$this->addType('boxId', 'integer');
		$this->addType('calledAt', 'integer');
	}
}
