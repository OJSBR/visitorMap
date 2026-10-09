<?php

/**
 * @file plugins/blocks/visitorMap/VisitorMapPlugin.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class VisitorMapPlugin
 *
 * @ingroup plugins_blocks_visitorMap
 *
 * @brief A sidebar block with a world map of where the accesses of the journal
 *        come from, over the last days or since a date.
 *
 *        The data is the geographic usage statistics OJS already keeps; nothing
 *        is collected and the reader's browser talks to no one else. The core
 *        table cannot be read by date on every page, so a queued job sums it
 *        into small tables of the plugin (see Aggregator), and the block reads
 *        those. The map is written once as a static SVG in the journal's public
 *        folder, named after its content, and the rest of the block is cached.
 */

namespace APP\plugins\blocks\visitorMap;

use APP\core\Application;
use APP\file\PublicFileManager;
use APP\plugins\blocks\visitorMap\classes\AggregateJob;
use APP\plugins\blocks\visitorMap\classes\AggregateTask;
use APP\plugins\blocks\visitorMap\classes\MapRenderer;
use APP\plugins\blocks\visitorMap\classes\migration\VisitorMapMigration;
use APP\plugins\blocks\visitorMap\classes\Repository;
use APP\plugins\blocks\visitorMap\classes\State;
use Illuminate\Support\Facades\Cache;
use IntlDateFormatter;
use NumberFormatter;
use PKP\core\Core;
use PKP\core\JSONMessage;
use PKP\facades\Locale;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\plugins\BlockPlugin;
use PKP\plugins\interfaces\HasTaskScheduler;
use PKP\scheduledTask\PKPScheduler;
use PKP\security\Role;
use Throwable;

class VisitorMapPlugin extends BlockPlugin implements HasTaskScheduler
{
    /** Settings and their defaults. */
    public const DEFAULTS = [
        'days' => 30,
        'startDate' => '',
        'metric' => 'unique',
        'showSummary' => true,
        'topCount' => 5,
        'excludedCountries' => '',
        'colorLand' => '#dde1e6',
        'colorHighlight' => '#1f5fbf',
        'antiScraper' => true,
    ];

    /** Settings that are switches. */
    public const SWITCHES = ['showSummary', 'antiScraper'];
    public const MAX_DAYS = 3650;
    public const MAX_TOP = 20;

    /** How long the block keeps what it computed. New data changes the key anyway. */
    public const CACHE_TTL = 6 * 60 * 60;

    /** After this long without a run, a page view queues one. */
    public const STALE_AFTER = 6 * 60 * 60;

    /**
     * Priority of locale-omp over the plugin's own locale (registered at 0):
     * above it, and below any override a press makes with Custom Locale.
     */
    public const OMP_LOCALE_PRIORITY = 1;

    /**
     * On OMP, the texts that name the journal, its articles and OJS come from
     * locale-omp, which names the press, its books and OMP instead. Only the
     * keys of this plugin are there, so nothing of the core changes.
     *
     * @param string $category
     * @param string $path
     * @param null|int $mainContextId
     */
    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if ($success && Application::get()->getName() === 'omp') {
            Locale::registerPath(Core::getBaseDir() . '/' . $this->getPluginPath() . '/locale-omp', self::OMP_LOCALE_PRIORITY);
        }

        return $success;
    }

    public function getDisplayName(): string
    {
        return __('plugins.blocks.visitorMap.displayName');
    }

    public function getDescription(): string
    {
        return __('plugins.blocks.visitorMap.description');
    }

    public function getContextSpecificPluginSettingsFile(): string
    {
        return $this->getPluginPath() . '/settings.xml';
    }

    public function getInstallMigration(): VisitorMapMigration
    {
        return new VisitorMapMigration();
    }

    /**
     * Enabling also creates the tables, for a copy that was put in place by hand
     * and never installed, and starts filling them right away.
     *
     * @param bool $enabled
     * @param null|mixed $contextId
     */
    public function setEnabled($enabled, $contextId = null)
    {
        parent::setEnabled($enabled, $contextId);
        if ($enabled) {
            (new VisitorMapMigration())->up();
            dispatch(new AggregateJob());
        }
    }

    public function registerSchedules(PKPScheduler $scheduler): void
    {
        $scheduler
            ->addSchedule(new AggregateTask())
            ->hourly()
            ->name(AggregateTask::class)
            ->withoutOverlapping();
    }

    public function getActions($request, $actionArgs): array
    {
        // The settings belong to a journal; there is nothing to set up for the site.
        if (!$request->getContext() || !$this->getEnabled()) {
            return parent::getActions($request, $actionArgs);
        }
        $router = $request->getRouter();

        return array_merge(
            [
                new LinkAction(
                    'settings',
                    new AjaxModal(
                        $router->url($request, null, null, 'manage', null, array_merge($actionArgs, ['verb' => 'settings'])),
                        $this->getDisplayName()
                    ),
                    __('manager.plugins.settings'),
                    null
                ),
            ],
            parent::getActions($request, $actionArgs)
        );
    }

    public function manage($args, $request): JSONMessage
    {
        $context = $request->getContext();
        if ($request->getUserVar('verb') !== 'settings' || !$context) {
            return parent::manage($args, $request);
        }

        $form = new VisitorMapSettingsForm($this, (int) $context->getId());
        if ($request->getUserVar('save')) {
            $form->readInputData();
            if ($form->validate()) {
                $form->execute();
                return new JSONMessage(true);
            }
        } else {
            $form->initData();
        }

        return new JSONMessage(true, $form->fetch($request));
    }

    /**
     * The settings of a journal, with the defaults filled in.
     *
     * @return array<string,mixed>
     */
    public function settings(int $contextId): array
    {
        $settings = [];
        foreach (self::DEFAULTS as $name => $default) {
            $value = $this->getSetting($contextId, $name);
            if (in_array($name, self::SWITCHES, true)) {
                // Never saved: the default. Saved: whatever was saved, and a
                // switch saved off stays off however the database returns it.
                $settings[$name] = $value === null ? $default : in_array($value, [true, 1, '1', 'true', 'on'], true);
                continue;
            }
            $settings[$name] = $value === null || $value === '' ? $default : $value;
        }
        $settings['days'] = max(0, min(self::MAX_DAYS, (int) $settings['days']));
        $settings['topCount'] = max(0, min(self::MAX_TOP, (int) $settings['topCount']));
        $settings['metric'] = $settings['metric'] === 'total' ? 'total' : 'unique';
        $settings['startDate'] = (string) ($this->getSetting($contextId, 'startDate') ?? '');
        $settings['excludedCountries'] = self::parseCountries((string) $settings['excludedCountries']);

        return $settings;
    }

    /**
     * The days the map covers: the last $days days up to yesterday (today is
     * not in the statistics yet), never before the start date. With no number
     * of days, everything since the start date.
     *
     * @return array{0: ?string, 1: string} First and last day, YYYY-MM-DD; no first day means from the beginning.
     */
    public static function window(int $days, string $startDate, string $today): array
    {
        $to = date('Y-m-d', strtotime($today . ' -1 day'));
        $from = $days > 0 ? date('Y-m-d', strtotime($to . ' -' . ($days - 1) . ' days')) : null;
        if ($startDate !== '' && ($from === null || strcmp($startDate, $from) > 0)) {
            $from = $startDate;
        }

        return [$from, $to];
    }

    /**
     * ISO 3166-1 alpha-2 codes from a list separated by commas or spaces.
     *
     * @return string[]
     */
    public static function parseCountries(string $list): array
    {
        $codes = preg_split('/[\s,;]+/', strtoupper(trim($list)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique($codes));
    }

    /**
     * The core draws every block of the sidebar inside one hook: an error here
     * would also take away every block after this one. So the block logs what
     * went wrong and steps aside.
     *
     * @param null|mixed $request
     */
    public function getContents($templateMgr, $request = null): string
    {
        try {
            return $this->render($templateMgr, $request ?? Application::get()->getRequest());
        } catch (Throwable $error) {
            error_log('visitorMap: the block could not be drawn: ' . $error);

            return '';
        }
    }

    private function render($templateMgr, $request): string
    {
        $context = $request->getContext();
        if (!$context) {
            return '';
        }
        $contextId = (int) $context->getId();
        $settings = $this->settings($contextId);
        [$from, $to] = self::window($settings['days'], $settings['startDate'], Core::getCurrentDate());

        try {
            $state = (new State())->getMany(['version', 'lastRun']);
        } catch (Throwable $error) {
            // The tables are not there yet: enabling the plugin creates them.
            return $this->notice($templateMgr, $request, $contextId, 'plugins.blocks.visitorMap.notice.noData');
        }
        $this->queueUpdateIfStale((int) ($state['lastRun'] ?? 0));

        $key = 'visitorMap-' . $contextId . '-' . md5(json_encode([$state['version'] ?? 0, $from, $to, $settings]));
        $data = Cache::remember($key, self::CACHE_TTL, fn () => $this->compute($request, $contextId, $from, $to, $settings));

        // The file may have been removed from the public folder since it was cached.
        if ($data['total'] > 0 && !is_file($data['svgPath'])) {
            Cache::forget($key);
            $data = $this->compute($request, $contextId, $from, $to, $settings);
        }

        if ($data['total'] === 0) {
            $geo = $context->getEnableGeoUsageStats($request->getSite());
            $message = $geo === null || $geo === 'disabled'
                ? 'plugins.blocks.visitorMap.notice.geoDisabled'
                : ($data['firstDay'] === null ? 'plugins.blocks.visitorMap.notice.noData' : 'plugins.blocks.visitorMap.notice.emptyRange');

            return $this->notice($templateMgr, $request, $contextId, $message);
        }

        $locale = Locale::getLocale();
        $numbers = new NumberFormatter($locale, NumberFormatter::DECIMAL);
        $countryNames = Locale::getCountries($locale);
        $top = [];
        foreach (array_slice($data['totals'], 0, $settings['topCount'], true) as $code => $total) {
            $top[] = [
                'code' => $code,
                'name' => self::countryName($countryNames, $code),
                'total' => $numbers->format($total),
                'share' => round(100 * $total / $data['max'], 1),
            ];
        }

        $titles = json_decode((string) $this->getSetting($contextId, 'blockTitle'), true) ?: [];
        $sinceStart = $settings['days'] === 0 || ($from !== null && $from === $settings['startDate']);
        $templateMgr->assign([
            // Blocks are drawn after the page head, so the template links the stylesheet.
            'visitorMapStyle' => $request->getBaseUrl() . '/' . $this->getPluginPath() . '/css/visitorMap.css',
            'visitorMapTitle' => $titles[$locale] ?? __('plugins.blocks.visitorMap.defaultTitle'),
            'visitorMapImage' => $data['svgUrl'],
            'visitorMapWidth' => $data['width'],
            'visitorMapHeight' => $data['height'],
            'visitorMapShowSummary' => $settings['showSummary'],
            'visitorMapTotal' => $numbers->format($data['total']),
            'visitorMapCountries' => $numbers->format($data['countries']),
            'visitorMapPeriod' => $sinceStart
                ? __('plugins.blocks.visitorMap.since', ['date' => $this->formatDate($from ?? $data['firstDay'], $locale)])
                : __('plugins.blocks.visitorMap.lastDays', ['days' => $numbers->format($settings['days'])]),
            'visitorMapMetric' => __($settings['metric'] === 'total' ? 'plugins.blocks.visitorMap.metric.total' : 'plugins.blocks.visitorMap.metric.unique'),
            'visitorMapLegend' => array_slice($data['palette'], 1),
            'visitorMapLegendMin' => $numbers->format(1),
            'visitorMapLegendMax' => $numbers->format($data['max']),
            'visitorMapTop' => $top,
            'visitorMapFilteredFrom' => $data['filteredFrom'] === null ? '' : __('plugins.blocks.visitorMap.filteredFrom', ['date' => $this->formatDate($data['filteredFrom'], $locale)]),
        ]);

        return parent::getContents($templateMgr, $request);
    }

    /**
     * Everything the block shows that depends on the data, computed once per
     * version of the data, range and settings. The map is written to the
     * journal's public folder under a name taken from its content.
     */
    public function compute($request, int $contextId, ?string $from, string $to, array $settings): array
    {
        $repository = new Repository();
        $totals = $repository->byCountry($contextId, $from, $to, $settings['metric'] === 'unique', $settings['excludedCountries'], $settings['antiScraper']);
        $filteredFrom = $settings['antiScraper'] ? $repository->firstFilteredDay($contextId) : null;
        $renderer = new MapRenderer((string) $settings['colorLand'], (string) $settings['colorHighlight']);
        $world = MapRenderer::world();

        $data = [
            'totals' => $totals,
            'total' => array_sum($totals),
            'countries' => count($totals),
            'max' => $totals ? max($totals) : 0,
            'firstDay' => $repository->firstDay($contextId),
            // The filter only reaches days whose usage log was still there: say
            // so when the period starts before the first of them.
            'filteredFrom' => $filteredFrom !== null && ($from === null || strcmp($from, $filteredFrom) < 0) ? $filteredFrom : null,
            'palette' => $renderer->palette(),
            'width' => $world['width'],
            'height' => $world['height'],
            'svgPath' => '',
            'svgUrl' => '',
        ];
        if (!$totals) {
            return $data;
        }

        $svg = $renderer->render($totals);
        $folder = (new PublicFileManager())->getContextFilesPath($contextId);
        $name = 'visitorMap-' . substr(sha1($svg), 0, 16) . '.svg';
        $path = Core::getBaseDir() . '/' . $folder . '/' . $name;
        if (!is_file($path)) {
            $this->writeMap($path, $svg);
        }
        $data['svgPath'] = $path;
        $data['svgUrl'] = $request->getBaseUrl() . '/' . $folder . '/' . $name;

        return $data;
    }

    /**
     * Write the map in one step, so no page ever links to half a file, and let
     * go of the maps of previous days. Pages cached elsewhere may still point to
     * a recent one, so only files older than two days are removed.
     */
    private function writeMap(string $path, string $svg): void
    {
        $folder = dirname($path);
        if (!is_dir($folder)) {
            mkdir($folder, 0755, true);
        }
        $temporary = $path . '.' . getmypid() . '.tmp';
        file_put_contents($temporary, $svg);
        rename($temporary, $path);

        foreach (glob($folder . '/visitorMap-*.svg') ?: [] as $old) {
            if ($old !== $path && filemtime($old) < time() - 2 * 24 * 60 * 60) {
                unlink($old);
            }
        }
    }

    /**
     * Queue an update when the last one is old. The scheduled task does this
     * too, but the task only reaches a plugin enabled for a journal when the
     * scheduler runs inside a request of that journal; a page view is the
     * other way in. The cache entry keeps it to one job per half hour.
     */
    private function queueUpdateIfStale(int $lastRun): void
    {
        if (time() - $lastRun > self::STALE_AFTER && Cache::add('visitorMap-queued', time(), 30 * 60)) {
            dispatch(new AggregateJob());
        }
    }

    /**
     * What the block says when there is no map: only the journal's managers see
     * it, readers see nothing.
     */
    private function notice($templateMgr, $request, int $contextId, string $message): string
    {
        $user = $request->getUser();
        $isManager = $user && (
            $user->hasRole([Role::ROLE_ID_MANAGER], $contextId)
            || $user->hasRole([Role::ROLE_ID_SITE_ADMIN], Application::SITE_CONTEXT_ID)
        );
        if (!$isManager) {
            return '';
        }

        // The way to the setting, in the words of the menus the manager sees.
        $path = implode(' › ', [__('navigation.admin'), __('admin.siteSettings'), __('manager.setup.statistics')]);
        $templateMgr->assign([
            'visitorMapTitle' => __('plugins.blocks.visitorMap.displayName'),
            'visitorMapNotice' => __($message, ['path' => $path]),
        ]);

        return $templateMgr->fetch($this->getTemplateResource('notice.tpl'));
    }

    /**
     * The name of a country in the reader's language. Kosovo (XK) is reported by
     * the geolocation database but is not in the list of countries of the
     * application, so its name comes from the plugin.
     */
    public static function countryName($countries, string $code): string
    {
        $country = $countries->getByAlpha2($code);
        if ($country) {
            return $country->getLocalName();
        }

        return $code === 'XK' ? __('plugins.blocks.visitorMap.country.XK') : $code;
    }

    private function formatDate(?string $date, string $locale): string
    {
        if ($date === null) {
            return '';
        }
        $formatter = new IntlDateFormatter($locale, IntlDateFormatter::MEDIUM, IntlDateFormatter::NONE, 'UTC');

        return (string) $formatter->format(new \DateTimeImmutable($date, new \DateTimeZone('UTC')));
    }
}
