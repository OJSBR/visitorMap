<?php

/**
 * @file plugins/blocks/visitorMap/tests/BlockTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class BlockTest
 *
 * @brief The block as a page shows it: the map written to the journal's public
 *        folder, the numbers and the list of countries, the cache between two
 *        views, the reader and the manager when there is nothing to show, and
 *        the settings.
 *
 *        Rendered through the template manager with the journal pinned on the
 *        router. The block reads only the plugin's tables, so the accesses are
 *        written there (AggregatorTest covers how they get there from the core).
 *        The test puts back the settings and the plugin's tables.
 */

namespace APP\plugins\blocks\visitorMap\tests;

use APP\core\Application;
use APP\core\PageRouter;
use APP\plugins\blocks\visitorMap\classes\migration\VisitorMapMigration;
use APP\plugins\blocks\visitorMap\classes\State;
use APP\plugins\blocks\visitorMap\VisitorMapPlugin;
use APP\plugins\blocks\visitorMap\VisitorMapSettingsForm;
use APP\submission\Submission;
use APP\template\TemplateManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PKP\context\Context;
use PKP\core\Core;
use PKP\core\PKPRequest;
use PKP\core\Registry;
use PKP\db\DAORegistry;
use PKP\facades\Locale;
use PKP\plugins\PluginRegistry;
use PKP\plugins\PluginSettingsDAO;
use PKP\security\Role;
use PKP\tests\PKPTestCase;

class BlockTest extends PKPTestCase
{
    private const PRODUCTION_LOOKS_LIKE = 100;
    private const LOAD_PREFIX = 'visitormap_test_';

    private ?Context $context = null;
    private ?VisitorMapPlugin $plugin = null;
    private array $savedTables = [];
    private array $savedSettings = [];

    protected function setUp(): void
    {
        parent::setUp();

        $contextDao = Application::getContextDAO();
        $this->context = $contextDao->getById((int) DB::table($contextDao->tableName)->min($contextDao->primaryKeyColumn));
        if (!$this->context) {
            $this->markTestSkipped('there is no journal');
        }
        if (DB::table('submissions')->where('status', Submission::STATUS_PUBLISHED)->count() > self::PRODUCTION_LOOKS_LIKE) {
            $this->markTestSkipped('this installation looks like a live site');
        }

        $this->pinContext();
        PluginRegistry::loadCategory('blocks', false, (int) $this->context->getId());
        $this->plugin = PluginRegistry::getPlugin('blocks', 'visitormapplugin');
        if (!$this->plugin) {
            $this->markTestSkipped('the plugin is not installed here');
        }

        (new VisitorMapMigration())->up();
        foreach ([VisitorMapMigration::TABLE_DAILY, VisitorMapMigration::TABLE_MONTHLY, VisitorMapMigration::TABLE_STATE] as $table) {
            $this->savedTables[$table] = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
            DB::table($table)->delete();
        }
        // A recent run, so the block does not queue one while it is tested.
        (new State())->set('lastRun', time());
        foreach (array_merge(VisitorMapSettingsForm::FIELDS, ['blockTitle']) as $name) {
            $this->savedSettings[$name] = $this->plugin->getSetting($this->contextId(), $name);
        }
    }

    protected function tearDown(): void
    {
        $nobody = null;
        Registry::set('user', $nobody);
        foreach ($this->savedTables as $table => $rows) {
            DB::table($table)->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table($table)->insert($chunk);
            }
        }
        $pluginSettings = DAORegistry::getDAO('PluginSettingsDAO'); /** @var PluginSettingsDAO $pluginSettings */
        foreach ($this->savedSettings as $name => $value) {
            if ($value === null) {
                // Never saved before the test: removed, not left empty.
                $pluginSettings->deleteSetting($this->contextId(), $this->plugin->getName(), $name);
            } else {
                $this->plugin->updateSetting($this->contextId(), $name, $value, is_int($value) ? 'int' : (is_bool($value) ? 'bool' : 'string'));
            }
        }
        $this->savedTables = $this->savedSettings = [];
        parent::tearDown();
    }

    public function testTheBlockShowsTheMapTheNumbersAndTheCountries(): void
    {
        $this->configure(['days' => 7, 'topCount' => 3, 'metric' => 'unique', 'showSummary' => true]);
        $this->insertDay(1, ['BR' => [30, 20], 'PT' => [10, 8], 'SG' => [2, 2]]);
        $this->insertDay(2, ['JP' => [5, 5]]);

        $html = $this->render();

        $this->assertMatchesRegularExpression('~<img class="visitor_map__image" src="[^"]*/journals/' . $this->contextId() . '/visitorMap-[0-9a-f]{16}\.svg"~', $html);
        $svg = $this->mapFile($html);
        $this->assertFileExists($svg);
        $this->assertStringContainsString('<path class="c5" d="', (string) file_get_contents($svg));

        $names = Locale::getCountries(Locale::getLocale());
        $this->assertStringContainsString($names->getByAlpha2('BR')->getLocalName(), $html);
        $this->assertStringContainsString($names->getByAlpha2('PT')->getLocalName(), $html);
        $this->assertStringNotContainsString('>' . $names->getByAlpha2('SG')->getLocalName() . '<', $html, 'only the first three countries are listed');
        $this->assertMatchesRegularExpression('~<dd>35</dd>~', $html, 'the unique accesses of the seven days');
        $this->assertMatchesRegularExpression('~<dd>4</dd>~', $html, 'the countries');
    }

    /** The second view reads the cache: the plugin's tables are not queried again. */
    public function testTheSecondViewComesFromTheCache(): void
    {
        $this->configure(['days' => 7]);
        $this->insertDay(1, ['BR' => [3, 3]]);
        $this->render();

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->render();
        $queries = implode("\n", array_column(DB::getQueryLog(), 'query'));
        DB::disableQueryLog();

        $this->assertStringNotContainsString(VisitorMapMigration::TABLE_DAILY, $queries);
        $this->assertStringNotContainsString(VisitorMapMigration::TABLE_MONTHLY, $queries);
    }

    /** New data is a new map file, so no browser keeps showing the old one. */
    public function testNewDataIsANewMapFile(): void
    {
        $this->configure(['days' => 7]);
        $this->insertDay(1, ['BR' => [3, 3]]);
        $before = $this->mapFile($this->render());

        $this->insertDay(2, ['AO' => [9, 9]]);
        $after = $this->mapFile($this->render());

        $this->assertNotSame($before, $after);
        $this->assertFileExists($before, 'the previous map stays for pages cached elsewhere');
    }

    public function testReadersSeeNothingWithoutAccessesAndManagersSeeWhy(): void
    {
        $this->configure(['days' => 1, 'excludedCountries' => implode(',', array_keys(\APP\plugins\blocks\visitorMap\classes\MapRenderer::world()['countries']))]);

        $this->assertSame('', trim($this->render()));

        $managerId = DB::table('user_user_groups as uug')
            ->join('user_groups as ug', 'ug.user_group_id', '=', 'uug.user_group_id')
            ->where('ug.context_id', $this->contextId())->where('ug.role_id', Role::ROLE_ID_MANAGER)
            ->value('uug.user_id');
        if (!$managerId) {
            $this->markTestSkipped('the journal has no manager');
        }
        $manager = \APP\facades\Repo::user()->get((int) $managerId, true);
        Registry::set('user', $manager);
        $html = $this->render();

        $this->assertStringContainsString('block_visitor_map--notice', $html);
        $geo = $this->context->getEnableGeoUsageStats(Application::get()->getRequest()->getSite());
        if ($geo === null || $geo === 'disabled') {
            // The way to the setting, in the words of the core's own menus.
            $this->assertStringContainsString(htmlspecialchars(__('admin.siteSettings')), $html);
            $this->assertStringContainsString(htmlspecialchars(__('manager.setup.statistics')), $html);
        }
    }

    public function testTheRangeEndsYesterdayAndNeverBeforeTheStartDate(): void
    {
        $this->assertSame(['2026-08-30', '2026-09-28'], VisitorMapPlugin::window(30, '', '2026-09-29'));
        $this->assertSame(['2026-09-15', '2026-09-28'], VisitorMapPlugin::window(30, '2026-09-15', '2026-09-29'));
        $this->assertSame(['2026-08-30', '2026-09-28'], VisitorMapPlugin::window(30, '2026-01-01', '2026-09-29'));
        $this->assertSame([null, '2026-09-28'], VisitorMapPlugin::window(0, '', '2026-09-29'));
        $this->assertSame(['2025-02-01', '2026-09-28'], VisitorMapPlugin::window(0, '2025-02-01', '2026-09-29'));
        $this->assertSame(['2026-09-28', '2026-09-28'], VisitorMapPlugin::window(1, '', '2026-09-29 10:00:00'));
    }

    public function testTheSettingsAreChecked(): void
    {
        $this->assertTrue(VisitorMapSettingsForm::isWholeNumberWithin('0', 0, 3650));
        $this->assertTrue(VisitorMapSettingsForm::isWholeNumberWithin(' 365 ', 0, 3650));
        $this->assertFalse(VisitorMapSettingsForm::isWholeNumberWithin('3651', 0, 3650));
        $this->assertFalse(VisitorMapSettingsForm::isWholeNumberWithin('-1', 0, 3650));
        $this->assertFalse(VisitorMapSettingsForm::isWholeNumberWithin('1.5', 0, 3650));
        $this->assertTrue(VisitorMapSettingsForm::isPastDate('2025-02-01'));
        $this->assertFalse(VisitorMapSettingsForm::isPastDate('2025-02-30'));
        $this->assertFalse(VisitorMapSettingsForm::isPastDate(date('Y-m-d', strtotime('+1 day'))));
        $this->assertFalse(VisitorMapSettingsForm::isPastDate('01/02/2025'));
        $this->assertTrue(VisitorMapSettingsForm::isColor('#1F5fbf'));
        $this->assertFalse(VisitorMapSettingsForm::isColor('blue'));
        $this->assertFalse(VisitorMapSettingsForm::isColor('#1f5fbf;background:url(x)'));
        $this->assertTrue(VisitorMapSettingsForm::areCountries('sg, IE;us'));
        $this->assertFalse(VisitorMapSettingsForm::areCountries('SG, XX'));
        $this->assertFalse(VisitorMapSettingsForm::areCountries('Brazil'));
        $this->assertSame(['SG', 'IE', 'US'], VisitorMapPlugin::parseCountries(' sg,IE ;; us sg '));
    }

    public function testDefaultsFillWhatWasNeverSaved(): void
    {
        $pluginSettings = DAORegistry::getDAO('PluginSettingsDAO'); /** @var PluginSettingsDAO $pluginSettings */
        foreach (array_merge(VisitorMapSettingsForm::FIELDS, ['blockTitle']) as $name) {
            $pluginSettings->deleteSetting($this->contextId(), $this->plugin->getName(), $name);
        }
        $settings = $this->plugin->settings($this->contextId());

        $this->assertSame(VisitorMapPlugin::DEFAULTS['days'], $settings['days']);
        $this->assertSame('unique', $settings['metric']);
        $this->assertSame([], $settings['excludedCountries']);
        $this->assertTrue($settings['showSummary']);
        $this->assertTrue($settings['antiScraper'], 'a journal that never saved the form gets the filter');
    }

    /**
     * The form as the manager saves it: an unticked box is not posted at all,
     * and must come back unticked, not as the default.
     */
    public function testTheFilterSwitchedOffStaysOff(): void
    {
        $form = new VisitorMapSettingsForm($this->plugin, $this->contextId());
        $form->initData();
        $form->setData('antiScraper', null);
        $form->execute();

        $this->assertFalse($this->plugin->settings($this->contextId())['antiScraper']);
        $again = new VisitorMapSettingsForm($this->plugin, $this->contextId());
        $again->initData();
        $this->assertFalse((bool) $again->getData('antiScraper'), 'the form opens unticked');

        $again->setData('antiScraper', '1');
        $again->execute();
        $this->assertTrue($this->plugin->settings($this->contextId())['antiScraper']);
    }

    /** Switching the filter changes the block at once, without waiting for a job. */
    public function testSwitchingTheFilterChangesTheBlockAtOnce(): void
    {
        $this->configure(['days' => 7, 'topCount' => 3, 'metric' => 'unique', 'showSummary' => true, 'antiScraper' => true]);
        $day = date('Y-m-d', strtotime(Core::getCurrentDate() . ' -1 day'));
        DB::table(VisitorMapMigration::TABLE_DAILY)->insert([
            ['load_id' => self::LOAD_PREFIX . 'x.log', 'context_id' => $this->contextId(), 'country' => 'US', 'date' => $day, 'metric' => 300, 'metric_unique' => 200, 'metric_clean' => 90, 'metric_unique_clean' => 40],
            ['load_id' => self::LOAD_PREFIX . 'x.log', 'context_id' => $this->contextId(), 'country' => 'BR', 'date' => $day, 'metric' => 120, 'metric_unique' => 100, 'metric_clean' => 118, 'metric_unique_clean' => 97],
        ]);
        (new State())->bumpVersion();

        $html = $this->render();
        $this->assertMatchesRegularExpression('~<dd>137</dd>~', $html, 'Brazil 97 and the United States 40, without scrapers');
        $this->assertLessThan(strpos($html, Locale::getCountries(Locale::getLocale())->getByAlpha2('US')->getLocalName()), strpos($html, Locale::getCountries(Locale::getLocale())->getByAlpha2('BR')->getLocalName()), 'Brazil leads once the scrapers are gone');

        $this->configure(['antiScraper' => false]);
        $this->assertMatchesRegularExpression('~<dd>300</dd>~', $this->render(), 'the core figures with the filter off');
    }

    private function contextId(): int
    {
        return (int) $this->context->getId();
    }

    private function configure(array $settings): void
    {
        foreach ($settings as $name => $value) {
            $this->plugin->updateSetting($this->contextId(), $name, $value, is_int($value) ? 'int' : (is_bool($value) ? 'bool' : 'string'));
        }
        Cache::forget('visitorMap-queued');
    }

    /** Accesses $daysAgo days before today, as the aggregation stores them, and a new version of the data. */
    private function insertDay(int $daysAgo, array $countries): void
    {
        $day = date('Y-m-d', strtotime(Core::getCurrentDate() . " -{$daysAgo} days"));
        foreach ($countries as $country => [$total, $unique]) {
            DB::table(VisitorMapMigration::TABLE_DAILY)->insert([
                'load_id' => self::LOAD_PREFIX . str_replace('-', '', $day) . '.log', 'context_id' => $this->contextId(),
                'country' => $country, 'date' => $day, 'metric' => $total, 'metric_unique' => $unique,
            ]);
        }
        (new State())->bumpVersion();
    }

    private function render(): string
    {
        $request = Application::get()->getRequest();
        $templateMgr = TemplateManager::getManager($request);

        return (string) $this->plugin->getContents($templateMgr, $request);
    }

    private function mapFile(string $html): string
    {
        preg_match('~/journals/\d+/(visitorMap-[0-9a-f]{16}\.svg)~', $html, $match);
        $this->assertNotEmpty($match, 'the block has no map');

        return Core::getBaseDir() . '/public/journals/' . $this->contextId() . '/' . $match[1];
    }

    /** There is no URL on the command line, so the journal is pinned on the router. */
    private function pinContext(): void
    {
        $request = Application::get()->getRequest();
        $router = new class () extends PageRouter {
            public $pinned;

            public function getContext(PKPRequest $request, bool $forceReload = false): ?Context
            {
                return $this->pinned;
            }
        };
        $router->setApplication(Application::get());
        $router->pinned = $this->context;
        $request->setRouter($router);
    }
}
