# Threat model — Totem

## What is protected
Nothing about a patient: a turno is a servicio, a preferente flag, a sequence and timestamps. There is no RUN, name, phone or reason. That is the point of the design (ADR-0004) and it removes the confidentiality, retention and breach questions an identified queue would raise.

## Surfaces
| Surface | Who | Guard |
|---|---|---|
| Totem page and `POST /turnos` | anyone on the clinic network (the kiosk) | opening hours, daily quota, generous `#[AnonRateLimit]` |
| Display page and `GET /display/feed` | anyone (the room's screen) | read-only, no personal data; `#[AnonRateLimit]` |
| Box page and call/transition endpoints | logged-in Nextcloud users | session, CSRF token, `UserRateLimit` |
| Admin page and config endpoints | Nextcloud admins | Nextcloud's admin guard |

## The real risk: abuse of anonymous issuing
Without an identity there is no duplicate-turno check. Someone can tap repeatedly and fill the queue with turnos nobody holds, delaying real patients. Bounds, in order: the servicio's opening hours; its daily quota; a per-IP rate limit. All kiosks share one IP, so the limit must be generous; it stops a script, not a determined person at the kiosk. Staff clear ghost turnos with "No se presentó". If this is not enough in practice, add a per-kiosk cooldown (an option, not built).

## Preferente is self-declared
It is never verified and no reason is stored, by design (Ley 20.584 / 21.168 intent; verification at a self-service totem would defeat it). Abuse shows up in analytics (slice 2), not in code.

## Other
- The display feed carries labels, servicio names, box names and colors only.
- The bundled voice model runs in the browser; no text leaves the machine.
- `totem_llamado` is append-only: the display feed and the audit trail.
