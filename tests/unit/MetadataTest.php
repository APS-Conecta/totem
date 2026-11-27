<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

/**
 * The app's manifest and its icon pair are contracts, not decoration: an info.xml
 * that disagrees with the code fails at install time, and an SVG that does not
 * parse is served with a 200 and renders nothing (farmacia's AppIconTest rules).
 */
class MetadataTest extends TestCase {
	private const APP_DIR = __DIR__ . '/../..';

	public function testTheManifestAgreesWithTheCode(): void {
		$info = simplexml_load_file(self::APP_DIR . '/appinfo/info.xml');
		$this->assertInstanceOf(SimpleXMLElement::class, $info);

		$this->assertSame('totem', (string)$info->id);
		$this->assertSame('Totem', (string)$info->namespace);
		$this->assertSame('AGPL-3.0-or-later', (string)$info->licence);
		$this->assertSame('34', (string)$info->dependencies->nextcloud['min-version']);
		$this->assertTrue(class_exists('OCA\\Totem\\AppInfo\\Application'));
	}

	public function testEveryMenuEntryPointsAtARealRoute(): void {
		$info = simplexml_load_file(self::APP_DIR . '/appinfo/info.xml');
		$names = array_column((require self::APP_DIR . '/appinfo/routes.php')['routes'], 'name');

		$entries = [];
		foreach ($info->navigations->navigation as $navigation) {
			$entries[(string)$navigation->name] = (string)$navigation->route;
			[$app, $controller, $action] = explode('.', (string)$navigation->route);
			$this->assertSame('totem', $app);
			$this->assertContains("$controller#$action", $names);
		}
		$this->assertSame(['Totem' => 'totem.page.box'], $entries);
	}

	public function testEveryEndpointTheDocsCiteIsARoute(): void {
		$routes = array_map(fn (array $route): string => $route['verb'] . ' ' . $route['url'], (require self::APP_DIR . '/appinfo/routes.php')['routes']);

		$unknown = [];
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::APP_DIR . '/docs', \FilesystemIterator::SKIP_DOTS));
		foreach ($files as $file) {
			if ($file->getExtension() !== 'md') {
				continue;
			}
			foreach (self::citedEndpoints((string)file_get_contents($file->getPathname())) as $endpoint) {
				if (!in_array($endpoint, $routes, true)) {
					$unknown[] = substr($file->getPathname(), strlen(self::APP_DIR . '/')) . ": $endpoint";
				}
			}
		}
		$this->assertSame([], $unknown, 'every backticked endpoint in docs/ must be a verb and url of appinfo/routes.php, letter for letter');
	}

	/** @return array<string, array{string, list<string>}> */
	public static function citations(): array {
		return [
			'the query string is dropped' => ['`GET /display/{slug}/feed?since=<llamado id>`', ['GET /display/{slug}/feed']],
			'two on one line' => ['`GET /stats`, `GET /stats/csv`', ['GET /stats', 'GET /stats/csv']],
			'every verb' => ['`POST /a` `PUT /b/{id}` `DELETE /c/{id}`', ['POST /a', 'PUT /b/{id}', 'DELETE /c/{id}']],
			'a path without a verb' => ['(`/box`, `/admin`)', []],
			'a verb outside backticks' => ['GET /stats returns the board', []],
			'empty input' => ['', []],
		];
	}

	/**
	 * @dataProvider citations
	 * @param list<string> $expected
	 */
	public function testTheCitationReaderFindsEachBacktickedEndpoint(string $markdown, array $expected): void {
		$this->assertSame($expected, self::citedEndpoints($markdown));
	}

	/**
	 * Each backticked `VERB /path` in the text, as "VERB /path" without its query string.
	 *
	 * @return list<string>
	 */
	private static function citedEndpoints(string $markdown): array {
		preg_match_all('/`(GET|POST|PUT|DELETE) (\/[^`?\s]*)[^`]*`/', $markdown, $matches, PREG_SET_ORDER);
		return array_map(fn (array $match): string => "$match[1] $match[2]", $matches);
	}

	/** @return array<string, array{string, string}> */
	public static function icons(): array {
		return ['app.svg on dark surfaces' => ['app.svg', '#fff'], 'app-dark.svg on light surfaces' => ['app-dark.svg', '#000']];
	}

	/** @dataProvider icons */
	public function testIconIsASingleTintGlyphNamingItsSurface(string $file, string $tint): void {
		$path = self::APP_DIR . '/img/' . $file;
		$this->assertFileExists($path);
		$xml = @simplexml_load_file($path);
		$this->assertInstanceOf(SimpleXMLElement::class, $xml, "$file must parse as XML");
		$this->assertSame('svg', $xml->getName());
		$source = (string)file_get_contents($path);
		$this->assertStringContainsString('Surface:', $source);
		preg_match_all('/fill="([^"]+)"/', $source, $fills);
		$this->assertNotEmpty($fills[1]);
		foreach ($fills[1] as $fill) {
			$this->assertSame($tint, $fill, "$file must be single-colour");
		}
	}

	public function testNoPageShowsAMessageWhileItsAppLoads(): void {
		foreach (['totem', 'display', 'staff'] as $page) {
			$markup = trim((string)preg_replace('/<\?php.*?\?>/s', '', (string)file_get_contents(self::APP_DIR . "/templates/$page.php")));

			$this->assertSame(sprintf('<div id="totem-%s"></div>', $page), $markup, "$page must ship an empty mount point, nothing a patient could read");
		}
	}

	public function testEveryPageHasItsTemplateAndItsBundleEntry(): void {
		foreach (['totem', 'display', 'staff'] as $page) {
			$this->assertFileExists(self::APP_DIR . "/templates/$page.php");
			$this->assertFileExists(self::APP_DIR . "/src/$page/main.js", "webpack.config.js builds src/$page/main.js");
		}
	}
}
