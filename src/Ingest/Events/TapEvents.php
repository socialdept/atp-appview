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
 * Tab, the Tap fork, sends the same shapes.
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
     * @return RecordEvent|IdentityEvent|null Null for a message type this does not handle
     *
     * @throws InvalidTapEvent
     */
    public static function parse(array $message): RecordEvent|IdentityEvent|null
    {
        $id = self::id($message) ?? throw new InvalidTapEvent('missing "id"');

        return match ($message['type'] ?? null) {
            'record' => self::record($id, $message['record'] ?? null),
            'identity' => self::identity($id, $message['identity'] ?? null),
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
}
