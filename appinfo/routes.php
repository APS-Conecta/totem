<?php

declare(strict_types=1);

// Who may call what is pinned by tests/unit/Controller/SurfaceTest.php against one
// allow-list: the kiosk and the display are public, the console is for any
// logged-in user, everything else is a Nextcloud admin's.
return [
	'routes' => [
		// Pages.
		['name' => 'page#totem', 'url' => '/totem', 'verb' => 'GET'],
		['name' => 'page#display', 'url' => '/display/{slug}', 'verb' => 'GET'],
		['name' => 'page#box', 'url' => '/box', 'verb' => 'GET'],
		['name' => 'page#admin', 'url' => '/admin', 'verb' => 'GET'],
		['name' => 'page#stats', 'url' => '/estadisticas', 'verb' => 'GET'],

		// The kiosk (public).
		['name' => 'turno#servicios', 'url' => '/public/servicios', 'verb' => 'GET'],
		['name' => 'turno#create', 'url' => '/turnos', 'verb' => 'POST'],

		// The waiting-room display (public). `since` travels as a query parameter.
		['name' => 'display#feed', 'url' => '/display/{slug}/feed', 'verb' => 'GET'],

		// The box console (logged-in users).
		['name' => 'call#boxes', 'url' => '/console/boxes', 'verb' => 'GET'],
		['name' => 'call#state', 'url' => '/console/{boxId}/state', 'verb' => 'GET', 'requirements' => ['boxId' => '\d+']],
		['name' => 'call#call', 'url' => '/console/{boxId}/call', 'verb' => 'POST', 'requirements' => ['boxId' => '\d+']],
		['name' => 'call#recall', 'url' => '/console/{boxId}/recall', 'verb' => 'POST', 'requirements' => ['boxId' => '\d+']],
		['name' => 'call#start', 'url' => '/console/{boxId}/start', 'verb' => 'POST', 'requirements' => ['boxId' => '\d+']],
		['name' => 'call#finish', 'url' => '/console/{boxId}/finish', 'verb' => 'POST', 'requirements' => ['boxId' => '\d+']],
		['name' => 'call#absent', 'url' => '/console/{boxId}/absent', 'verb' => 'POST', 'requirements' => ['boxId' => '\d+']],

		// The statistics board (logged-in users). `kind` and `date` travel as query parameters.
		['name' => 'stats#board', 'url' => '/stats', 'verb' => 'GET'],
		['name' => 'stats#csv', 'url' => '/stats/csv', 'verb' => 'GET'],

		// Configuration (Nextcloud admins).
		['name' => 'servicio#index', 'url' => '/servicios', 'verb' => 'GET'],
		['name' => 'servicio#create', 'url' => '/servicios', 'verb' => 'POST'],
		['name' => 'servicio#update', 'url' => '/servicios/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'box#index', 'url' => '/boxes', 'verb' => 'GET'],
		['name' => 'box#create', 'url' => '/boxes', 'verb' => 'POST'],
		['name' => 'box#update', 'url' => '/boxes/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'box#destroy', 'url' => '/boxes/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],
		['name' => 'pantalla#index', 'url' => '/pantallas', 'verb' => 'GET'],
		['name' => 'pantalla#create', 'url' => '/pantallas', 'verb' => 'POST'],
		['name' => 'pantalla#update', 'url' => '/pantallas/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'pantalla#destroy', 'url' => '/pantallas/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],
	],
];
