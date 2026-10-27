# Totem — glossary

Cross-repo ADR citations are `repo#ADR-NNNN` (`gestion#ADR-0013`); in-repo citations stay bare.

The domain's words, fixed so the code says what the team says. **On screen two of them read differently, because clinics name things differently: a Servicio is a _Unidad_ of the CESFAM (a farmacia is a unidad inside it, a vacunatorio too) and a Box is a _Ventanilla_.** Code, tables, routes and controller actions keep Servicio and Box; a name the admin types ("Box 3", "Ventanilla 2") is data. The terms are Spanish because the screens and the people who read them say them in Spanish; the code around them is English. _Avoid_ marks a term that must not appear.

**Servicio** (on screen: **Unidad**): a unit of the CESFAM with a queue a patient joins (Farmacia, Toma de Muestras, Vacunatorio, a sector…). Configured per instance, never seeded (`gestion#ADR-0013`); it has a name, a three-or-four-letter prefix, a color, opening hours and an optional daily quota. _Avoid_: cola, fila, módulo, and "servicio" on screen.

**Turno**: one place in a servicio's queue for one day, written `FAR-P-014`: prefix, `P` or `G`, a sequence. It carries no patient identity. _Avoid_: ticket (that is the paper), número, atención. One exception, the owner's wording: the statistics board and its CSV say "ticket" ("Tickets emitidos"); code, routes and every other screen keep Turno.

**Preferente**: the attention a patient asks for by answering SÍ to a single self-declared question (60 or more years old, a disability, or caring for a person with one; Ley 20.584 and Ley 21.168). It is never verified and no reason is stored. The other answer is **general**. _Avoid_: prioritario, urgente.

**Llamado**: one call of a turno to a box. A re-llamado is another llamado of the same turno: the display feed is append-only, and the recent list shows a turno once. _Avoid_: aviso (that is the sound).

**Tótem**: a kiosk where a patient takes a turno, with its own public address (`/apps/totem/totem/<slug>`; the plain `/apps/totem/totem` is the default kiosk). It lists the unidades it offers (none listed means all) and may have a network ticket printer: with one the server prints the ticket there, without one the kiosk's browser prints. _Avoid_: kiosko, terminal.

**Pantalla**: a waiting-room display. It lists the servicios it shows (none listed means all) and has its own volume and voice (Mujer, Hombre or none). _Avoid_: monitor, TV.

**Box** (on screen: **Ventanilla**): a desk, with the servicios it covers. A box may cover several; it then calls the candidate that has waited longest. _Avoid_: "box" on screen, módulo, puesto.

**Estado**: `waiting → called → in_service → completed`, or `called → no_show`. _Avoid_: status.

**No se presentó**: the called patient did not come. There is no editing of a turno: a wrong servicio or preferente means a new turno.

**Día**: every turno, quota and sequence is scoped to a calendar day in the instance's time zone; nothing special happens at the end of the day. A turno still waiting when its Día ends is never called again and counts as sin atender on that Día. A box keeps the turno it holds, whatever its Día, until it is completed or marked No se presentó.

**Sector**: a servicio (unidad) the admin flags "Es un sector" (a clinic's division, such as Sector Azul). The patient is never asked for one; the statistics board counts sectors in a block apart from the other servicios. _Avoid_: zona, equipo.

**Estadísticas**: the staff page of absolute counts of turnos for a day, a week (Monday to Sunday) or a month, by sector or servicio and by preferente or general, with how each ended (atendidos, no se presentó, sin atender). It counts turnos, never people (`docs/adr/0007`). _Avoid_: analítica, reporte.

**Aviso**: how a llamado is announced: two ding-dong chimes, then the voice twice, plus a gold frame on the display.
