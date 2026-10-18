# Totem — glossary

Cross-repo ADR citations are `repo#ADR-NNNN` (`gestion#ADR-0013`); in-repo citations stay bare.

The domain's words, fixed so the code says what the team says. The terms are Spanish because the screens and the people who read them say them in Spanish; the code around them is English. _Avoid_ marks a term that must not appear.

**Servicio**: a queue a patient joins (Farmacia, Toma de Muestras, Vacunatorio, a sector…). Configured per instance, never seeded (`gestion#ADR-0013`); it has a name, a three-or-four-letter prefix, a color, opening hours and an optional daily quota. _Avoid_: cola, fila, módulo.

**Turno**: one place in a servicio's queue for one day, written `FAR-P-014`: prefix, `P` or `G`, a sequence. It carries no patient identity. _Avoid_: ticket (that is the paper), número, atención.

**Preferente**: the attention a patient asks for by answering SÍ to a single self-declared question (60 or more years old, a disability, or caring for a person with one; Ley 20.584 and Ley 21.168). It is never verified and no reason is stored. The other answer is **general**. _Avoid_: prioritario, urgente.

**Llamado**: one call of a turno to a box. A re-llamado is another llamado of the same turno: the display feed is append-only, and the recent list shows a turno once. _Avoid_: aviso (that is the sound).

**Pantalla**: a waiting-room display. It lists the servicios it shows (none listed means all) and has its own volume and voice (Mujer, Hombre or none). _Avoid_: monitor, TV.

**Box**: a desk, with the servicios it covers. A box may cover several; it then calls the candidate that has waited longest. _Avoid_: ventanilla, módulo, puesto.

**Estado**: `waiting → called → in_service → completed`, or `called → no_show`. _Avoid_: status.

**No se presentó**: the called patient did not come. There is no editing of a turno: a wrong servicio or preferente means a new turno.

**Día**: every turno, quota and sequence is scoped to a calendar day in the instance's time zone; nothing special happens at the end of the day.

**Aviso**: how a llamado is announced: two ding-dong chimes, then the voice twice, plus a gold frame on the display.
