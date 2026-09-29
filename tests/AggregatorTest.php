<?php

/**
 * @file plugins/blocks/visitorMap/tests/AggregatorTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class AggregatorTest
 *
 * @brief The plugin's tables follow the core's geographic statistics: the first
 *        filling, a run with nothing new, unique accesses written late, a day
 *        loaded again, a new day, a run cut short, and two runs at once.
 *
 *        Rows are written into the core tables the way the usage statistics
 *        loader writes them, on days in 2099 so they are always the newest, and
 *        read back from the plugin's tables. The plugin's tables are saved
 *        before each test and put back after it; the core rows are deleted.
 */

namespace APP\plugins\blocks\visitorMap\tests;

use APP\core\Application;
use APP\plugins\blocks\visitorMap\classes\Aggregator;
use APP\plugins\blocks\visitorMap\classes\migration\VisitorMapMigration;
use APP\plugins\blocks\visitorMap\classes\Repository;
use APP\plugins\blocks\visitorMap\classes\State;
use APP\submission\Submission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PKP\statistics\PKPStatisticsHelper;
use PKP\tests\PKPTestCase;

class AggregatorTest extends PKPTestCase
{
    private const PRODUCTION_LOOKS_LIKE = 100;
    private const DAYS = ['2099-01-10', '2099-01-11', '2099-01-12', '2099-01-13'];

    private int $contextId = 0;
    private int $submissionId = 0;

    /** @var array<string,array> The plugin's tables as they were. */
    private array $saved = [];

    /** @var string[] Usage logs written into the core's archive. */
    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $contextDao = Application::getContextDAO();
        $this->contextId = (int) DB::table($contextDao->tableName)->min($contextDao->primaryKeyColumn);
        if (!$this->contextId) {
            $this->markTestSkipped('there is no journal');
        }
        $published = DB::table('submissions')->where('status', Submission::STATUS_PUBLISHED)->count();
        if ($published > self::PRODUCTION_LOOKS_LIKE) {
            $this->markTestSkipped('this installation has ' . $published . ' published submissions: it looks like a live site');
        }
        $this->submissionId = (int) DB::table('submissions')->where('context_id', $this->contextId)->min('submission_id');
        if (!$this->submissionId) {
            $this->markTestSkipped('the journal has no submission');
        }

        (new VisitorMapMigration())->up();
        foreach ([VisitorMapMigration::TABLE_DAILY, VisitorMapMigration::TABLE_MONTHLY, VisitorMapMigration::TABLE_STATE] as $table) {
            $this->saved[$table] = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
            DB::table($table)->delete();
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->logs as $log) {
            if (is_file($log)) {
                unlink($log);
            }
        }
        $this->logs = [];
        DB::table(Aggregator::SOURCE_DAILY)->where('load_id', 'like', 'usage_events_2099%')->delete();
        DB::table(Aggregator::SOURCE_MONTHLY)->where('context_id', $this->contextId)->whereIn('month', [200011, 200012])->delete();
        foreach ($this->saved as $table => $rows) {
            DB::table($table)->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table($table)->insert($chunk);
            }
        }
        $this->saved = [];
        parent::tearDown();
    }

    public function testTheFirstRunCopiesDaysAndClosedMonths(): void
    {
        $this->seedDays();
        $this->seedMonth(200012, ['BR' => [50, 40], 'PT' => [7, 5]]);

        $this->assertTrue((new Aggregator())->run(30.0));

        foreach (self::DAYS as $index => $day) {
            $this->assertSame(['BR' => [10 + $index, 8 + $index], 'PT' => [3, 2]], $this->daily($day));
        }
        $this->assertSame(['BR' => [50, 40], 'PT' => [7, 5]], $this->monthly(200012), 'a month before the daily data comes from the monthly table');
        $this->assertSame(0, DB::table(VisitorMapMigration::TABLE_DAILY)->where('country', '')->count(), 'accesses without a country are not counted');
        $this->assertNotSame('', (new State())->version());
    }

    public function testARunWithNothingNewKeepsTheVersion(): void
    {
        $this->seedDays();
        (new Aggregator())->run(30.0);
        $version = (new State())->version();

        (new Aggregator())->run(30.0);

        $this->assertSame($version, (new State())->version(), 'the caches built on the data must survive a run that changed nothing');
    }

    /**
     * The loader writes the unique accesses of a day after its totals, with an
     * update that creates no new row: only recomputing the last days sees it.
     */
    public function testUniqueAccessesWrittenLateAreFollowed(): void
    {
        $this->seedDays();
        (new Aggregator())->run(30.0);
        $version = (new State())->version();

        // Both rows of the day for Brazil (two regions) get the late figure.
        DB::table(Aggregator::SOURCE_DAILY)->where('load_id', $this->loadId('2099-01-13'))->where('country', 'BR')->update(['metric_unique' => 99]);
        (new Aggregator())->run(30.0);

        $this->assertSame(198, $this->daily('2099-01-13')['BR'][1]);
        $this->assertNotSame($version, (new State())->version());
    }

    /** A day loaded again replaces what the plugin had; it is not added to it. */
    public function testADayLoadedAgainIsReplaced(): void
    {
        $this->seedDays();
        (new Aggregator())->run(30.0);

        DB::table(Aggregator::SOURCE_DAILY)->where('load_id', $this->loadId('2099-01-10'))->delete();
        $this->insertDaily('2099-01-10', ['BR' => [4, 4], 'AO' => [2, 1]]);
        (new Aggregator())->run(30.0);

        $this->assertSame(['AO' => [2, 1], 'BR' => [4, 4]], $this->daily('2099-01-10'));
    }

    public function testANewDayIsPickedUp(): void
    {
        $this->seedDays();
        (new Aggregator())->run(30.0);

        $this->insertDaily('2099-01-14', ['JP' => [6, 6]]);
        (new Aggregator())->run(30.0);

        $this->assertSame(['JP' => [6, 6]], $this->daily('2099-01-14'));
        DB::table(Aggregator::SOURCE_DAILY)->where('load_id', $this->loadId('2099-01-14'))->delete();
    }

    /** A run out of time says so, and the runs that follow end where one long run would. */
    public function testARunCutShortResumesWhereItStopped(): void
    {
        $this->seedDays();
        $this->seedMonth(200012, ['BR' => [50, 40]]);

        $this->assertFalse((new Aggregator())->run(0.0), 'with no time at all a run does one load and stops');
        $runs = 1;
        while (!(new Aggregator())->run(0.001) && $runs < 500) {
            $runs++;
        }
        $this->assertLessThan(500, $runs, 'the runs never finished');
        $cut = [$this->daily('2099-01-10'), $this->daily('2099-01-13'), $this->monthly(200012)];

        foreach ([VisitorMapMigration::TABLE_DAILY, VisitorMapMigration::TABLE_MONTHLY, VisitorMapMigration::TABLE_STATE] as $table) {
            DB::table($table)->delete();
        }
        (new Aggregator())->run(30.0);

        $this->assertSame([$this->daily('2099-01-10'), $this->daily('2099-01-13'), $this->monthly(200012)], $cut);
    }

    public function testASecondRunWaitsForTheFirst(): void
    {
        $this->seedDays();
        $state = new State();
        $this->assertTrue($state->acquireLock(Aggregator::LOCK_SECONDS));

        try {
            (new Aggregator())->run(30.0);
            $this->assertSame([], $this->daily('2099-01-10'), 'a run started while another holds the lock must not work');
        } finally {
            $state->releaseLock();
        }

        (new Aggregator())->run(30.0);
        $this->assertNotSame([], $this->daily('2099-01-10'));
    }

    /**
     * The block's query: days from the daily table, and only the months that
     * lie wholly inside the range from the monthly one.
     */
    public function testTheRangeCountsDaysAndWholeMonths(): void
    {
        $this->seedDays();
        $this->seedMonth(200011, ['BR' => [1000, 900]]);
        $this->seedMonth(200012, ['BR' => [50, 40], 'PT' => [7, 5]]);
        (new Aggregator())->run(30.0);
        $repository = new Repository();

        // Windows that reach neither side of whatever data the installation has.
        $this->assertSame(['BR' => 40, 'PT' => 5], $repository->byCountry($this->contextId, '2000-11-15', '2001-06-30', true), 'November starts before the range and is left out');
        $this->assertSame(['BR' => 900 + 40], $repository->byCountry($this->contextId, '2000-11-01', '2001-06-30', true, ['PT']), 'a whole month counts, an excluded country does not');
        $this->assertSame(['BR' => 8 + 9 + 10, 'PT' => 2 + 2 + 2], $repository->byCountry($this->contextId, '2099-01-10', '2099-01-12', true));
        $this->assertSame(['BR' => 12 + 13, 'PT' => 3 + 3], $repository->byCountry($this->contextId, '2099-01-12', '2099-01-13', false));
        $this->assertSame([], $repository->byCountry($this->contextId, '2099-02-01', '2099-02-28', true));
        $this->assertSame('2000-11-01', $repository->firstDay($this->contextId));
    }

    /**
     * The core's own number minus what the filter finds in the day's log;
     * a day without a log has no filtered number.
     */
    public function testTheFilteredCountsAreTheCoreMinusTheScrapers(): void
    {
        $this->seedDays();
        $this->writeLog('2099-01-13', [
            ['tool1', 'msh-pdfmin/1', 'PT'],
            ['tool2', 'msh-pdfmin/1', 'PT'],
            ['reader', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'BR'],
        ]);

        (new Aggregator())->run(30.0);

        $this->assertSame(['BR' => [13, 11, 13, 11], 'PT' => [3, 2, 1, 0]], $this->dailyWithClean('2099-01-13'), 'Portugal loses its two scraper accesses, Brazil keeps the official figure');
        $this->assertSame(['BR' => [12, 10, null, null], 'PT' => [3, 2, null, null]], $this->dailyWithClean('2099-01-12'), 'no log, no filtered number');

        $repository = new Repository();
        $this->assertSame(['BR' => 11 + 10, 'PT' => 2 + 2], $repository->byCountry($this->contextId, '2099-01-12', '2099-01-13', true));
        $this->assertSame(['BR' => 11 + 10, 'PT' => 0 + 2], $repository->byCountry($this->contextId, '2099-01-12', '2099-01-13', true, [], true), 'where there was no log the core figure is used');
        // The installation may have logs of its own for earlier days.
        $first = $repository->firstFilteredDay($this->contextId);
        $this->assertNotNull($first);
        $this->assertLessThanOrEqual(0, strcmp($first, '2099-01-13'));
    }

    /**
     * After the upgrade, days already stored get their filtered numbers from
     * the plugin's own rows, and the stored history is kept even when the core
     * has deleted the day.
     */
    public function testTheBackfillFillsStoredDaysAndKeepsTheirHistory(): void
    {
        $this->insertDaily('2099-01-01', ['PT' => [9, 7]]);
        $this->seedDays();
        (new Aggregator())->run(30.0);
        $this->assertSame(['PT' => [9, 7, null, null]], $this->dailyWithClean('2099-01-01'));

        // The state of a site that ran 1.0: no backfill done yet.
        (new State())->forget('cleanBackfillDone');
        (new State())->forget('cleanBackfillCursor');
        $this->writeLog('2099-01-01', [['tool1', 'msh-pdfmin/1', 'PT'], ['tool2', 'msh-pdfmin/1', 'PT']]);
        DB::table(Aggregator::SOURCE_DAILY)->where('load_id', $this->loadId('2099-01-01'))->delete();

        (new Aggregator())->run(30.0);

        $this->assertSame(['PT' => [9, 7, 7, 5]], $this->dailyWithClean('2099-01-01'));
        $this->assertSame('1', (new State())->get('cleanBackfillDone'));
    }

    /**
     * An archived log does not change: a day whose core figures did not change
     * and that was already filtered is not read again on the next runs.
     */
    public function testAnUnchangedFilteredDayIsNotReadAgain(): void
    {
        $this->seedDays();
        $this->writeLog('2099-01-13', [['tool1', 'msh-pdfmin/1', 'PT']]);
        (new Aggregator())->run(30.0);
        $this->assertSame([3, 2, 2, 1], $this->dailyWithClean('2099-01-13')['PT']);

        // Were the log read again, the day would lose a second access.
        file_put_contents(end($this->logs), str_replace('tool1', 'tool1', (string) file_get_contents(end($this->logs))) . str_replace('tool1', 'tool2', (string) file_get_contents(end($this->logs))));
        (new Aggregator())->run(30.0);

        $this->assertSame([3, 2, 2, 1], $this->dailyWithClean('2099-01-13')['PT']);
    }

    public function testTheMigrationRunsTwice(): void
    {
        (new VisitorMapMigration())->up();
        (new VisitorMapMigration())->up();

        foreach ([VisitorMapMigration::TABLE_DAILY, VisitorMapMigration::TABLE_MONTHLY] as $table) {
            foreach (VisitorMapMigration::CLEAN_COLUMNS as $column) {
                $this->assertTrue(Schema::hasColumn($table, $column), "{$table}.{$column}");
            }
        }
    }

    /** @param array<int,string[]> $visits [visitor, user agent, country] */
    private function writeLog(string $day, array $visits): void
    {
        $archive = PKPStatisticsHelper::getUsageStatsDirPath() . '/archive';
        if (!is_dir($archive)) {
            mkdir($archive, 0755, true);
        }
        $lines = [];
        foreach ($visits as $index => [$visitor, $agent, $country]) {
            $lines[] = json_encode([
                'time' => $day . sprintf(' 08:%02d:00', $index), 'ip' => hash('sha256', $visitor), 'userAgent' => $agent,
                'canonicalUrl' => 'https://example.org/article/view/' . $this->submissionId, 'assocType' => 1048585,
                'contextId' => $this->contextId, 'submissionId' => $this->submissionId, 'representationId' => null,
                'submissionFileId' => null, 'fileType' => null, 'country' => $country, 'region' => null, 'city' => null,
                'institutionIds' => [], 'version' => '3.5.0.3', 'issueId' => null, 'issueGalleyId' => null,
            ]);
        }
        $path = $archive . '/' . $this->loadId($day);
        $this->assertFileDoesNotExist($path, 'a real log with the name of the test');
        file_put_contents($path, implode("\n", $lines) . "\n");
        $this->logs[] = $path;
    }

    /** @return array<string,array> [total, unique, total filtered, unique filtered] by country */
    private function dailyWithClean(string $day): array
    {
        $rows = [];
        foreach (DB::table(VisitorMapMigration::TABLE_DAILY)->where('context_id', $this->contextId)->where('date', $day)->orderBy('country')->get() as $row) {
            $rows[$row->country] = [(int) $row->metric, (int) $row->metric_unique, $row->metric_clean === null ? null : (int) $row->metric_clean, $row->metric_unique_clean === null ? null : (int) $row->metric_unique_clean];
        }

        return $rows;
    }

    private function loadId(string $day): string
    {
        return 'usage_events_' . str_replace('-', '', $day) . '.log';
    }

    /** Four days, the accesses of each split over two submissions like real ones. */
    private function seedDays(): void
    {
        foreach (self::DAYS as $index => $day) {
            $this->insertDaily($day, ['BR' => [10 + $index, 8 + $index], 'PT' => [3, 2], '' => [5, 5]]);
        }
    }

    /** @param array<string,int[]> $countries [total, unique] by country */
    private function insertDaily(string $day, array $countries): void
    {
        foreach ($countries as $country => [$total, $unique]) {
            foreach ([[$total - intdiv($total, 2), $unique - intdiv($unique, 2), ''], [intdiv($total, 2), intdiv($unique, 2), 'SP']] as [$metric, $metricUnique, $region]) {
                DB::table(Aggregator::SOURCE_DAILY)->insert([
                    'load_id' => $this->loadId($day), 'context_id' => $this->contextId, 'submission_id' => $this->submissionId,
                    'country' => $country, 'region' => $region, 'city' => '', 'date' => $day,
                    'metric' => $metric, 'metric_unique' => $metricUnique,
                ]);
            }
        }
    }

    /** @param array<string,int[]> $countries [total, unique] by country */
    private function seedMonth(int $month, array $countries): void
    {
        foreach ($countries as $country => [$total, $unique]) {
            DB::table(Aggregator::SOURCE_MONTHLY)->insert([
                'context_id' => $this->contextId, 'submission_id' => $this->submissionId,
                'country' => $country, 'region' => '', 'city' => '', 'month' => $month,
                'metric' => $total, 'metric_unique' => $unique,
            ]);
        }
    }

    /** @return array<string,int[]> [total, unique] by country, as the plugin stored them */
    private function daily(string $day): array
    {
        $rows = [];
        foreach (DB::table(VisitorMapMigration::TABLE_DAILY)->where('context_id', $this->contextId)->where('date', $day)->orderBy('country')->get() as $row) {
            $rows[$row->country] = [(int) $row->metric, (int) $row->metric_unique];
        }

        return $rows;
    }

    /** @return array<string,int[]> */
    private function monthly(int $month): array
    {
        $rows = [];
        foreach (DB::table(VisitorMapMigration::TABLE_MONTHLY)->where('context_id', $this->contextId)->where('month', $month)->orderBy('country')->get() as $row) {
            $rows[$row->country] = [(int) $row->metric, (int) $row->metric_unique];
        }

        return $rows;
    }
}
