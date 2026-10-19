# 0009 — The server prints the ticket, at a printer chosen per totem

**Status:** accepted

A web page cannot choose a printer: the browser prints to whatever default printer its machine has. To let the configuration say where each totem's ticket goes, **the Nextcloud server prints**.

- **A totem is a thing you configure.** Like a Pantalla, a *Tótem* (a kiosk) has a name, its own public address (`/apps/totem/totem/<slug>`, fixed at creation), the unidades it offers (none ticked = all) and, optionally, a network printer. The plain `/apps/totem/totem` stays as the default kiosk: every unidad, browser printing.
- **ESC/POS over TCP.** The printer is an 80 mm thermal ticket printer (Epson, Star in ESC/POS mode, most generic ones). The server opens `tcp://host:port` (default 9100) and writes the ticket as text: the establishment, the label as big as the paper allows, the unidad, "ATENCIÓN PREFERENTE" when it applies, the moment, what to do next, and a cut. No driver and no library. Text goes out in code page 850 so Spanish letters print; letters the printer lacks become `?`; control characters are stripped from every field so a name cannot talk to the printer (`Domain/EscPosTicket`).
- **A broken printer never stops the queue.** The turno is stored first; printing is a side effect that cannot throw (`Service/TicketPrinter`). The answer carries `printed`: `true`, `false` (the printer did not answer: the kiosk says so and sends the patient to SOME) or `null` (no network printer: the kiosk's browser prints, as before). The last result and its time are kept on the totem so staff see a printer that stopped answering, and "Probar impresión" prints a sample ticket.
- **Bounded connections.** One second to connect and two to write: a printer that is off costs the patient a second, not a minute.
- **Where a printer may be** (`Domain/PrinterAddress`): a private IPv4 address (10/8, 172.16/12, 192.168/16) or a name that resolves only to such addresses, on a JetDirect port (9100–9109). The rule runs when the totem is saved and again at every print, because a name can be re-pointed after it was configured. See the threat model.
- **Kiosk side.** A totem that lists unidades refuses a ticket for another one. With a network printer the kiosk no longer calls `window.print()` (no double ticket); without one it prints through the browser exactly as it always did.

## Not done

IPP/CUPS and USB printers, reprinting a ticket, one printer shared by several totems, a barcode or QR on the paper, paper-out detection, and IPv6 or public printer addresses. No real printer was available while building this: the byte stream is proven against a stand-in printer (`e2e/fake-printer.cjs`, the `printer` service of `compose.dev.yaml`) and the real TCP transport, not against hardware.
