<?php

/**
 * @file plugins/blocks/visitorMap/VisitorMapSettingsForm.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class VisitorMapSettingsForm
 *
 * @brief What the map covers and how it looks, per journal.
 */

namespace APP\plugins\blocks\visitorMap;

use APP\template\TemplateManager;
use PKP\facades\Locale;
use PKP\form\Form;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorCustom;
use PKP\form\validation\FormValidatorPost;

class VisitorMapSettingsForm extends Form
{
    /** The settings the form reads and writes, besides the title. */
    public const FIELDS = ['days', 'startDate', 'metric', 'showSummary', 'topCount', 'excludedCountries', 'colorLand', 'colorHighlight', 'antiScraper'];

    public function __construct(private VisitorMapPlugin $plugin, private int $contextId)
    {
        parent::__construct($plugin->getTemplateResource('settingsForm.tpl'));

        $this->addCheck(new FormValidatorCustom($this, 'days', 'required', 'plugins.blocks.visitorMap.settings.days.invalid', fn ($value) => self::isWholeNumberWithin($value, 0, VisitorMapPlugin::MAX_DAYS)));
        $this->addCheck(new FormValidatorCustom($this, 'startDate', 'optional', 'plugins.blocks.visitorMap.settings.startDate.invalid', fn ($value) => self::isPastDate((string) $value)));
        $this->addCheck(new FormValidatorCustom($this, 'metric', 'required', 'plugins.blocks.visitorMap.settings.metric.invalid', fn ($value) => in_array($value, ['unique', 'total'], true)));
        $this->addCheck(new FormValidatorCustom($this, 'topCount', 'required', 'plugins.blocks.visitorMap.settings.topCount.invalid', fn ($value) => self::isWholeNumberWithin($value, 0, VisitorMapPlugin::MAX_TOP)));
        $this->addCheck(new FormValidatorCustom($this, 'excludedCountries', 'optional', 'plugins.blocks.visitorMap.settings.excludedCountries.invalid', fn ($value) => self::areCountries((string) $value)));
        $this->addCheck(new FormValidatorCustom($this, 'colorLand', 'required', 'plugins.blocks.visitorMap.settings.color.invalid', fn ($value) => self::isColor((string) $value)));
        $this->addCheck(new FormValidatorCustom($this, 'colorHighlight', 'required', 'plugins.blocks.visitorMap.settings.color.invalid', fn ($value) => self::isColor((string) $value)));
        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
    }

    public static function isWholeNumberWithin($value, int $min, int $max): bool
    {
        $value = trim((string) $value);

        return ctype_digit($value) && (int) $value >= $min && (int) $value <= $max;
    }

    /** A real day, YYYY-MM-DD, not after today. */
    public static function isPastDate(string $value): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $match) || !checkdate((int) $match[2], (int) $match[3], (int) $match[1])) {
            return false;
        }

        return strcmp($value, date('Y-m-d')) <= 0;
    }

    public static function isColor(string $value): bool
    {
        return (bool) preg_match('/^#[0-9a-fA-F]{6}$/', $value);
    }

    /** Every code of the list is a country the application knows. */
    public static function areCountries(string $list): bool
    {
        $countries = Locale::getCountries();
        foreach (VisitorMapPlugin::parseCountries($list) as $code) {
            if (strlen($code) !== 2 || (!$countries->getByAlpha2($code) && $code !== 'XK')) {
                return false;
            }
        }

        return true;
    }

    public function initData(): void
    {
        $settings = $this->plugin->settings($this->contextId);
        $settings['excludedCountries'] = implode(', ', $settings['excludedCountries']);
        $settings['blockTitle'] = json_decode((string) $this->plugin->getSetting($this->contextId, 'blockTitle'), true) ?: [];
        $this->_data = $settings;
    }

    public function readInputData(): void
    {
        $this->readUserVars(array_merge(self::FIELDS, ['blockTitle']));
        $this->setData('days', trim((string) $this->getData('days')));
        $this->setData('topCount', trim((string) $this->getData('topCount')));
        $this->setData('startDate', trim((string) $this->getData('startDate')));
    }

    public function fetch($request, $template = null, $display = false)
    {
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign([
            'pluginName' => $this->plugin->getName(),
            'maxDays' => VisitorMapPlugin::MAX_DAYS,
            'maxTop' => VisitorMapPlugin::MAX_TOP,
            'today' => date('Y-m-d'),
            'metricOptions' => [
                'unique' => 'plugins.blocks.visitorMap.metric.unique',
                'total' => 'plugins.blocks.visitorMap.metric.total',
            ],
        ]);

        return parent::fetch($request, $template, $display);
    }

    public function execute(...$functionArgs)
    {
        $plugin = $this->plugin;
        $contextId = $this->contextId;

        $plugin->updateSetting($contextId, 'days', (int) $this->getData('days'), 'int');
        $plugin->updateSetting($contextId, 'startDate', (string) $this->getData('startDate'), 'string');
        $plugin->updateSetting($contextId, 'metric', (string) $this->getData('metric'), 'string');
        $plugin->updateSetting($contextId, 'showSummary', (bool) $this->getData('showSummary'), 'bool');
        $plugin->updateSetting($contextId, 'topCount', (int) $this->getData('topCount'), 'int');
        $plugin->updateSetting($contextId, 'excludedCountries', implode(',', VisitorMapPlugin::parseCountries((string) $this->getData('excludedCountries'))), 'string');
        $plugin->updateSetting($contextId, 'colorLand', strtolower((string) $this->getData('colorLand')), 'string');
        $plugin->updateSetting($contextId, 'colorHighlight', strtolower((string) $this->getData('colorHighlight')), 'string');
        // An unticked box is not posted at all: no value means off.
        $plugin->updateSetting($contextId, 'antiScraper', (bool) $this->getData('antiScraper'), 'bool');

        $titles = array_filter(
            array_map(fn ($title) => trim(strip_tags((string) $title)), (array) $this->getData('blockTitle')),
            'strlen'
        );
        $plugin->updateSetting($contextId, 'blockTitle', json_encode($titles, JSON_UNESCAPED_UNICODE), 'string');

        // The block's cache is keyed by the settings, so there is nothing to clear.
        return parent::execute(...$functionArgs);
    }
}
