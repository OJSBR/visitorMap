<?php

/**
 * @file plugins/blocks/visitorMap/tests/MapRendererTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class MapRendererTest
 *
 * @brief The outlines shipped with the plugin, the colour scale and the SVG.
 */

namespace APP\plugins\blocks\visitorMap\tests;

use APP\plugins\blocks\visitorMap\classes\MapRenderer;
use PKP\facades\Locale;
use PKP\tests\PKPTestCase;

class MapRendererTest extends PKPTestCase
{
    public function testTheOutlinesAreComplete(): void
    {
        $world = MapRenderer::world();

        $this->assertGreaterThan(170, count($world['countries']));
        foreach (['BR', 'PT', 'US', 'FR', 'NO', 'AO', 'MZ', 'CN', 'IN', 'RU', 'AU', 'XK'] as $code) {
            $this->assertArrayHasKey($code, $world['countries'], "{$code} has no outline");
        }
        foreach (['SG', 'MT', 'HK', 'CV', 'BH'] as $code) {
            $this->assertTrue(MapRenderer::draws($code), "{$code} is too small for the outlines and needs a dot");
        }
        $this->assertArrayNotHasKey('AQ', $world['countries'], 'Antarctica is left out');

        $countries = Locale::getCountries();
        foreach (array_merge(array_keys($world['countries']), array_keys($world['tiny'])) as $code) {
            $this->assertMatchesRegularExpression('/^[A-Z]{2}$/', $code);
            // Kosovo is reported by the geolocation database under XK, a code the
            // list of countries of the application does not have.
            $this->assertTrue($code === 'XK' || $countries->getByAlpha2($code) !== null, "{$code} is not a country OJS knows");
        }
        foreach ($world['countries'] as $code => $path) {
            $this->assertMatchesRegularExpression('/^(M[-\d. ]+(l[-\d. ]+)*z)+$/', $path, "the outline of {$code} is not plain path data");
        }
    }

    public function testTheScaleIsLogarithmic(): void
    {
        $this->assertSame(0, MapRenderer::classOf(0, 1000));
        $this->assertSame(1, MapRenderer::classOf(1, 1000));
        $this->assertSame(5, MapRenderer::classOf(1000, 1000));
        $this->assertSame(3, MapRenderer::classOf(31, 1000), '31 is past 1000^(2/5)');
        $this->assertSame(5, MapRenderer::classOf(1, 1), 'a single country with accesses gets the strongest colour');
        $this->assertSame([1, 4, 16, 64, 252], MapRenderer::thresholds(1000));
    }

    public function testThePaletteGoesFromATintToTheHighlight(): void
    {
        $palette = (new MapRenderer('#dde1e6', '#1f5fbf'))->palette();

        $this->assertCount(MapRenderer::CLASSES + 1, $palette);
        $this->assertSame('#dde1e6', $palette[0]);
        $this->assertSame('#1f5fbf', $palette[MapRenderer::CLASSES]);
        foreach ($palette as $color) {
            $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $color);
        }
    }

    public function testTheMapShadesTheCountriesWithAccesses(): void
    {
        $svg = (new MapRenderer('#dde1e6', '#1f5fbf'))->render(['BR' => 1000, 'PT' => 31, 'SG' => 1]);
        $xml = simplexml_load_string($svg);

        $this->assertNotFalse($xml, 'the SVG is not well-formed');
        $world = MapRenderer::world();
        $this->assertSame((string) $world['width'], (string) $xml['width']);
        $this->assertStringContainsString('<path class="c5" d="' . $world['countries']['BR'] . '"/>', $svg);
        $this->assertStringContainsString('<path class="c3" d="' . $world['countries']['PT'] . '"/>', $svg);
        $this->assertStringContainsString('<path class="c0" d="' . $world['countries']['US'] . '"/>', $svg);
        $this->assertSame(1, substr_count($svg, '<circle'), 'only the small countries with accesses get a dot');
        $this->assertStringContainsString(sprintf('cx="%s" cy="%s"', ...$world['tiny']['SG']), $svg);
        $this->assertStringNotContainsString('<text', $svg, 'the map carries no text, so one file serves every language');
    }
}
