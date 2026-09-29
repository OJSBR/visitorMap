<?php

/**
 * @file plugins/blocks/visitorMap/classes/AggregateTask.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class AggregateTask
 *
 * @brief The scheduled update of the plugin's tables. The work itself is done
 *        by AggregateJob, in the queue.
 */

namespace APP\plugins\blocks\visitorMap\classes;

use PKP\scheduledTask\ScheduledTask;

class AggregateTask extends ScheduledTask
{
    public function getName(): string
    {
        return __('plugins.blocks.visitorMap.task.name');
    }

    protected function executeActions(): bool
    {
        dispatch(new AggregateJob());

        return true;
    }
}
