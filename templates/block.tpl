{**
 * plugins/blocks/visitorMap/templates/block.tpl
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * The visitor map block. The map is a static image; the numbers and the list
 * of countries are text, so they reach screen readers and search engines.
 * Blocks are drawn after the page head, so the stylesheet is linked here.
 *}
<link rel="stylesheet" type="text/css" href="{$visitorMapStyle|escape}">
<div class="pkp_block block_visitor_map">
	<h2 class="title">{$visitorMapTitle|escape}</h2>
	<div class="content">
		<figure class="visitor_map">
			<img class="visitor_map__image" src="{$visitorMapImage|escape}" width="{$visitorMapWidth|escape}" height="{$visitorMapHeight|escape}" alt="{translate|escape key="plugins.blocks.visitorMap.alt"}" loading="lazy" decoding="async">
			<figcaption class="visitor_map__legend">
				<span class="visitor_map__legendValue">{$visitorMapLegendMin|escape}</span>
				<span class="visitor_map__legendScale" aria-hidden="true">{foreach from=$visitorMapLegend item=color}<span style="background-color:{$color|escape}"></span>{/foreach}</span>
				<span class="visitor_map__legendValue">{$visitorMapLegendMax|escape}</span>
			</figcaption>
		</figure>

		{if $visitorMapShowSummary}
			<dl class="visitor_map__summary">
				<div>
					<dt>{$visitorMapMetric|escape}</dt>
					<dd>{$visitorMapTotal|escape}</dd>
				</div>
				<div>
					<dt>{translate key="plugins.blocks.visitorMap.countries"}</dt>
					<dd>{$visitorMapCountries|escape}</dd>
				</div>
			</dl>
			<p class="visitor_map__period">{$visitorMapPeriod|escape}</p>
		{/if}

		{if $visitorMapTop}
			<ol class="visitor_map__top" aria-label="{translate|escape key="plugins.blocks.visitorMap.topCountries"}">
				{foreach from=$visitorMapTop item=country}
					<li>
						<span class="visitor_map__country">{$country.name|escape}</span>
						<span class="visitor_map__value">{$country.total|escape}</span>
						<span class="visitor_map__bar" aria-hidden="true"><span style="width:{$country.share|escape}%"></span></span>
					</li>
				{/foreach}
			</ol>
		{/if}
	</div>
</div>
