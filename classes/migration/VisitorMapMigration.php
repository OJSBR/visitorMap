<?php

/**
 * @file plugins/blocks/visitorMap/classes/migration/VisitorMapMigration.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class VisitorMapMigration
 *
 * @brief The plugin's own tables: accesses by journal, country and day, and by
 *        journal, country and month for the time before daily statistics.
 *
 *        The core's metrics_submission_geo_daily has no index that serves a
 *        range of dates, so reading it on every page is out of the question;
 *        these tables are small, summed per country, and indexed for exactly
 *        the query the block makes. They also keep the history the core
 *        deletes when daily statistics are not kept.
 */

namespace APP\plugins\blocks\visitorMap\classes\migration;

use APP\core\Application;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class VisitorMapMigration extends Migration
{
    public const TABLE_DAILY = 'visitor_map_daily';
    public const TABLE_MONTHLY = 'visitor_map_monthly';
    public const TABLE_STATE = 'visitor_map_state';

    /**
     * Create the tables that are missing. Safe to run again: it is also run when
     * the plugin is enabled, since a plugin copied into place instead of
     * installed through the interface never had its migration run.
     */
    public function up(): void
    {
        $contextDao = Application::getContextDAO();

        if (!Schema::hasTable(self::TABLE_DAILY)) {
            Schema::create(self::TABLE_DAILY, function (Blueprint $table) use ($contextDao) {
                $table->comment('Accesses by journal, country and day, summed from metrics_submission_geo_daily by the Visitor Map plugin.');
                $table->bigIncrements('visitor_map_daily_id');
                $table->string('load_id', 50);
                $table->bigInteger('context_id');
                $table->string('country', 2);
                $table->date('date');
                $table->bigInteger('metric')->default(0);
                $table->bigInteger('metric_unique')->default(0);

                $table->foreign('context_id', 'visitor_map_daily_context_id')
                    ->references($contextDao->primaryKeyColumn)->on($contextDao->tableName)->onDelete('cascade');
                $table->unique(['load_id', 'context_id', 'country', 'date'], 'visitor_map_daily_unique');
                $table->index(['context_id', 'date'], 'visitor_map_daily_context_date');
                $table->index(['date'], 'visitor_map_daily_date');
            });
        }

        if (!Schema::hasTable(self::TABLE_MONTHLY)) {
            Schema::create(self::TABLE_MONTHLY, function (Blueprint $table) use ($contextDao) {
                $table->comment('Accesses by journal, country and month, for the months before the daily statistics available, summed by the Visitor Map plugin.');
                $table->bigIncrements('visitor_map_monthly_id');
                $table->bigInteger('context_id');
                $table->string('country', 2);
                $table->integer('month');
                $table->bigInteger('metric')->default(0);
                $table->bigInteger('metric_unique')->default(0);

                $table->foreign('context_id', 'visitor_map_monthly_context_id')
                    ->references($contextDao->primaryKeyColumn)->on($contextDao->tableName)->onDelete('cascade');
                $table->unique(['context_id', 'country', 'month'], 'visitor_map_monthly_unique');
            });
        }

        if (!Schema::hasTable(self::TABLE_STATE)) {
            Schema::create(self::TABLE_STATE, function (Blueprint $table) {
                $table->comment('Progress of the Visitor Map plugin over the core statistics.');
                $table->string('name', 64)->primary();
                $table->string('value', 255)->default('');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE_DAILY);
        Schema::dropIfExists(self::TABLE_MONTHLY);
        Schema::dropIfExists(self::TABLE_STATE);
    }

    /** Whether all the tables exist. */
    public static function isInstalled(): bool
    {
        return Schema::hasTable(self::TABLE_DAILY)
            && Schema::hasTable(self::TABLE_MONTHLY)
            && Schema::hasTable(self::TABLE_STATE);
    }

    /** The database the tables live in, for the few statements that differ. */
    public static function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }
}
