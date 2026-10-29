<?php

namespace SocialDept\AtpAppview\Tests\Unit\Ingest;

use RuntimeException;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Contracts\BatchHandler;
use SocialDept\AtpAppview\Ingest\Events\IdentityEvent;
use SocialDept\AtpAppview\Ingest\Events\RecordAction;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;
use SocialDept\AtpAppview\Ingest\Exceptions\BatchFailed;
use SocialDept\AtpAppview\Ingest\IngestBuffer;
use SocialDept\AtpAppview\Tests\TestCase;

class IngestBufferTest extends TestCase
{
    /** @var array<int, int> */
    protected array $acked = [];

    public function test_events_are_acknowledged_only_after_every_handler_commits(): void
    {
        $order = [];
        $first = $this->handler(function () use (&$order) {
            $order[] = 'first:'.count($this->acked);
        });
        $second = $this->handler(function () use (&$order) {
            $order[] = 'second:'.count($this->acked);
        });

        $buffer = $this->buffer([$first, $second]);
        $buffer->push(1, $this->record(1));
        $buffer->push(2, $this->record(2));
        $buffer->flush();

        $this->assertSame(['first:0', 'second:0'], $order);
        $this->assertSame([1, 2], $this->acked);
        $this->assertSame(0, $buffer->pending());
    }

    public function test_push_reports_when_the_batch_is_full(): void
    {
        $buffer = $this->buffer([], batchSize: 2);

        $this->assertFalse($buffer->push(1, $this->record(1)));
        $this->assertTrue($buffer->push(2, $this->record(2)));
    }

    public function test_handlers_receive_events_in_delivery_order(): void
    {
        $seen = null;
        $buffer = $this->buffer([$this->handler(function (Batch $batch) use (&$seen) {
            $seen = $batch;
        })]);

        $buffer->push(1, $this->record(1));
        $buffer->push(2, new IdentityEvent(2, 'did:plc:alice', 'alice.example', true, 'active'));
        $buffer->push(3, $this->record(3));
        $buffer->flush();

        $this->assertSame([1, 2, 3], array_map(fn ($event) => $event->id, $seen->events));
        $this->assertCount(2, $seen->records());
        $this->assertCount(1, $seen->identities());
    }

    public function test_a_failing_batch_is_retried_before_it_is_acknowledged(): void
    {
        $calls = 0;
        $buffer = $this->buffer([$this->handler(function () use (&$calls) {
            if (++$calls < 3) {
                throw new RuntimeException('database busy');
            }
        })], maxAttempts: 3);

        $buffer->push(1, $this->record(1));
        $buffer->flush();

        $this->assertSame(3, $calls);
        $this->assertSame([1], $this->acked);
    }

    public function test_a_batch_that_keeps_failing_is_never_acknowledged(): void
    {
        $buffer = $this->buffer([$this->handler(fn () => throw new RuntimeException('database down'))], maxAttempts: 2);
        $buffer->push(1, $this->record(1));

        try {
            $buffer->flush();
            $this->fail('The batch should have failed.');
        } catch (BatchFailed $e) {
            $this->assertSame(2, $e->attempts);
            $this->assertSame([], $this->acked);
        }
    }

    public function test_deliveries_without_an_event_are_acknowledged_without_reaching_handlers(): void
    {
        $calls = 0;
        $buffer = $this->buffer([$this->handler(function () use (&$calls) {
            $calls++;
        })]);

        $buffer->push(1, null);
        $buffer->push(2, null);
        $buffer->flush();

        $this->assertSame(0, $calls);
        $this->assertSame([1, 2], $this->acked);
    }

    public function test_clearing_drops_events_without_acknowledging_them(): void
    {
        $buffer = $this->buffer([]);
        $buffer->push(1, $this->record(1));
        $buffer->clear();
        $buffer->flush();

        $this->assertSame([], $this->acked);
    }

    /**
     * @param  array<int, BatchHandler>  $handlers
     */
    protected function buffer(array $handlers, int $batchSize = 200, int $maxAttempts = 3): IngestBuffer
    {
        return new IngestBuffer($handlers, function (int $id) {
            $this->acked[] = $id;
        }, $batchSize, $maxAttempts, fn () => null);
    }

    protected function handler(\Closure $handle): BatchHandler
    {
        return new class ($handle) implements BatchHandler {
            public function __construct(private \Closure $handle)
            {
            }

            public function handle(Batch $batch): void
            {
                ($this->handle)($batch);
            }
        };
    }

    protected function record(int $id): RecordEvent
    {
        return new RecordEvent($id, 'did:plc:alice', 'app.bsky.feed.post', "r{$id}", RecordAction::Create, 'bafy', '3k', ['text' => 'hi'], true);
    }
}
