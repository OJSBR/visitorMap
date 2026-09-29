<?php

/**
 * @file plugins/blocks/visitorMap/tests/ScraperFilterTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ScraperFilterTest
 *
 * @brief The rules of the scraper filter and its counting, on a usage log
 *        written line by line in the format of OJS 3.5.
 */

namespace APP\plugins\blocks\visitorMap\tests;

use APP\plugins\blocks\visitorMap\classes\ScraperFilter;
use PKP\core\Core;
use PKP\tests\PKPTestCase;

class ScraperFilterTest extends PKPTestCase
{
    private const DAY = '2099-01-10';
    private const SWARM = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36';
    private const READER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36';
    private const INCOHERENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/605.1.15';
    private const IOS_CHROME = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/139.0.0.0 Chrome/139.0.0.0 Mobile/15E148 Safari/604.1';
    private const TOOL = 'msh-pdfmin/1';
    private const HEADLESS = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/120.0.0.0 Safari/537.36';
    private const DECLARED_ROBOT = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

    private string $path = '';

    protected function tearDown(): void
    {
        if ($this->path !== '' && is_file($this->path)) {
            unlink($this->path);
        }
        parent::tearDown();
    }

    public function testTheRulesMarkScrapersAndLeaveReadersAlone(): void
    {
        $lines = [];
        // R1: 120 addresses, one request each, the same browser string.
        for ($i = 0; $i < 120; $i++) {
            $lines[] = $this->line('swarm' . $i, self::SWARM, 'US', 10 + $i % 5, sprintf('03:%02d:%02d', intdiv($i, 60), $i % 60));
        }
        // Readers: 60 addresses, two articles each, minutes apart.
        for ($i = 0; $i < 60; $i++) {
            $lines[] = $this->line('reader' . $i, self::READER, 'BR', 10, sprintf('10:%02d:00', $i % 60));
            $lines[] = $this->line('reader' . $i, self::READER, 'BR', 11, sprintf('11:%02d:00', $i % 60));
        }
        // R2, and a real iOS browser that must not be taken for it.
        for ($i = 0; $i < 7; $i++) {
            $lines[] = $this->line('mac' . $i, self::INCOHERENT, 'US', 12, sprintf('05:0%d:00', $i));
            $lines[] = $this->line('ios' . $i, self::IOS_CHROME, 'PT', 12, sprintf('05:0%d:00', $i));
        }
        // R3 and R4.
        for ($i = 0; $i < 4; $i++) {
            $lines[] = $this->line('tool' . $i, self::TOOL, 'SG', 13, sprintf('06:0%d:00', $i));
            $lines[] = $this->line('headless' . $i, self::HEADLESS, 'DE', 13, sprintf('06:0%d:00', $i));
        }
        // What the core does not count, and the filter must not either.
        $lines[] = $this->line('bot', self::DECLARED_ROBOT, 'US', 10, '07:00:00');
        $lines[] = $this->line('nocountry', self::TOOL, '', 10, '07:00:00');
        $lines[] = $this->line('nosubmission', self::TOOL, 'SG', null, '07:00:00');
        $lines[] = $this->line('othercontext', self::TOOL, 'SG', 10, '07:00:00', 999);
        $lines[] = '{broken json';

        $result = (new ScraperFilter([1]))->count($this->write($lines));

        $headlessIsDeclared = Core::isUserAgentBot(self::HEADLESS);
        $this->assertSame(120, $result['rules']['R1']);
        $this->assertSame(7, $result['rules']['R2'], 'Safari WebKit with a Chrome token; the iOS Chrome with CriOS is left alone');
        $this->assertSame(4, $result['rules']['R3']);
        $this->assertSame($headlessIsDeclared ? 0 : 4, $result['rules']['R4'], 'HeadlessChrome, unless the COUNTER list already drops it');
        $this->assertFalse(Core::isUserAgentBot(self::SWARM), 'the swarm passes for a browser');

        $this->assertSame(['metric' => 127, 'metric_unique' => 127], $result['buckets']['1|US|' . self::DAY]);
        $this->assertSame(['metric' => 4, 'metric_unique' => 4], $result['buckets']['1|SG|' . self::DAY]);
        $this->assertArrayNotHasKey('1|BR|' . self::DAY, $result['buckets'], 'readers are never marked');
        $this->assertArrayNotHasKey('1|PT|' . self::DAY, $result['buckets']);
    }

    /** The swarm rule needs all three signs, not one of them. */
    public function testASwarmNeedsManySingleRequestAddresses(): void
    {
        $filter = new ScraperFilter([1]);

        $this->assertTrue($filter->isSwarm(array_fill(0, 100, 1)));
        $this->assertFalse($filter->isSwarm(array_fill(0, 99, 1)), 'fewer than 100 addresses');
        $this->assertFalse($filter->isSwarm(array_merge(array_fill(0, 85, 1), array_fill(0, 15, 1 + 1))), '85% single-request addresses');
        $this->assertFalse($filter->isSwarm(array_merge(array_fill(0, 95, 1), array_fill(0, 5, 10))), '1.45 requests per address');
        $this->assertSame('R3', $filter->rule('Lightpanda/1.0', false));
        $this->assertNull($filter->rule(self::READER, false));
    }

    /**
     * Counting everything, the filter must give what the core gives: a request
     * repeated within 30 seconds counts once (the last one is kept), the same
     * second does not make a double click, and unique accesses are one per
     * address, browser, article and hour.
     */
    public function testMarkingEverythingCountsLikeTheCore(): void
    {
        $lines = [
            $this->line('a', self::READER, 'BR', 10, '09:00:00'),
            $this->line('a', self::READER, 'BR', 10, '09:00:10'),
            $this->line('a', self::READER, 'BR', 10, '09:00:50'),
            $this->line('a', self::READER, 'BR', 10, '09:00:50'),
            $this->line('a', self::READER, 'BR', 10, '10:30:00'),
            $this->line('b', self::READER, 'BR', 11, '09:00:00'),
        ];

        $result = (new ScraperFilter([1], true))->count($this->write($lines));

        // a: 09:00:00 is followed by 09:00:10 (dropped); 09:00:10 by 09:00:50,
        // 40 s later (kept); the two at 09:00:50 are the same second (both
        // kept); 10:30 (kept); b (kept). Unique: a/10 at 09h and at 10h, b/11.
        $this->assertSame(['metric' => 5, 'metric_unique' => 3], $result['buckets']['1|BR|' . self::DAY]);
    }

    public function testACompressedLogIsReadToo(): void
    {
        $plain = $this->write([$this->line('x', self::TOOL, 'SG', 10, '08:00:00')]);
        $this->path = $plain . '.gz';
        file_put_contents($this->path, gzencode((string) file_get_contents($plain)));
        unlink($plain);

        $this->assertSame(1, (new ScraperFilter([1]))->count($this->path)['rules']['R3']);
    }

    /** One line of the usage log, as OJS 3.5 writes it. */
    private function line(string $visitor, string $agent, string $country, ?int $submission, string $time, int $context = 1): string
    {
        return json_encode([
            'time' => self::DAY . ' ' . $time,
            'ip' => hash('sha256', $visitor),
            'userAgent' => $agent,
            'canonicalUrl' => 'https://example.org/index.php/j/article/view/' . $submission,
            'assocType' => 1048585,
            'contextId' => $context,
            'submissionId' => $submission,
            'representationId' => null,
            'submissionFileId' => null,
            'fileType' => null,
            'country' => $country === '' ? null : $country,
            'region' => null,
            'city' => null,
            'institutionIds' => [],
            'version' => '3.5.0.3',
            'issueId' => 1,
            'issueGalleyId' => null,
        ]);
    }

    private function write(array $lines): string
    {
        $this->path = tempnam(sys_get_temp_dir(), 'vmlog');
        file_put_contents($this->path, implode("\n", $lines) . "\n");

        return $this->path;
    }
}
