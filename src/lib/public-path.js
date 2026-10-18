// SPDX-License-Identifier: AGPL-3.0-or-later

// Import this FIRST in an entry that loads another chunk (the voice's Web Worker): webpack would
// otherwise ask for it under /apps/totem/js/, which does not exist when the app lives in custom_apps.
import { generateFilePath } from '@nextcloud/router'

__webpack_public_path__ = generateFilePath('totem', '', 'js/')
