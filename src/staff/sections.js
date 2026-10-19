// SPDX-License-Identifier: AGPL-3.0-or-later

// The sections of the staff app, in the order the menu shows them. The menu and the content both read this
// list, so a section is added in one place. Names omit what the menu already says (the groups).

export const GROUPS = ['Operación', 'Configuración']

export const SECTIONS = [
	{ id: 'box', group: 'Operación', name: 'Box' },
	{ id: 'estadisticas', group: 'Operación', name: 'Estadísticas' },
	{ id: 'servicios', group: 'Configuración', name: 'Servicios' },
	{ id: 'boxes', group: 'Configuración', name: 'Boxes' },
	{ id: 'pantallas', group: 'Configuración', name: 'Pantallas' },
]
