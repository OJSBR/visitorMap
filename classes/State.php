<?php

/**
 * @file plugins/blocks/visitorMap/classes/State.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class State
 *
 * @brief Where the aggregation stopped, the version of the data and the lock
 *        that keeps two runs from working at the same time.
 *
 *        Kept in a table of the plugin and not in plugin_settings: the plugin
 *        settings are cached per request, and the job, the scheduled task and
 *        the pages all have to see the same values at once.
 */

namespace APP\plugins\blocks\visitorMap\classes;

use APP\plugins\blocks\visitorMap\classes\migration\VisitorMapMigration;
use Illuminate\Support\Facades\DB;

class State
{
    private const LOCK = 'lock';
    private const VERSION = 'version';

    public function get(string $name, ?string $default = null): ?string
    {
        $value = DB::table(VisitorMapMigration::TABLE_STATE)->where('name', '=', $name)->value('value');

        return $value === null ? $default : (string) $value;
    }

    /**
     * Several values at once, for the page, which reads the version and the
     * time of the last run on every view.
     *
     * @param string[] $names
     *
     * @return array<string,string>
     */
    public function getMany(array $names): array
    {
        return DB::table(VisitorMapMigration::TABLE_STATE)->whereIn('name', $names)->pluck('value', 'name')->all();
    }

    public function set(string $name, string|int $value): void
    {
        DB::table(VisitorMapMigration::TABLE_STATE)->upsert(
            [['name' => $name, 'value' => (string) $value]],
            ['name'],
            ['value']
        );
    }

    public function forget(string $name): void
    {
        DB::table(VisitorMapMigration::TABLE_STATE)->where('name', '=', $name)->delete();
    }

    /**
     * Changes whenever the aggregated data does, so every cache built on it
     * expires. A random value and not a counter: a counter starts over when the
     * tables are created again, and would meet the caches of the old ones.
     */
    public function version(): string
    {
        return (string) $this->get(self::VERSION, '');
    }

    public function bumpVersion(): void
    {
        $this->set(self::VERSION, bin2hex(random_bytes(8)));
    }

    /**
     * Take the lock unless another run holds it. A lock older than $seconds
     * belongs to a run that died and is taken over.
     *
     * The value is a zero-padded timestamp, so comparing the strings compares
     * the times on every database.
     */
    public function acquireLock(int $seconds): bool
    {
        DB::table(VisitorMapMigration::TABLE_STATE)->insertOrIgnore(['name' => self::LOCK, 'value' => self::stamp(0)]);

        return DB::table(VisitorMapMigration::TABLE_STATE)
            ->where('name', '=', self::LOCK)
            ->where('value', '<', self::stamp(time() - $seconds))
            ->update(['value' => self::stamp(time())]) === 1;
    }

    public function releaseLock(): void
    {
        $this->set(self::LOCK, self::stamp(0));
    }

    private static function stamp(int $time): string
    {
        return sprintf('%012d', max(0, $time));
    }
}
