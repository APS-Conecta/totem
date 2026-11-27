// SPDX-License-Identifier: AGPL-3.0-or-later

// Import this FIRST in every entry, whether or not it loads another chunk today (the voice's Web Worker):
// webpack would otherwise ask for a chunk under /apps/totem/js/, which does not exist when the app lives in custom_apps.
import { generateFilePath } from '@nextcloud/router'

__webpack_public_path__ = generateFilePath('totem', '', 'js/')
