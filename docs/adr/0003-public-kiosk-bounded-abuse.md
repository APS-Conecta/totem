# 0003 — Anonymous totem and display, bounded by hours, quota and rate limit

**Status:** accepted

The totem and the display are public pages (`#[PublicPage] #[NoCSRFRequired]`, `#[AnonRateLimit]`); the box needs a login and the admin needs a Nextcloud admin.

**Why.** A kiosk has no user to log in, and a patient must not need an account. The cost is that issuing turnos can be abused; `docs/threat-model.md` states the bounds. All kiosks share one IP, so the rate limit is deliberately generous.
