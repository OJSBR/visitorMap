<?php

/**
 * @file plugins/blocks/visitorMap/classes/Aggregator.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class Aggregator
 *
 * @brief Sums the core's geographic statistics into the plugin's tables.
 *
 *        The core tables are only read through their indexes: the daily one
 *        by load_id (one log file, one day) and by ranges of its primary key,
 *        the monthly one by ranges of its primary key. A filter by date would
 *        read the whole table, which is what the plugin is here to avoid.
 *
 *        Daily data is recomputed one load at a time, replacing what the plugin
 *        had for that load, the same way the core replaces it when a log file
 *        is loaded again. The last days are always recomputed: the core writes
 *        the unique accesses of a load in a second step, after the totals.
 *
 *        The months before the first day of daily data come once from the
 *        monthly table; they are closed and do not change any more.
 *
 *        A run stops when its time is up and says so; the next one carries on.
 */

namespace APP\plugins\blocks\visitorMap\classes;

use APP\core\Application;
use APP\plugins\blocks\visitorMap\classes\migration\VisitorMapMigration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Aggregator
{
    public const SOURCE_DAILY = 'metrics_submission_geo_daily';
    public const SOURCE_DAILY_KEY = 'metrics_submission_geo_daily_id';
    public const SOURCE_MONTHLY = 'metrics_submission_geo_monthly';
    public const SOURCE_MONTHLY_KEY = 'metrics_submission_geo_monthly_id';

    /** Days before the newest load that are recomputed on every run. */
    public const RECENT_DAYS = 3;

    /** Rows of the monthly table read per step. */
    public const MONTHLY_CHUNK = 50000;

    /** A run holding the lock longer than this is taken to have died. */
    public const LOCK_SECONDS = 300;

    private float $deadline = 0.0;
    private bool $changed = false;
    private State $state;

    /** @var int[] */
    private array $contextIds = [];

    public function __construct(?State $state = null)
    {
        $this->state = $state ?? new State();
    }

    /**
     * Work until everything is up to date or $seconds have passed.
     *
     * @return bool Whether everything is up to date. False means another run
     *              is needed to finish.
     */
    public function run(float $seconds = 15.0): bool
    {
        (new VisitorMapMigration())->up();

        if (!$this->state->acquireLock(self::LOCK_SECONDS)) {
            // Another run is at it and will finish the work.
            return true;
        }

        try {
            $this->deadline = microtime(true) + $seconds;
            $this->changed = false;
            $contextDao = Application::getContextDAO();
            $this->contextIds = DB::table($contextDao->tableName)->pluck($contextDao->primaryKeyColumn)->map(fn ($id) => (int) $id)->all();

            $finished = $this->daily() && $this->monthly();

            if ($this->changed) {
                $this->state->bumpVersion();
            }
            $this->state->set('lastRun', time());

            return $finished;
        } finally {
            $this->state->releaseLock();
        }
    }

    /**
     * The day of a load, read from its id the way the core reads it: the load
     * id is the name of the log file, which ends in YYYYMMDD.log.
     */
    public static function loadDate(string $loadId): ?string
    {
        if (!preg_match('/(\d{4})(\d{2})(\d{2})\.\w+$/', $loadId, $match) || !checkdate((int) $match[2], (int) $match[3], (int) $match[1])) {
            return null;
        }

        return "{$match[1]}-{$match[2]}-{$match[3]}";
    }

    private function timeIsUp(): bool
    {
        return microtime(true) >= $this->deadline;
    }

    private function daily(): bool
    {
        if (!Schema::hasTable(self::SOURCE_DAILY)) {
            return true;
        }

        $maxId = (int) DB::table(self::SOURCE_DAILY)->max(self::SOURCE_DAILY_KEY);
        $idCursor = $this->state->get('dailyIdCursor');
        if ($idCursor === null) {
            // First run: what exists now is taken load by load, in order; what
            // arrives from here on is found by its primary key.
            $idCursor = (string) $maxId;
            $this->state->set('dailyIdCursor', $idCursor);
            $this->state->set('dailyDoneThrough', '');
        }
        $doneThrough = (string) $this->state->get('dailyDoneThrough', '');

        $new = DB::table(self::SOURCE_DAILY)->where('load_id', '>', $doneThrough)->distinct()->pluck('load_id')->all();
        sort($new, SORT_STRING);
        $touched = DB::table(self::SOURCE_DAILY)
            ->where(self::SOURCE_DAILY_KEY, '>', (int) $idCursor)
            ->where(self::SOURCE_DAILY_KEY, '<=', $maxId)
            ->distinct()->pluck('load_id')->all();

        // New loads, in order, as far as the time allows. At least one per run,
        // however short the run, so that every run moves on.
        $worked = false;
        foreach ($new as $loadId) {
            if ($worked && $this->timeIsUp()) {
                return false;
            }
            $worked = true;
            $this->recomputeLoad((string) $loadId);
            $doneThrough = (string) $loadId;
            $this->state->set('dailyDoneThrough', $doneThrough);
        }

        // Loads the core wrote again since the last run, and the last days,
        // which it may still be completing. They are few, and are done in full:
        // being redone on every run, they would otherwise never be finished.
        $again = array_diff(array_unique(array_merge($touched, $this->recentLoads())), $new);
        sort($again, SORT_STRING);
        foreach ($again as $loadId) {
            $this->recomputeLoad((string) $loadId);
        }

        $this->state->set('dailyIdCursor', $maxId);

        return true;
    }

    /**
     * The loads of the last days before the newest one, which the core may still
     * be completing.
     *
     * @return string[]
     */
    private function recentLoads(): array
    {
        $newest = (string) DB::table(self::SOURCE_DAILY)->max('load_id');
        $date = self::loadDate($newest);
        if ($date === null) {
            return [];
        }

        $from = date('Ymd', strtotime($date . ' -' . self::RECENT_DAYS . ' days'));
        $floor = substr($newest, 0, -12) . $from . substr($newest, -4);

        return DB::table(self::SOURCE_DAILY)->where('load_id', '>=', $floor)->distinct()->pluck('load_id')->all();
    }

    /**
     * Replace what the plugin has for a load with what the core has now. The
     * day of the load is cleared too, as the core clears it before loading.
     */
    private function recomputeLoad(string $loadId): void
    {
        $rows = DB::table(self::SOURCE_DAILY)
            ->select('context_id', 'country', 'date', DB::raw('SUM(metric) AS metric'), DB::raw('SUM(metric_unique) AS metric_unique'))
            ->where('load_id', '=', $loadId)
            ->whereNotNull('country')
            ->where('country', '<>', '')
            ->whereIn('context_id', $this->contextIds ?: [0])
            ->groupBy('context_id', 'country', 'date')
            ->get();

        $insert = [];
        foreach ($rows as $row) {
            $insert[] = [
                'load_id' => $loadId,
                'context_id' => (int) $row->context_id,
                'country' => strtoupper((string) $row->country),
                'date' => substr((string) $row->date, 0, 10),
                'metric' => (int) $row->metric,
                'metric_unique' => (int) $row->metric_unique,
            ];
        }

        $date = self::loadDate($loadId);
        if ($this->holds($loadId, $date, $insert)) {
            // Nothing changed since the last time: keep the version, and with
            // it every cache and map built on it.
            return;
        }
        DB::transaction(function () use ($loadId, $date, $insert) {
            DB::table(VisitorMapMigration::TABLE_DAILY)
                ->where(fn ($query) => $query->where('load_id', '=', $loadId)->when($date !== null, fn ($query) => $query->orWhere('date', '=', $date)))
                ->delete();
            foreach (array_chunk($insert, 500) as $chunk) {
                DB::table(VisitorMapMigration::TABLE_DAILY)->insert($chunk);
            }
        });

        $this->changed = true;
    }

    /**
     * Whether the plugin already has exactly these rows for the load and its
     * day, and nothing else.
     *
     * @param array[] $rows
     */
    private function holds(string $loadId, ?string $date, array $rows): bool
    {
        $current = DB::table(VisitorMapMigration::TABLE_DAILY)
            ->select('load_id', 'context_id', 'country', 'date', 'metric', 'metric_unique')
            ->where(fn ($query) => $query->where('load_id', '=', $loadId)->when($date !== null, fn ($query) => $query->orWhere('date', '=', $date)))
            ->get()
            ->map(fn ($row) => implode('|', [$row->load_id, (int) $row->context_id, $row->country, substr((string) $row->date, 0, 10), (int) $row->metric, (int) $row->metric_unique]))
            ->sort()->values()->all();
        $wanted = array_map(fn ($row) => implode('|', $row), $rows);
        sort($wanted);

        return $current === $wanted;
    }

    private function monthly(): bool
    {
        if ($this->state->get('monthlyDone') === '1' || !Schema::hasTable(self::SOURCE_MONTHLY)) {
            return true;
        }

        if ($this->state->get('monthlyCutoff') === null) {
            // Months from the first day of daily data on come from the daily
            // table; the ones before it, from the monthly table.
            $first = Schema::hasTable(self::SOURCE_DAILY) ? DB::table(self::SOURCE_DAILY)->min('load_id') : null;
            $date = $first !== null ? self::loadDate((string) $first) : null;
            $cutoff = $date !== null ? (int) (substr($date, 0, 4) . substr($date, 5, 2)) : (int) date('Ym');

            $this->state->set('monthlyIdCursor', 0);
            $this->state->set('monthlyIdEnd', (int) DB::table(self::SOURCE_MONTHLY)->max(self::SOURCE_MONTHLY_KEY));
            $this->state->set('monthlyCutoff', $cutoff);
        }

        $cutoff = (int) $this->state->get('monthlyCutoff');
        $cursor = (int) $this->state->get('monthlyIdCursor', '0');
        $end = (int) $this->state->get('monthlyIdEnd', '0');

        $worked = false;
        while ($cursor < $end) {
            if ($worked && $this->timeIsUp()) {
                return false;
            }
            $worked = true;
            // The keys of the monthly table have wide gaps (months are compiled
            // again under new keys), so the chunk ends at the key that is
            // MONTHLY_CHUNK rows ahead, not MONTHLY_CHUNK keys ahead.
            $to = (int) (DB::table(self::SOURCE_MONTHLY)
                ->where(self::SOURCE_MONTHLY_KEY, '>', $cursor)
                ->orderBy(self::SOURCE_MONTHLY_KEY)
                ->offset(self::MONTHLY_CHUNK - 1)
                ->limit(1)
                ->value(self::SOURCE_MONTHLY_KEY) ?? $end);
            $to = min($to, $end);
            $rows = DB::table(self::SOURCE_MONTHLY)
                ->select('context_id', 'country', 'month', DB::raw('SUM(metric) AS metric'), DB::raw('SUM(metric_unique) AS metric_unique'))
                ->where(self::SOURCE_MONTHLY_KEY, '>', $cursor)
                ->where(self::SOURCE_MONTHLY_KEY, '<=', $to)
                ->where('month', '<', $cutoff)
                ->whereNotNull('country')
                ->where('country', '<>', '')
                ->whereIn('context_id', $this->contextIds ?: [0])
                ->groupBy('context_id', 'country', 'month')
                ->get();

            // The months are added up chunk by chunk, so the rows and the new
            // position are written together or not at all.
            DB::transaction(function () use ($rows, $to) {
                $this->addToMonthly($rows->all());
                $this->state->set('monthlyIdCursor', $to);
            });
            if ($rows->isNotEmpty()) {
                $this->changed = true;
            }
            $cursor = $to;
        }

        $this->state->set('monthlyDone', '1');

        return true;
    }

    /** @param object[] $rows */
    private function addToMonthly(array $rows): void
    {
        if (!$rows) {
            return;
        }

        $key = fn ($row) => $row->context_id . '|' . strtoupper((string) $row->country) . '|' . $row->month;
        $existing = DB::table(VisitorMapMigration::TABLE_MONTHLY)
            ->whereIn('context_id', array_unique(array_map(fn ($row) => (int) $row->context_id, $rows)))
            ->whereIn('month', array_unique(array_map(fn ($row) => (int) $row->month, $rows)))
            ->get()
            ->keyBy($key);

        $upsert = [];
        foreach ($rows as $row) {
            $before = $existing[$key($row)] ?? null;
            $upsert[] = [
                'context_id' => (int) $row->context_id,
                'country' => strtoupper((string) $row->country),
                'month' => (int) $row->month,
                'metric' => (int) $row->metric + (int) ($before->metric ?? 0),
                'metric_unique' => (int) $row->metric_unique + (int) ($before->metric_unique ?? 0),
            ];
        }
        foreach (array_chunk($upsert, 500) as $chunk) {
            DB::table(VisitorMapMigration::TABLE_MONTHLY)->upsert($chunk, ['context_id', 'country', 'month'], ['metric', 'metric_unique']);
        }
    }
}
