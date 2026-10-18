# 0005 — The voice is a bundled open-source model run in the browser

**Status:** accepted. The spike ran (below); speed on the *actual* display hardware is still to be measured by whoever deploys one.

The display announces each llamado with chimes and a Spanish voice. The voice is Piper, run with `onnxruntime-web` and an eSpeak-ng WASM phonemizer, with two voices chosen by listening: **Hombre** `es_ES-davefx-medium` (CC0) and **Mujer** `es_MX-claude-high` (Apache-2.0, neutral Latin American Spanish). Each Pantalla loads only its own (~63 MB).

**Why not `speechSynthesis`.** It depends on the OS: a kiosk Linux often has no Spanish voice (a headless Chromium here reported none). The announcement is an accessibility feature, so it cannot depend on what happens to be installed.

**Licenses.** Piper is MIT, the two voices CC0 and Apache-2.0, eSpeak-ng GPL-3.0-or-later, which is compatible with this app's AGPL-3.0-or-later. Voices needing attribution or share-alike (Sharvard, Daniela) were rejected.

**Fallback chain.** Model fails to load → `speechSynthesis` → chimes only. The gold frame and the written caption never depend on sound.



## How it runs
A Web Worker (`src/tts/worker.js`) owns the model, `onnxruntime-web` (single thread, so no special response headers) and the espeak-ng phonemizer; the page only sends text and plays the PCM it gets back (`src/tts/piper.js`). The worker needs `worker-src 'self'`, which Nextcloud's default policy lacks, so `PageController` adds it on the display and admin pages. The voice is synthesized **while the chime rings** and every phrase is synthesized once (the second saying and any re-llamado reuse it). The model is loaded when the display opens, not at the first call.

## Spike results (this repo's dev machine, 4 cores, headless Chromium, single thread)
| Voice | Load (once) | Synthesis of "Turno Farmacia, preferente 12. Diríjase al Box 2." | Audio |
|---|---|---|---|
| Hombre (Davefx, medium) | ~3.2 s | 1.4–1.7 s | 3.5 s |
| Mujer (Claude, high) | ~5.9 s | 3.2–4.0 s | 4.2 s |

Mujer is about real time, so on a modest display PC the voice can start a few seconds after the chime ends (the chime lasts 2.2 s and synthesis overlaps it). If that is too slow on the real hardware: lower the pace, choose Hombre, or pre-render the phrases when servicio and box names are saved.

## Packaging
Runtime and models (~160 MB) are produced by `make tts-assets` into `js/tts/` and **are committed** (decided by the owner), so a `git archive` release is self-contained and the display needs no build step or internet. The script verifies the models against pinned sha256 values; re-run it only to upgrade them, and review the diff of any model change. Each file is under 100 MB, so plain git works (no Git LFS), at the cost of about 160 MB in the repository history. If the files are missing, the engine reports itself unavailable and the chain falls back to the browser voice, then to the chimes alone.

