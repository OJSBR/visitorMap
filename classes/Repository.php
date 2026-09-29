<?php

/**
 * @file plugins/blocks/visitorMap/classes/Repository.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class Repository
 *
 * @brief Accesses of a journal by country over a range of days, read from the
 *        plugin's tables.
 *
 *        Days come from the daily table. Whole months that lie inside the range
 *        and predate the daily data come from the monthly one; a month the
 *        range only partly covers is left out rather than counted in full.
 */

namespace APP\plugins\blocks\visitorMap\classes;

use APP\plugins\blocks\visitorMap\classes\migration\VisitorMapMigration;
use Illuminate\Support\Facades\DB;

class Repository
{
    /**
     * @param string $from First day, YYYY-MM-DD, or null for the first day there is data.
     * @param string $to Last day, YYYY-MM-DD.
     * @param bool $unique Unique accesses (true) or all accesses (false).
     * @param string[] $excluded Countries left out, ISO 3166-1 alpha-2.
     *
     * @return array<string,int> Accesses by country, largest first. Countries without accesses are absent.
     */
    public function byCountry(int $contextId, ?string $from, string $to, bool $unique, array $excluded = []): array
    {
        $column = $unique ? 'metric_unique' : 'metric';
        $totals = [];

        $daily = DB::table(VisitorMapMigration::TABLE_DAILY)
            ->select('country', DB::raw("SUM({$column}) AS total"))
            ->where('context_id', '=', $contextId)
            ->where('date', '<=', $to)
            ->when($from !== null, fn ($query) => $query->where('date', '>=', $from))
            ->groupBy('country')
            ->get();
        foreach ($daily as $row) {
            $totals[$row->country] = ($totals[$row->country] ?? 0) + (int) $row->total;
        }

        [$firstMonth, $lastMonth] = self::wholeMonths($from, $to);
        if ($firstMonth <= $lastMonth) {
            $monthly = DB::table(VisitorMapMigration::TABLE_MONTHLY)
                ->select('country', DB::raw("SUM({$column}) AS total"))
                ->where('context_id', '=', $contextId)
                ->where('month', '<=', $lastMonth)
                ->when($firstMonth > 0, fn ($query) => $query->where('month', '>=', $firstMonth))
                ->groupBy('country')
                ->get();
            foreach ($monthly as $row) {
                $totals[$row->country] = ($totals[$row->country] ?? 0) + (int) $row->total;
            }
        }

        $excluded = array_map('strtoupper', $excluded);
        $totals = array_filter($totals, fn ($total, $country) => $total > 0 && !in_array($country, $excluded, true), ARRAY_FILTER_USE_BOTH);
        arsort($totals);

        return $totals;
    }

    /**
     * The first day the journal has data for, in either table.
     */
    public function firstDay(int $contextId): ?string
    {
        $month = DB::table(VisitorMapMigration::TABLE_MONTHLY)->where('context_id', '=', $contextId)->min('month');
        if ($month !== null) {
            return substr((string) $month, 0, 4) . '-' . substr((string) $month, 4, 2) . '-01';
        }
        $day = DB::table(VisitorMapMigration::TABLE_DAILY)->where('context_id', '=', $contextId)->min('date');

        return $day === null ? null : substr((string) $day, 0, 10);
    }

    /**
     * The months, as YYYYMM, that begin and end inside the range. With no first
     * day, every month up to the last one counts.
     *
     * @return int[] [first, last]; first is greater than last when there is none.
     */
    public static function wholeMonths(?string $from, string $to): array
    {
        $end = new \DateTimeImmutable($to);
        $last = $end->modify('last day of this month')->format('Y-m-d') === $end->format('Y-m-d')
            ? (int) $end->format('Ym')
            : (int) $end->modify('first day of last month')->format('Ym');

        if ($from === null) {
            return [0, $last];
        }
        $start = new \DateTimeImmutable($from);
        $first = $start->format('d') === '01'
            ? (int) $start->format('Ym')
            : (int) $start->modify('first day of next month')->format('Ym');

        return [$first, $last];
    }
}
