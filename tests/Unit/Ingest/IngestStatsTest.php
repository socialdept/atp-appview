<?php

namespace SocialDept\AtpAppview\Tests\Unit\Ingest;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use PHPUnit\Framework\TestCase;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Contracts\BatchHandler;
use SocialDept\AtpAppview\Ingest\Events\IdentityEvent;
use SocialDept\AtpAppview\Ingest\Events\RecordAction;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;
use SocialDept\AtpAppview\Ingest\IngestBuffer;
use SocialDept\AtpAppview\Ingest\IngestStats;

class IngestStatsTest extends TestCase
{
    protected Repository $cache;

    protected int $now = 1_800_000_000;

    protected function setUp(): void
    {
        // Pinned to the start of a minute, so buckets fall where the test expects.
        $this->now -= $this->now % 60;
        $this->cache = new Repository(new ArrayStore());
    }

    public function test_a_backfill_is_running_while_backfill_events_arrive_and_done_after_a_quiet_minute(): void
    {
        $stats = $this->stats();
        $this->assertFalse($stats->backfilling());

        $stats->record(new Batch([$this->record(live: false)]));
        $this->assertTrue($stats->backfilling());

        $this->now += 30;
        $stats->record(new Batch([$this->record(live: true)]));
        $this->assertTrue($stats->backfilling(), 'Live events do not end a backfill.');

        $this->now += 31;
        $this->assertFalse($stats->backfilling());
        $this->assertSame($this->now - 61, $stats->lastBackfillAt()->getTimestamp());
        $this->assertSame($this->now - 31, $stats->lastLiveAt()->getTimestamp());
    }

    public function test_rates_count_whole_minutes_by_kind(): void
    {
        $stats = $this->stats();

        $stats->record(new Batch([$this->record(false), $this->record(false), $this->record(true), new IdentityEvent(9, 'did:plc:a', null, true, null)]));
        $this->now += 60;
        $stats->record(new Batch([$this->record(false), $this->record(false)]));

        $this->now += 60;
        $this->assertEquals(['backfill' => 2.0, 'live' => 0.5, 'identity' => 0.5], $stats->perMinute(2));
    }

    public function test_counts_reach_the_cache_at_most_every_few_seconds(): void
    {
        $stats = $this->stats();
        $reader = $this->stats();

        $stats->record(new Batch([$this->record(false)]));
        $this->now += 2;
        $stats->record(new Batch([$this->record(false)]));
        $this->now += 60;
        $this->assertSame(1.0, $reader->perMinute(1)['backfill'], 'The second batch is still in memory.');

        $stats->flush();
        $this->assertSame(2.0, $reader->perMinute(1)['backfill']);
    }

    public function test_the_buffer_counts_events_even_when_handlers_see_none_of_them(): void
    {
        $stats = $this->stats();
        $buffer = new IngestBuffer([new class () implements BatchHandler {
            public function handle(Batch $batch): void
            {
            }
        }], fn () => null, stats: $stats);

        $buffer->push(1, $this->record(false));
        $buffer->push(2, null);
        $buffer->flush();

        $this->assertTrue($stats->backfilling());
    }

    protected function stats(): IngestStats
    {
        return new IngestStats($this->cache, fn () => $this->now);
    }

    protected function record(bool $live): RecordEvent
    {
        static $id = 0;

        return new RecordEvent(++$id, 'did:plc:a', 'app.example.post', 'r'.$id, RecordAction::Create, 'bafy', '3l2aaaaaaaaaa', ['n' => $id], $live);
    }
}
