# Totem

Turnos de atención para CESFAM, CECOSF y SAPU. A native Nextcloud app (NC 34, PHP 8.2–8.5, AGPL-3.0-or-later).

- **Totem** (`/apps/totem/totem`, anonymous, touch): two taps, servicio and preferente, and the ticket prints.
- **Pantalla de sala** (`/apps/totem/display/<pantalla>`, anonymous): the call, the recent calls, chimes and a Spanish voice (a bundled open-source model, offline).
- **Box** (`/apps/totem/box`, logged in): one state-driven button — Llamar siguiente, Iniciar atención, Finalizar atención.
- **Estadísticas** (`/apps/totem/estadisticas`, logged in): absolute counts of tickets per day, week or month, by sector, servicio and preferente, with a CSV download.
- **Administración** (`/apps/totem/admin`, Nextcloud admin): servicios (and which ones are sectors), boxes, pantallas.

No RUN and no personal data is asked for or stored. See `docs/threat-model.md`, `docs/adr/`, `CONTEXT.md`.
The approved mockups are in `docs/mockups/`.

## Develop

```
make test                       # PHP units, in a bare container
make instance-up                # throwaway Nextcloud 34 + PostgreSQL at http://localhost:8094 (admin / admin)
make test-integration           # mappers and migration against that instance
make e2e                        # the walking skeleton (Playwright)
npm ci && npm test && npm run build
make tts-assets                 # the bundled voice (re-creates the committed runtime + 2 Piper models in js/tts/, ~160 MB; only needed to upgrade them)
```

`aps-common` is consumed, never edited: `make aps-sync` copies it beside this repo into `vendor/aps/common/src` and `src/aps`.
