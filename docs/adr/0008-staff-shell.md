# 0008 — One staff app: a menu, a section and a sidebar

**Status:** accepted

The staff side was three separate pages (box, statistics, administration) with no shared menu: the administration page was reachable only by typing its address. It is now **one Nextcloud app**, laid out the way `farmacia` (the org's declared template) and `epidemiologia` lay theirs out: Nextcloud's navigation on the left, the section in the middle, the sidebar on the right.

- **The kiosk and the display stay apart.** They are public, chromeless screens with their own fitted-unit layout (ADR-0006); the staff app has Nextcloud's chrome. The `Abrir tótem` link in the menu's footer and `Abrir pantalla` on every pantalla open them in a new tab.
- **Menu.** Two labelled groups, built from one registry (`src/staff/sections.js`): *Operación* (Ventanilla, Estadísticas) and *Configuración* (Unidades, Ventanillas, Pantallas). The groups are lists labelled by their captions, not headings, so the menu adds no heading level (epidemiologia's ADR-0005). The menu is the same for everyone.
- **Addresses.** `/apps/totem/box`, `/estadisticas` and `/admin` still exist and each opens its section (the three routes render one `staff` page and bundle; no route is renamed). The three configuration sections share `/admin` and tell themselves apart by the `#` (`#servicios`, `#boxes`, `#pantallas`). Switching section changes the address with `history.pushState` and the back button returns; a click with a modifier key is left to the browser (a new tab). There is no router library: the section is one piece of state (`src/staff/location.js`).
- **The sidebar shows the detail of what is selected.** Ventanilla: the waiting list (open by itself where there is room, on request on a phone). Estadísticas: the full split of the selected unidad or day. Unidades, Ventanillas, Pantallas: the edit form of the selected record, with `ConfigSection` holding the chrome the three lists share.
- **Configuración is open to every logged-in user**, by the owner's decision ("they can modify as needed"): the configuration endpoints and the `/admin` page are `#[NoAdminRequired]`, with `UserRateLimit` and the CSRF token kept on writes. Any account on the instance can therefore change opening hours, quotas and which unidades exist, and so affect the kiosk. If that proves too wide, the tightening is a group check on those endpoints (not built).
- **Vocabulary on screen.** A Servicio is a *Unidad* of the CESFAM (a farmacia is a unidad inside it, and so is a vacunatorio) and a Box is a *Ventanilla*; both feminine, so the sentences agree ("Nueva unidad", "Esta ventanilla", "Activa", "Ninguna unidad está marcada"). The spoken call drops the article ("Diríjase a Ventanilla 2."). Code, tables, routes and controller actions keep Servicio and Box; names an admin types are data.

## Look

The staff pages are ordinary Nextcloud pages and take everything from Nextcloud's variables, so theming and dark mode need no code. A test (`src/staff/design.test.js`) reads every staff style and refuses a pixel font size or a literal colour. Spacing is a multiple of `--default-grid-baseline`; the content is at most 1100px wide, and its left inset always clears the navigation toggle Nextcloud floats over the content's corner.

Control size: Nextcloud's standard control is **34px** (the mockup had assumed 44). A mouse keeps it so the app looks like the rest of Nextcloud; on a touch screen (`pointer: coarse`) the whole app switches to the platform's large size, **48px**, through one variable. The end-to-end audit reads the real token and checks both.

## Not done

A router library, period and filter state in the address, a group restriction for Configuración, and the brand fonts of the APS theme (the instance's theme provides them). `vue-material-design-icons` was added for the menu icons, as `farmacia` and `epidemiologia` do.
