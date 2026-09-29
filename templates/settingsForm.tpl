{**
 * plugins/blocks/visitorMap/templates/settingsForm.tpl
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Settings of the visitor map.
 *}
<script type="text/javascript">
	$(function() {ldelim}
		$('#visitorMapSettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>

<form class="pkp_form" id="visitorMapSettingsForm" method="post" action="{url router=PKP\core\PKPApplication::ROUTE_COMPONENT op="manage" category="blocks" plugin=$pluginName verb="settings" save=true}">
	{csrf}

	{include file="controllers/notification/inPlaceNotification.tpl" notificationId="visitorMapFormNotification"}

	<p>{translate key="plugins.blocks.visitorMap.settings.intro"}</p>

	{fbvFormArea id="visitorMapPeriod" title="plugins.blocks.visitorMap.settings.period"}
		{fbvFormSection for="days" description="plugins.blocks.visitorMap.settings.days.description"}
			{fbvElement type="text" label="plugins.blocks.visitorMap.settings.days" id="days" value=$days size=$fbvStyles.size.SMALL inputmode="numeric"}
		{/fbvFormSection}
		{fbvFormSection for="startDate" description="plugins.blocks.visitorMap.settings.startDate.description"}
			<label class="label" for="visitorMapStartDate">{translate key="plugins.blocks.visitorMap.settings.startDate"}</label>
			<input type="date" id="visitorMapStartDate" name="startDate" value="{$startDate|escape}" max="{$today|escape}">
		{/fbvFormSection}
		{fbvFormSection for="metric" description="plugins.blocks.visitorMap.settings.metric.description"}
			{fbvElement type="select" label="plugins.blocks.visitorMap.settings.metric" id="metric" from=$metricOptions selected=$metric translate=true size=$fbvStyles.size.MEDIUM}
		{/fbvFormSection}
		{fbvFormSection for="excludedCountries" description="plugins.blocks.visitorMap.settings.excludedCountries.description"}
			{fbvElement type="text" label="plugins.blocks.visitorMap.settings.excludedCountries" id="excludedCountries" value=$excludedCountries size=$fbvStyles.size.MEDIUM}
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormArea id="visitorMapDisplay" title="plugins.blocks.visitorMap.settings.display"}
		{fbvFormSection for="blockTitle"}
			{fbvElement type="text" label="plugins.blocks.visitorMap.settings.blockTitle" id="blockTitle" value=$blockTitle multilingual=true}
		{/fbvFormSection}
		{fbvFormSection list=true}
			{fbvElement type="checkbox" id="showSummary" checked=$showSummary label="plugins.blocks.visitorMap.settings.showSummary"}
		{/fbvFormSection}
		{fbvFormSection for="topCount" description="plugins.blocks.visitorMap.settings.topCount.description"}
			{fbvElement type="text" label="plugins.blocks.visitorMap.settings.topCount" id="topCount" value=$topCount size=$fbvStyles.size.SMALL inputmode="numeric"}
		{/fbvFormSection}
		{fbvFormSection}
			<label class="label" for="visitorMapColorLand">{translate key="plugins.blocks.visitorMap.settings.colorLand"}</label>
			<input type="color" id="visitorMapColorLand" name="colorLand" value="{$colorLand|escape}">
			<label class="label" for="visitorMapColorHighlight">{translate key="plugins.blocks.visitorMap.settings.colorHighlight"}</label>
			<input type="color" id="visitorMapColorHighlight" name="colorHighlight" value="{$colorHighlight|escape}">
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormButtons}
</form>
