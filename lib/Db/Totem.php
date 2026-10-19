<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A totem: one kiosk, with its own public address. The unidades it offers live in totem_totem_servicio
 * (ServicioLinks); none linked means it offers all. Its ticket printer, if any, is a network printer the
 * server prints to; without one the kiosk's browser prints. The last result of printing is kept so staff can
 * see a printer that stopped answering.
 *
 * @method string getSlug()
 * @method void setSlug(string $slug)
 * @method string getName()
 * @method void setName(string $name)
 * @method string|null getPrinterHost()
 * @method void setPrinterHost(?string $printerHost)
 * @method int getPrinterPort()
 * @method void setPrinterPort(int $printerPort)
 * @method bool|null getPrinterOk()
 * @method void setPrinterOk(?bool $printerOk)
 * @method int|null getPrinterCheckedAt()
 * @method void setPrinterCheckedAt(?int $printerCheckedAt)
 */
class Totem extends Entity {
	protected $slug = '';
	protected $name = '';
	protected $printerHost = null;
	protected $printerPort = 9100;
	protected $printerOk = null;
	protected $printerCheckedAt = null;

	public function __construct() {
		$this->addType('printerPort', 'integer');
		$this->addType('printerOk', 'boolean');
		$this->addType('printerCheckedAt', 'integer');
	}

	public function hasPrinter(): bool {
		return $this->printerHost !== null && $this->printerHost !== '';
	}
}
