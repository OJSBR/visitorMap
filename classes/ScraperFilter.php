<?php

/**
 * @file plugins/blocks/visitorMap/classes/ScraperFilter.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ScraperFilter
 *
 * @brief Finds, in the usage log of a day, the accesses of scrapers that pass
 *        for browsers, and counts them the way the core counts accesses.
 *
 *        The core already leaves out the robots that say what they are (the
 *        COUNTER list); what gets through are programs that send a browser's
 *        user agent. Four rules, none of them a list of names:
 *
 *        R1 swarm: one user agent string, on one day, from at least 100
 *           addresses that almost all made a single request. A person reads
 *           more than one page; a pool of proxies does not.
 *        R2 incoherent: Safari's WebKit (605) together with a Chrome token,
 *           which no real browser on a Mac sends. Chrome, Edge, Firefox and
 *           Opera on iOS say so with their own tokens.
 *        R3 not a browser: a user agent that does not start with Mozilla/.
 *        R4 headless: HeadlessChrome.
 *
 *        Only lines the core would count are considered: valid JSON, with a
 *        submission and a country, of an existing journal, and not from a
 *        declared robot. The marked lines are counted as the core counts: the
 *        total with the COUNTER double-click filter (a request followed by the
 *        same one within 30 seconds is dropped), the unique accesses as
 *        distinct journal, address, user agent, submission and hour.
 *
 *        Addresses in the log are already hashed, and nothing read here is
 *        stored: the result is only counts by journal, country and day.
 */

namespace APP\plugins\blocks\visitorMap\classes;

use PKP\core\Core;

class ScraperFilter
{
    public const SWARM_MIN_IPS = 100;
    public const SWARM_SINGLE_RATIO = 0.90;
    public const SWARM_MAX_HITS_PER_IP = 1.15;

    /** The COUNTER double-click window, in seconds, as the core applies it. */
    public const DOUBLE_CLICK_SECONDS = 30;

    public const RULES = ['R1', 'R2', 'R3', 'R4'];

    /** @var array<string,bool> Declared robots, by user agent string. */
    private array $robots = [];

    /**
     * @param int[] $contextIds The journals that exist.
     * @param bool $markAll Count every line the core would count, as if all were
     *                      scrapers: used to check that the counting matches the core.
     */
    public function __construct(private array $contextIds, private bool $markAll = false)
    {
    }

    /**
     * @return array{buckets: array<string,array{metric:int,metric_unique:int}>, rules: array<string,int>}
     *         Counts of the marked accesses by "contextId|country|date", and the
     *         number of lines each rule marked (a line is given to the first rule
     *         that matches).
     */
    public function count(string $path): array
    {
        // First pass: how each user agent behaved on the day. Only the counts
        // by address are kept, not the lines: a busy journal logs hundreds of
        // thousands of lines a day.
        $byAgent = [];
        foreach ($this->lines($path) as $line) {
            $byAgent[$line['ua']][$line['ip']] = ($byAgent[$line['ua']][$line['ip']] ?? 0) + 1;
        }
        $swarms = [];
        foreach ($byAgent as $agent => $addresses) {
            if ($this->isSwarm($addresses)) {
                $swarms[$agent] = true;
            }
        }
        unset($byAgent);

        // Second pass: keep the marked lines only, then count them as the core does.
        $rules = array_fill_keys(self::RULES, 0);
        $marked = [];
        foreach ($this->lines($path) as $index => $line) {
            $rule = $this->markAll ? 'R1' : $this->rule($line['ua'], isset($swarms[$line['ua']]));
            if ($rule === null) {
                continue;
            }
            $rules[$rule]++;
            $marked[$index] = $line;
        }

        return ['buckets' => $this->tally($marked), 'rules' => $rules];
    }

    /**
     * The rule a user agent falls under, or null.
     */
    public function rule(string $agent, bool $swarm): ?string
    {
        return match (true) {
            $swarm => 'R1',
            str_contains($agent, 'AppleWebKit/605') && str_contains($agent, 'Chrome/') && !preg_match('/CriOS|EdgiOS|FxiOS|OPiOS/', $agent) => 'R2',
            !str_starts_with($agent, 'Mozilla/') => 'R3',
            str_contains($agent, 'HeadlessChrome') => 'R4',
            default => null,
        };
    }

    /**
     * Whether the addresses of one user agent, with the number of requests of
     * each, look like a pool of proxies.
     *
     * @param array<string,int> $addresses
     */
    public function isSwarm(array $addresses): bool
    {
        $count = count($addresses);
        if ($count < self::SWARM_MIN_IPS) {
            return false;
        }
        $single = count(array_filter($addresses, fn ($hits) => $hits === 1));

        return $single / $count >= self::SWARM_SINGLE_RATIO
            && array_sum($addresses) / $count <= self::SWARM_MAX_HITS_PER_IP;
    }

    /**
     * The lines of the log the core would count, in the order of the file,
     * keyed by their position in it.
     *
     * @return \Generator<int,array{time:int,date:string,hour:string,ip:string,ua:string,url:string,context:int,submission:int,country:string}>
     */
    private function lines(string $path): \Generator
    {
        $handle = gzopen($path, 'rb');
        if ($handle === false) {
            return;
        }
        $contexts = array_flip($this->contextIds);
        $index = 0;
        try {
            while (($raw = gzgets($handle)) !== false) {
                $index++;
                $entry = json_decode($raw, true);
                if (!is_array($entry) || empty($entry['submissionId']) || empty($entry['country']) || empty($entry['time'])) {
                    continue;
                }
                $context = (int) ($entry['contextId'] ?? 0);
                $agent = (string) ($entry['userAgent'] ?? '');
                if (!isset($contexts[$context]) || $this->isRobot($agent)) {
                    continue;
                }
                $time = strtotime((string) $entry['time']);
                if ($time === false) {
                    continue;
                }
                yield $index => [
                    'time' => $time,
                    'date' => substr((string) $entry['time'], 0, 10),
                    'hour' => substr((string) $entry['time'], 0, 13),
                    'ip' => (string) ($entry['ip'] ?? ''),
                    'ua' => $agent,
                    'url' => (string) ($entry['canonicalUrl'] ?? ''),
                    'context' => $context,
                    'submission' => (int) $entry['submissionId'],
                    'country' => strtoupper((string) $entry['country']),
                ];
            }
        } finally {
            gzclose($handle);
        }
    }

    /** Declared robots, checked once per user agent string: the check is slow. */
    protected function isRobot(string $agent): bool
    {
        return $this->robots[$agent] ??= Core::isUserAgentBot($agent);
    }

    /**
     * Totals with the double clicks left out, and unique accesses.
     *
     * @param array<int,array> $lines Marked lines, keyed by their position in the file.
     *
     * @return array<string,array{metric:int,metric_unique:int}>
     */
    private function tally(array $lines): array
    {
        // A request is dropped when the same one comes again, later and within
        // the window: the core keeps the last of a burst.
        $times = [];
        foreach ($lines as $index => $line) {
            $times[$line['context'] . "\n" . $line['ip'] . "\n" . $line['ua'] . "\n" . $line['url']][] = [$index, $line['time']];
        }
        $dropped = [];
        foreach ($times as $requests) {
            $total = count($requests);
            for ($i = 0, $j = 1; $i < $total; $i++) {
                $j = max($j, $i + 1);
                while ($j < $total && $requests[$j][1] <= $requests[$i][1]) {
                    $j++;
                }
                if ($j < $total && $requests[$j][1] - $requests[$i][1] < self::DOUBLE_CLICK_SECONDS) {
                    $dropped[$requests[$i][0]] = true;
                }
            }
        }

        $buckets = [];
        $unique = [];
        foreach ($lines as $index => $line) {
            $bucket = $line['context'] . '|' . $line['country'] . '|' . $line['date'];
            $buckets[$bucket] ??= ['metric' => 0, 'metric_unique' => 0];
            if (!isset($dropped[$index])) {
                $buckets[$bucket]['metric']++;
            }
            $key = $line['context'] . "\n" . $line['ip'] . "\n" . $line['ua'] . "\n" . $line['submission'] . "\n" . $line['hour'];
            if (!isset($unique[$key])) {
                $unique[$key] = true;
                $buckets[$bucket]['metric_unique']++;
            }
        }

        return $buckets;
    }
}
