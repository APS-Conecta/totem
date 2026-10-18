# 0002 — The display polls every 2 s; no SSE or websockets

**Status:** accepted

`GET /display/feed?screen=<slug>&since=<llamado id>` returns the llamados after a cursor. The display asks every 2 seconds, with backoff when the server is unreachable.

**Why.** A call is announced within about two seconds, which is fine for a waiting room. PHP-FPM holds a worker per open SSE connection, and the ExApp proxy path cannot carry streams. A cursor over the append-only `totem_llamado` table is stateless and survives reloads and reconnects.
