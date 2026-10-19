<?php

namespace SocialDept\AtpAppview\Ingest\Events;

use SocialDept\AtpAppview\Ingest\Exceptions\InvalidTapEvent;

/**
 * Parses messages from Tap's websocket channel.
 *
 *     {"id": 12345, "type": "record", "record": {"live": true, "rev": "...",
 *      "did": "...", "collection": "...", "rkey": "...", "action": "create",
 *      "cid": "...", "record": {...}}}
 *
 *     {"id": 12346, "type": "identity", "identity": {"did": "...",
 *      "handle": "...", "is_active": true, "status": "active"}}
 *
 * Tab, the Tap fork, sends the same shapes. Tack sends labels the same way:
 *
 *     {"id": 7, "type": "label", "label": {"src": "...", "uri": "...",
 *      "val": "...", "neg": false, "cts": "..."}, "verified": true}
 *
 *     {"id": 8, "type": "labeler", "labeler": {"did": "...",
 *      "endpoint": "...", "declaration": {...}}}
 */
final class TapEvents
{
    /**
     * The delivery id Tap expects back in the ack, read before anything else
     * so even a message that fails to parse can be acknowledged.
     *
     * @param  array<string, mixed>  $message
     */
    public static function id(array $message): ?int
    {
        return is_int($message['id'] ?? null) ? $message['id'] : null;
    }

    /**
     * @param  array<string, mixed>  $message
     * @return RecordEvent|IdentityEvent|LabelEvent|LabelerEvent|null Null for a message type this does not handle
     *
     * @throws InvalidTapEvent
     */
    public static function parse(array $message): RecordEvent|IdentityEvent|LabelEvent|LabelerEvent|null
    {
        $id = self::id($message) ?? throw new InvalidTapEvent('missing "id"');

        return match ($message['type'] ?? null) {
            'record' => self::record($id, $message['record'] ?? null),
            'identity' => self::identity($id, $message['identity'] ?? null),
            'label' => self::label($id, $message['label'] ?? null, (bool) ($message['verified'] ?? false)),
            'labeler' => self::labeler($id, $message['labeler'] ?? null),
            default => null,
        };
    }

    protected static function record(int $id, mixed $data): RecordEvent
    {
        if (! is_array($data)) {
            throw new InvalidTapEvent('record event has no record payload');
        }

        foreach (['did', 'collection', 'rkey', 'action'] as $field) {
            if (! is_string($data[$field] ?? null) || $data[$field] === '') {
                throw new InvalidTapEvent("record event is missing \"{$field}\"");
            }
        }

        $action = RecordAction::tryFrom($data['action'])
            ?? throw new InvalidTapEvent("unknown record action \"{$data['action']}\"");

        return new RecordEvent(
            id: $id,
            did: $data['did'],
            collection: $data['collection'],
            rkey: $data['rkey'],
            action: $action,
            cid: is_string($data['cid'] ?? null) ? $data['cid'] : null,
            rev: (string) ($data['rev'] ?? ''),
            record: is_array($data['record'] ?? null) ? $data['record'] : null,
            live: (bool) ($data['live'] ?? true),
        );
    }

    protected static function identity(int $id, mixed $data): IdentityEvent
    {
        if (! is_array($data) || ! is_string($data['did'] ?? null) || $data['did'] === '') {
            throw new InvalidTapEvent('identity event is missing "did"');
        }

        return new IdentityEvent(
            id: $id,
            did: $data['did'],
            handle: is_string($data['handle'] ?? null) ? $data['handle'] : null,
            active: (bool) ($data['is_active'] ?? true),
            status: is_string($data['status'] ?? null) ? $data['status'] : null,
        );
    }

    protected static function label(int $id, mixed $data, bool $verified): LabelEvent
    {
        if (! is_array($data)) {
            throw new InvalidTapEvent('label event has no label payload');
        }

        foreach (['src', 'uri', 'val', 'cts'] as $field) {
            if (! is_string($data[$field] ?? null) || $data[$field] === '') {
                throw new InvalidTapEvent("label event is missing \"{$field}\"");
            }
        }

        return new LabelEvent(
            id: $id,
            src: $data['src'],
            uri: $data['uri'],
            cid: is_string($data['cid'] ?? null) ? $data['cid'] : null,
            val: $data['val'],
            neg: (bool) ($data['neg'] ?? false),
            cts: $data['cts'],
            exp: is_string($data['exp'] ?? null) ? $data['exp'] : null,
            verified: $verified,
        );
    }

    protected static function labeler(int $id, mixed $data): LabelerEvent
    {
        if (! is_array($data) || ! is_string($data['did'] ?? null) || $data['did'] === '') {
            throw new InvalidTapEvent('labeler event is missing "did"');
        }

        return new LabelerEvent(
            id: $id,
            did: $data['did'],
            endpoint: is_string($data['endpoint'] ?? null) ? $data['endpoint'] : null,
            declaration: is_array($data['declaration'] ?? null) ? $data['declaration'] : null,
            removed: (bool) ($data['removed'] ?? false),
        );
    }
}
