# Totem

Turnos de atención para CESFAM, CECOSF y SAPU. A native Nextcloud app (NC 34, PHP 8.2–8.5, AGPL-3.0-or-later).

- **Totem** (`/apps/totem/totem`, anonymous, touch; each configured tótem has its own `/apps/totem/totem/<name>`): two taps, unidad and preferente, and the ticket prints, at a network printer the server drives or through the browser.
- **Pantalla de sala** (`/apps/totem/display/<pantalla>`, anonymous): the call, the recent calls, chimes and a Spanish voice (a bundled open-source model, offline).
- **Ventanilla** (`/apps/totem/box`, logged in): one state-driven button — Llamar siguiente, Iniciar atención, Finalizar atención.
- **Estadísticas** (`/apps/totem/estadisticas`, logged in): absolute counts of tickets per day, week or month, by sector, unidad and preferente, with a CSV download.
- **Configuración** (`/apps/totem/admin`, logged in): unidades (and which ones are sectors), ventanillas, pantallas and tótems (with their printers).

The three staff sections (Ventanilla, Estadísticas, Configuración) are one app with Nextcloud's menu and sidebar (`docs/adr/0008`); the totem and the display stay separate, public screens.

No RUN and no personal data is asked for or stored. See `docs/threat-model.md`, `docs/adr/`, `CONTEXT.md`.
The approved mockups are in `docs/mockups/`.

## Develop

```
make test                       # PHP units, in a bare container
make instance-up                # throwaway Nextcloud 34 + PostgreSQL at http://localhost:8094 (admin / admin), plus a stand-in ticket printer for the e2e tests
make test-integration           # mappers and migration against that instance
make e2e                        # the walking skeleton (Playwright)
npm ci && npm test && npm run build
make tts-assets                 # the bundled voice (re-creates the committed runtime + 2 Piper models in js/tts/, ~160 MB; only needed to upgrade them)
```

`aps-common` is consumed, never edited: `make aps-sync` copies it beside this repo into `vendor/aps/common/src` and `src/aps`.

## Release

The release workflow (`.github/workflows/release.yml`) builds the bundles and packs the tarball on every pull request; on a `v*` tag, inside the repo's protected `release` environment, it signs and publishes `totem.tar.gz`, `info.xml`, `SHA256SUMS` and `SHA256SUMS.minisig` (the org's shared workflow, instalador #31). The bundles in `js/` are build output, never committed — a fresh clone needs `npm ci && npm run build` before the app works — while `js/tts/`, the committed voice, ships inside every tarball (`docs/adr/0005`).
