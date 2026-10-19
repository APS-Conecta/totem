const path = require('path')
const webpackConfig = require('@nextcloud/webpack-vue-config')

// One small bundle per page, each its own surface: js/totem-<name>.js, matching
// Util::addScript in PageController. The totem and display are public pages and
// share no chrome with the Nextcloud ones (box, admin).
const pages = ['totem', 'display', 'box', 'admin', 'stats']
webpackConfig.entry = Object.fromEntries(pages.map((p) => [p, path.join(__dirname, 'src', p, 'main.js')]))

// The build cleans js/, but js/tts/ (the committed bundled voice, `make tts-assets`) is not ours to delete.
webpackConfig.output = { ...webpackConfig.output, clean: { keep: /^tts\// } }

module.exports = webpackConfig
