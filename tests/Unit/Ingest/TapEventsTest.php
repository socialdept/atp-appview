<?php

namespace SocialDept\AtpAppview\Tests\Unit\Ingest;

use PHPUnit\Framework\TestCase;
use SocialDept\AtpAppview\Ingest\Events\IdentityEvent;
use SocialDept\AtpAppview\Ingest\Events\LabelerEvent;
use SocialDept\AtpAppview\Ingest\Events\LabelEvent;
use SocialDept\AtpAppview\Ingest\Events\RecordAction;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;
use SocialDept\AtpAppview\Ingest\Events\TapEvents;
use SocialDept\AtpAppview\Ingest\Exceptions\InvalidTapEvent;
use SocialDept\AtpAppview\Ingest\FailedDeliveries;

class TapEventsTest extends TestCase
{
    public function test_it_parses_a_record_event(): void
    {
        $event = TapEvents::parse([
            'id' => 12345,
            'type' => 'record',
            'record' => [
                'live' => false,
                'rev' => '3kb3fge5lm32x',
                'did' => 'did:plc:alice',
                'collection' => 'app.bsky.feed.post',
                'rkey' => '3abc',
                'action' => 'create',
                'cid' => 'bafyreiabc',
                'record' => ['$type' => 'app.bsky.feed.post', 'text' => 'hi'],
            ],
        ]);

        $this->assertInstanceOf(RecordEvent::class, $event);
        $this->assertSame(12345, $event->id);
        $this->assertSame(RecordAction::Create, $event->action);
        $this->assertSame('at://did:plc:alice/app.bsky.feed.post/3abc', $event->uri());
        $this->assertSame(['$type' => 'app.bsky.feed.post', 'text' => 'hi'], $event->record);
        $this->assertTrue($event->isBackfill());
    }

    public function test_a_delete_has_no_record_body(): void
    {
        $event = TapEvents::parse(['id' => 1, 'type' => 'record', 'record' => [
            'did' => 'did:plc:alice', 'collection' => 'app.bsky.feed.like', 'rkey' => '3x', 'action' => 'delete', 'rev' => '3k',
        ]]);

        $this->assertSame(RecordAction::Delete, $event->action);
        $this->assertNull($event->record);
        $this->assertNull($event->cid);
        $this->assertFalse($event->isBackfill());
    }

    public function test_it_parses_an_identity_event(): void
    {
        $event = TapEvents::parse(['id' => 7, 'type' => 'identity', 'identity' => [
            'did' => 'did:plc:alice', 'handle' => 'alice.example', 'is_active' => false, 'status' => 'deactivated',
        ]]);

        $this->assertInstanceOf(IdentityEvent::class, $event);
        $this->assertSame('alice.example', $event->handle);
        $this->assertFalse($event->active);
        $this->assertSame('deactivated', $event->status);
    }

    public function test_an_unknown_type_parses_to_null(): void
    {
        $this->assertNull(TapEvents::parse(['id' => 1, 'type' => 'something-new']));
    }

    public function test_the_id_is_readable_even_when_the_event_is_not(): void
    {
        $this->assertSame(9, TapEvents::id(['id' => 9, 'type' => 'record']));
        $this->assertNull(TapEvents::id(['type' => 'record']));
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function malformed(): array
    {
        return [
            'no id' => [['type' => 'record', 'record' => []]],
            'no payload' => [['id' => 1, 'type' => 'record']],
            'missing rkey' => [['id' => 1, 'type' => 'record', 'record' => ['did' => 'did:plc:a', 'collection' => 'c.d.e', 'action' => 'create']]],
            'unknown action' => [['id' => 1, 'type' => 'record', 'record' => ['did' => 'did:plc:a', 'collection' => 'c.d.e', 'rkey' => 'r', 'action' => 'upsert']]],
            'identity without did' => [['id' => 1, 'type' => 'identity', 'identity' => ['handle' => 'x']]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformed')]
    public function test_a_malformed_event_is_refused(array $message): void
    {
        $this->expectException(InvalidTapEvent::class);

        TapEvents::parse($message);
    }

    public function test_it_parses_tack_label_and_labeler_events(): void
    {
        $label = TapEvents::parse(['id' => 7, 'type' => 'label', 'verified' => true, 'label' => [
            'src' => 'did:plc:mod', 'uri' => 'at://did:plc:alice/app.bsky.feed.post/3k', 'cid' => 'bafy', 'val' => 'porn', 'neg' => true, 'cts' => '2026-10-19T12:00:00.000Z', 'exp' => null,
        ]]);

        $this->assertInstanceOf(LabelEvent::class, $label);
        $this->assertSame('did:plc:alice', $label->did, 'A label is about the repo of its record.');
        $this->assertTrue($label->neg);
        $this->assertTrue($label->verified);

        $labeler = TapEvents::parse(['id' => 8, 'type' => 'labeler', 'labeler' => ['did' => 'did:plc:mod', 'endpoint' => 'https://mod.example', 'declaration' => ['policies' => ['labelValues' => ['porn']]]]]);

        $this->assertInstanceOf(LabelerEvent::class, $labeler);
        $this->assertSame(['porn'], $labeler->declaration['policies']['labelValues']);
        $this->assertFalse($labeler->removed);
    }

    public function test_a_label_without_its_value_is_invalid(): void
    {
        $this->expectException(InvalidTapEvent::class);

        TapEvents::parse(['id' => 7, 'type' => 'label', 'label' => ['src' => 'did:plc:mod', 'uri' => 'did:plc:alice', 'cts' => '2026-10-19T12:00:00.000Z']]);
    }

    public function test_label_events_survive_being_parked(): void
    {
        $label = new LabelEvent(7, 'did:plc:mod', 'did:plc:alice', null, 'spam', true, '2026-10-19T12:00:00.000Z', null, true);
        $labeler = new LabelerEvent(8, 'did:plc:mod', 'https://mod.example', ['a' => 1], true);

        $this->assertEquals($label, FailedDeliveries::rebuild(FailedDeliveries::payload($label)));
        $this->assertEquals($labeler, FailedDeliveries::rebuild(FailedDeliveries::payload($labeler)));
    }
}
