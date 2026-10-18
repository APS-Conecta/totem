<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A queue a patient joins (CONTEXT.md, Servicio).
 *
 * @method string getSlug()
 * @method void setSlug(string $slug)
 * @method string getName()
 * @method void setName(string $name)
 * @method string getPrefix()
 * @method void setPrefix(string $prefix)
 * @method string getColor()
 * @method void setColor(string $color)
 * @method string getOpens()
 * @method void setOpens(string $opens)
 * @method string getCloses()
 * @method void setCloses(string $closes)
 * @method int|null getDailyQuota()
 * @method void setDailyQuota(?int $dailyQuota)
 * @method bool getActive()
 * @method void setActive(bool $active)
 * @method bool getIsSector()
 * @method void setIsSector(bool $isSector)
 */
class Servicio extends Entity {
	protected $slug = '';
	protected $name = '';
	protected $prefix = '';
	protected $color = '#4a4f57';
	protected $opens = '00:00';
	protected $closes = '23:59';
	protected $dailyQuota = null;
	protected $active = true;
	protected $isSector = false;

	public function __construct() {
		$this->addType('dailyQuota', 'integer');
		$this->addType('active', 'boolean');
		$this->addType('isSector', 'boolean');
	}
}
