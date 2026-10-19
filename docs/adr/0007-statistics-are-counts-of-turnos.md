# 0007 — Statistics are absolute counts of turnos

**Status:** accepted

The Estadísticas page (`/apps/totem/estadisticas`) shows, for a day, a Monday-to-Sunday week or a calendar month in the instance's time zone: how many turnos were issued, split by servicio, by sector and by preferente or general, and how each one ended (atendidos, no se presentó, sin atender). Whole numbers only: no averages, waiting times, percentages or charts.

- **Turnos, not people.** A turno carries no identity (ADR-0004), so one person who takes two turnos counts twice. The board says so on screen. The owner asked for the word "ticket" on this board and its CSV ("Tickets emitidos"); everywhere else the term stays Turno (`CONTEXT.md`).
- **A sector is a servicio.** A servicio has an "Es un sector" flag (set by hand by the admin); the board counts sectors in one block and the other servicios in another. The patient is never asked for a sector, so the totem still takes two taps. Sectors are not read from `territorio` or from the installer's `sector-*` groups: their ids are install-local, and Totem seeds no clinic names (`gestion#ADR-0013`).
- **Every turno ends in exactly one outcome.** `emitidos = atendidos + no se presentó + sin atender`, where sin atender is whatever was still waiting, called or in service. This holds in every figure on the page (a unit test pins it).
- **Computed on request, in SQL.** One grouped `COUNT` over `totem_turno` by (day, servicio, preferente, estado), folded in PHP by `Domain/Tally`. No summary table, no job, nothing to keep in sync; the existing day-scoped indexes serve it.
- **Exact numbers, no suppression.** There is no identity to re-identify and the org has no minimum-cell rule; hiding small cells would make the board disagree with the paper count. Revisit if analytics ever gains an identity.
- **Open to any logged-in user**, like the box console. The counts hold no personal data, and a clinic manager is rarely a Nextcloud admin.
- **CSV.** `GET /stats/csv` downloads the detail of the period, one line per day, servicio and atención, of which every figure on screen is a sum. Days without turnos have no line. Same dialect as farmacia: `;` between cells, a BOM for Excel, RFC 4180 quoting written by hand, names defused against spreadsheet formulas (`APS\Common\Csv::text`), LF line ends. aps-common has no CSV writer, so `Service/StatsCsv` is Totem's own; if a third app needs one, promote it there.
- **Inactive servicios** stay in the board when they have counts in the period (a servicio is switched off, never deleted), and a renamed servicio shows its current name on past days.
