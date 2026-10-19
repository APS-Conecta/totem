// SPDX-License-Identifier: AGPL-3.0-or-later
//
// A stand-in for an 80 mm network ticket printer, for the end-to-end tests: it listens on 9100 like the real
// thing, keeps the bytes of the last ticket it was sent, and tells a test what it received over HTTP (9101).
// Runs as the `printer` service of compose.dev.yaml, so the Nextcloud container reaches it at the host name
// `printer` (a private address, as a clinic's printer would be).
//
//   GET /health  -> 200 once it is listening
//   GET /last    -> { count, bytes } where bytes is the last ticket in base64, or null
//   GET /reset   -> forgets what it received

const http = require('node:http')
const net = require('node:net')

let count = 0
let last = null

net.createServer((socket) => {
	const chunks = []
	socket.on('data', (chunk) => chunks.push(chunk))
	socket.on('end', () => {
		last = Buffer.concat(chunks)
		count += 1
	})
	socket.on('error', () => {})
}).listen(9100)

http.createServer((request, response) => {
	if (request.url === '/reset') {
		count = 0
		last = null
	}
	response.setHeader('Content-Type', 'application/json')
	response.end(JSON.stringify({ count, bytes: last === null ? null : last.toString('base64') }))
}).listen(9101)
