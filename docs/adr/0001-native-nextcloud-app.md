# 0001 — A native Nextcloud app, not FastAPI or an ExApp

**Status:** accepted

The spec proposed FastAPI + SQLAlchemy behind a Nextcloud shell. We build a native PHP app like `farmacia` and `territorio`.

**Why.** FastAPI means a second datastore, a second codebase, a JWT bridge and a deploy daemon. An ExApp keeps most of that and adds limits: AppAPI's PHP proxy has no websockets and no documented SSE, HTML responses are buffered, PUBLIC routes reach an ExApp with a null user, and the deploy daemon (DSP) is deprecated in NC 35. A native app gets sessions, CSRF, rate limits, the admin guard, theming and migrations for free and ships in the same tarball as the rest of APS Conecta.

**Consequence.** Everything is PHP + Vue inside Nextcloud; one establishment per instance (`gestion#ADR-0013`).
