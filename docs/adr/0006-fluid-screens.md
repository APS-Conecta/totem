# 0006 — Screens adapt to any size and orientation

**Status:** accepted

The screen hardware is unknown. The totem and the display size everything from one unit `--k` (one design pixel, fitted to the container with container-query units) and switch layout on aspect ratio: portrait is one column, landscape is two. Box and admin are ordinary responsive pages. Name lengths are capped (servicio 28, box 14, prefix 4) because the layouts are sized for them.

**Why.** A fixed 1080×1920 or 1920×1080 design would be wrong on most real screens. The end-to-end audit (`e2e/responsive.spec.js`) proves no text leaves the screen at the tested sizes.
