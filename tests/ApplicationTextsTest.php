<?php

/**
 * @file plugins/blocks/visitorMap/tests/ApplicationTextsTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ApplicationTextsTest
 *
 * @brief The texts of the plugin on OJS and on OMP. The plugin's own locale
 *        names the journal, its articles and OJS; on OMP, locale-omp names the
 *        press, its books and OMP instead, for the same keys and nothing else.
 *        That the running application gets its own texts is checked in
 *        BlockTest, where the plugin is registered within a context.
 */

namespace APP\plugins\blocks\visitorMap\tests;

use PKP\tests\PKPTestCase;

class ApplicationTextsTest extends PKPTestCase
{
    /** The keys whose text names the application. */
    public const KEYS = [
        'description', 'alt', 'notice.geoDisabled', 'notice.noData', 'notice.emptyRange', 'settings.intro',
        'settings.startDate.description', 'settings.metric.description', 'settings.antiScraper.description',
    ];

    private const PREFIX = 'plugins.blocks.visitorMap.';

    private function entries(string $file): array
    {
        preg_match_all('~^msgid "([^"]+)"\nmsgstr "((?:[^"\\\\]|\\\\.)*)"~m', file_get_contents($file), $m, PREG_SET_ORDER);

        return array_column($m, 2, 1);
    }

    private function root(): string
    {
        return dirname(__DIR__);
    }

    public function testEveryLanguageHasItsOmpTexts(): void
    {
        $languages = array_map('basename', glob($this->root() . '/locale/*', GLOB_ONLYDIR));
        $omp = array_map('basename', glob($this->root() . '/locale-omp/*', GLOB_ONLYDIR));
        sort($languages);
        sort($omp);

        $this->assertSame($languages, $omp);
    }

    public function testTheOmpTextsReplaceTheSameKeysAndNothingElse(): void
    {
        $expected = array_map(fn ($key) => self::PREFIX . $key, self::KEYS);
        foreach (glob($this->root() . '/locale-omp/*/locale.po') as $file) {
            $code = basename(dirname($file));
            $omp = $this->entries($file);
            $own = $this->entries($this->root() . '/locale/' . $code . '/locale.po');

            $this->assertSame($expected, array_keys($omp), $code);
            foreach ($omp as $key => $text) {
                $this->assertArrayHasKey($key, $own, "{$code}: {$key} exists in the plugin's own locale");
                $this->assertStringNotContainsString('OJS', $text, "{$code}: {$key}");
                // Placeholders stay what the code fills in.
                preg_match_all('~\{\$\w+\}~', $own[$key], $a);
                preg_match_all('~\{\$\w+\}~', $text, $b);
                $this->assertSame($a[0], $b[0], "{$code}: placeholders of {$key}");
                if (str_contains($own[$key], 'OJS')) {
                    $this->assertStringContainsString('OMP', $text, "{$code}: {$key} names OMP where the own text names OJS");
                }
            }
        }
    }
}
