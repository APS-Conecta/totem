<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Exception;

use APS\Common\Refusal;
use RuntimeException;

/**
 * A request understood and refused. The message is a Spanish sentence a person
 * can read and act on; aps-common's Refuses trait answers it as a 422.
 */
class Refused extends RuntimeException implements Refusal {
}
