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

	public function testTheMenuEntryPointsAtARealRoute(): void {
		$info = simplexml_load_file(self::APP_DIR . '/appinfo/info.xml');
		$route = (string)$info->navigations->navigation->route;
		[$app, $controller, $action] = explode('.', $route);
		$names = array_column((require self::APP_DIR . '/appinfo/routes.php')['routes'], 'name');

		$this->assertSame('totem', $app);
		$this->assertContains("$controller#$action", $names);
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

	public function testEveryPageHasItsTemplateAndItsBundleEntry(): void {
		foreach (['totem', 'display', 'box', 'admin'] as $page) {
			$this->assertFileExists(self::APP_DIR . "/templates/$page.php");
			$this->assertFileExists(self::APP_DIR . "/src/$page/main.js", "webpack.config.js builds src/$page/main.js");
		}
	}
}
