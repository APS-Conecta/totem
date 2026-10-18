<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One place in a servicio's queue for one day. It carries no patient identity.
 *
 * @method int getServicioId()
 * @method void setServicioId(int $servicioId)
 * @method string getDay()
 * @method void setDay(string $day)
 * @method bool getPreferente()
 * @method void setPreferente(bool $preferente)
 * @method int getSeq()
 * @method void setSeq(int $seq)
 * @method string getEstado()
 * @method void setEstado(string $estado)
 * @method int|null getBoxId()
 * @method void setBoxId(?int $boxId)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int|null getCalledAt()
 * @method void setCalledAt(?int $calledAt)
 * @method int|null getServedAt()
 * @method void setServedAt(?int $servedAt)
 * @method int|null getFinishedAt()
 * @method void setFinishedAt(?int $finishedAt)
 */
class Turno extends Entity {
	protected $servicioId = 0;
	protected $day = '';
	protected $preferente = false;
	protected $seq = 0;
	protected $estado = 'waiting';
	protected $boxId = null;
	protected $createdAt = 0;
	protected $calledAt = null;
	protected $servedAt = null;
	protected $finishedAt = null;

	public function __construct() {
		$this->addType('servicioId', 'integer');
		$this->addType('preferente', 'boolean');
		$this->addType('seq', 'integer');
		$this->addType('boxId', 'integer');
		$this->addType('createdAt', 'integer');
		$this->addType('calledAt', 'integer');
		$this->addType('servedAt', 'integer');
		$this->addType('finishedAt', 'integer');
	}
}
