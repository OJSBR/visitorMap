<?php

/**
 * @file plugins/blocks/visitorMap/classes/AggregateJob.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class AggregateJob
 *
 * @brief Brings the plugin's tables up to date, in the queue.
 *
 *        Each job works for a limited time, well inside the time the queue
 *        allows a job, and queues the next one when there is more to do: the
 *        first run on a journal with years of statistics takes several jobs.
 */

namespace APP\plugins\blocks\visitorMap\classes;

use PKP\jobs\BaseJob;

class AggregateJob extends BaseJob
{
    /** Seconds of work per job. */
    public const BUDGET = 15.0;

    public function handle(): void
    {
        if (!(new Aggregator())->run(self::BUDGET)) {
            dispatch(new self());
        }
    }
}
