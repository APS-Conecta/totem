# AGENTS.md — Totem

Turnos de atención para CESFAM, CECOSF y SAPU, a Nextcloud app: the patient takes a turno on a touch totem (two taps), staff call it from a box, the waiting-room display shows and announces it. No patient data of any kind is collected.

## Working rules

- **TDD, no exceptions:** no production code without a failing test first. Watch each test fail for the right reason, write the minimum, run the whole suite.
- **Names:** code is English, but a domain term keeps the spelling `CONTEXT.md` gives it (`Servicio`, `Turno`, `Llamado`, `Pantalla`, `Box`, `Estado`). Rename an existing name only inside code a change already touches, and never a table, column, route or controller action. **On screen a Servicio is a _Unidad_ and a Box is a _Ventanilla_** (feminine: "Nueva unidad", "Esta ventanilla"); the code keeps Servicio and Box.
- **Threat model:** read `docs/threat-model.md` before any security work.
- **Gate before opening a PR:** `git clone --depth 1 https://github.com/APS-Conecta/aps-common.git ../aps-common` if it is missing; `make aps-drift`; `npm ci`; `npm run lint-js`; `npm test`; `npm run build`, then assert `js/totem-*.js` and their `.LICENSE.txt` files exist (never committed — the release packs them); `make test`; `make instance-up && make test-integration && make e2e`, then `make instance-clean`. A step you could not run is named in the PR, never reported as passed.
- **`src/` changed → `npm run build`**: `js/` is build output, ignored — nothing to commit. The release workflow builds the bundles before packing, and a PR fails whose build produces no bundle. Never commit `*.js.map` or `node_modules/`.
- **Never edit** `package-lock.json`, `composer.lock`, the dependency lists in `package.json`/`composer.json`, `js/tts/` by hand, or the aps-common copies `src/aps/`, `vendor/aps/common/src/`, `tests/stubs/OC/Hooks/Emitter.php` (`make aps-sync` rewrites them).
- **Strings:** what a person reads (screens, the sentences a refusal carries) is Spanish, written as literals; this app uses no `t()` / `$l->t()`. Code, comments, commits and PR text are English.
- **Staff pages use Nextcloud's tokens only:** no pixel font sizes and no literal colours in `src/staff`, `src/box`, `src/stats`, `src/admin` (`src/staff/design.test.js` enforces it); spacing in multiples of `--default-grid-baseline`. A new staff section is one line in `src/staff/sections.js` plus a component that renders `NcAppContent` and, if it has detail, `NcAppSidebar`.
- **Screens adapt:** the totem and the display size everything from one fitted unit and switch layout by aspect ratio; never write a fixed-pixel layout for them. The approved mockups are `docs/mockups/index.html` (totem, display, box, admin) and `docs/mockups/staff.html` (the staff app).
- **Voice assets:** `js/tts/` is committed (about 160 MB: the runtime and the two Piper models), so a release tarball is self-contained — it is the one `js/` subtree that ships as tracked bytes, beside the bundles the release builds (`docs/adr/0005`). Only `make tts-assets` (needs `npm ci`) changes it, and it verifies pinned sha256 values; never edit it by hand. `docs/mockups/voz/*.wav` are the two recordings the mockup plays.
- **One concern per PR**, with every change its description claims.

## Agent skills

### Issue tracker

GitHub Issues on `APS-Conecta/totem`, via the `gh` CLI. See `docs/agents/issue-tracker.md`.

### Triage labels

Canonical vocabulary, unmodified: `needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`,
`wontfix`. All five exist on the repo. See `docs/agents/triage-labels.md`.

### Domain docs

Single-context: [`CONTEXT.md`](CONTEXT.md) for the vocabulary, [`docs/adr/`](docs/adr/) for the
decisions. See `docs/agents/domain.md`.
