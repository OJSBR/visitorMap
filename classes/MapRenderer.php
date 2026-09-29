<?php

/**
 * @file plugins/blocks/visitorMap/classes/MapRenderer.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class MapRenderer
 *
 * @brief Draws the world map as an SVG, each country shaded by its accesses.
 *
 *        The outlines are the ones in data/world.json (Natural Earth, public
 *        domain, Equal Earth projection), built by tools/build-world.py. The
 *        shading uses five classes on a logarithmic scale: the accesses of a
 *        journal are concentrated in a handful of countries, and a linear scale
 *        would leave all the others looking empty. Countries too small for the
 *        outlines get a dot, drawn only when they have accesses.
 *
 *        The SVG carries no text, so a single file serves every language.
 */

namespace APP\plugins\blocks\visitorMap\classes;

use RuntimeException;

class MapRenderer
{
    public const CLASSES = 5;

    /** @var ?array{width: float, height: float, countries: array<string,string>, tiny: array<string,float[]>} */
    private static ?array $world = null;

    public function __construct(private string $landColor, private string $highlightColor)
    {
    }

    /** The outlines, read once per process. */
    public static function world(): array
    {
        if (self::$world === null) {
            $world = json_decode((string) file_get_contents(dirname(__DIR__) . '/data/world.json'), true);
            if (!is_array($world) || empty($world['countries'])) {
                throw new RuntimeException('visitorMap: data/world.json is missing or damaged');
            }
            self::$world = $world;
        }

        return self::$world;
    }

    /**
     * The class of a number of accesses, from 1 to CLASSES, on a logarithmic
     * scale that ends at the largest number.
     */
    public static function classOf(int $value, int $max): int
    {
        if ($value <= 0) {
            return 0;
        }
        if ($max <= 1) {
            return self::CLASSES;
        }

        return max(1, min(self::CLASSES, 1 + (int) floor(self::CLASSES * log($value) / log($max))));
    }

    /**
     * The smallest number of accesses of each class, for the legend.
     *
     * @return int[] One entry per class, first class first.
     */
    public static function thresholds(int $max): array
    {
        $thresholds = [];
        for ($class = 1; $class <= self::CLASSES; $class++) {
            $thresholds[] = $max <= 1 ? 1 : (int) ceil($max ** (($class - 1) / self::CLASSES));
        }

        return $thresholds;
    }

    /**
     * The colours of the classes, from a light tint of the highlight colour to
     * the highlight colour itself.
     *
     * @return string[] Index 0 is the land without accesses.
     */
    public function palette(): array
    {
        $palette = [$this->landColor];
        $to = self::rgb($this->highlightColor);
        $from = array_map(fn ($channel) => (int) round($channel + (255 - $channel) * 0.78), $to);
        for ($class = 1; $class <= self::CLASSES; $class++) {
            $share = ($class - 1) / (self::CLASSES - 1);
            $palette[] = sprintf(
                '#%02x%02x%02x',
                ...array_map(fn ($a, $b) => (int) round($a + ($b - $a) * $share), $from, $to)
            );
        }

        return $palette;
    }

    /**
     * @param array<string,int> $totals Accesses by ISO 3166-1 alpha-2 code.
     */
    public function render(array $totals): string
    {
        $world = self::world();
        $max = $totals ? max($totals) : 0;
        $palette = $this->palette();

        $style = 'path{stroke:#fff;stroke-width:.35;stroke-linejoin:round}circle{stroke:#fff;stroke-width:.6}';
        foreach ($palette as $class => $color) {
            $style .= ".c{$class}{fill:{$color}}";
        }

        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$s %2$s" width="%1$s" height="%2$s"><style>%3$s</style>',
            $world['width'],
            $world['height'],
            $style
        );

        foreach ($world['countries'] as $code => $path) {
            $svg .= sprintf('<path class="c%d" d="%s"/>', self::classOf((int) ($totals[$code] ?? 0), $max), $path);
        }
        foreach ($world['tiny'] as $code => [$x, $y]) {
            $class = self::classOf((int) ($totals[$code] ?? 0), $max);
            if ($class > 0) {
                $svg .= sprintf('<circle class="c%d" cx="%s" cy="%s" r="3.2"/>', $class, $x, $y);
            }
        }

        return $svg . '</svg>';
    }

    /** Whether the map can show a country, as an outline or as a dot. */
    public static function draws(string $code): bool
    {
        $world = self::world();

        return isset($world['countries'][$code]) || isset($world['tiny'][$code]);
    }

    /** @return int[] */
    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }
}
