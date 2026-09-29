{**
 * plugins/blocks/visitorMap/templates/notice.tpl
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Why there is no map, shown to the journal's managers only.
 *}
<div class="pkp_block block_visitor_map block_visitor_map--notice">
	<h2 class="title">{$visitorMapTitle|escape}</h2>
	<div class="content">
		<p class="visitor_map__notice">{$visitorMapNotice|strip_unsafe_html}</p>
	</div>
</div>
