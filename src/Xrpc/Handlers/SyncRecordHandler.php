<?php

namespace SocialDept\AtpAppview\Xrpc\Handlers;

use SocialDept\AtpAppview\Index\IndexesRecordsNow;
use SocialDept\AtpAppview\Index\LatestRecord;
use SocialDept\AtpAppview\Index\RepoNotFound;
use SocialDept\AtpAppview\Ingest\Collections;
use SocialDept\AtpAppview\Xrpc\XrpcException;
use SocialDept\AtpAppview\Xrpc\XrpcRequest;
use Throwable;

/**
 * Read-your-writes for frontend mode. The browser writes a record to its own
 * PDS, then calls this through the PDS proxy with the record's uri, and the
 * record is fetched from that PDS and indexed straight away instead of when
 * Tap delivers it. A record that is gone is tombstoned, so a delete shows up
 * as quickly.
 *
 * Only the caller's own records, and only collections the AppView indexes.
 * The record comes from the caller's PDS, never from the request.
 *
 * Input {uri}, answers {uri, indexed}.
 */
class SyncRecordHandler
{
    public function __construct(
        protected IndexesRecordsNow $index,
        protected LatestRecord $latest,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(XrpcRequest $request): array
    {
        $viewer = $request->viewer();
        $uri = $request->input()['uri'] ?? null;

        if (! is_string($uri) || ! preg_match('#^at://(did:[a-z]+:[a-zA-Z0-9._:%-]+)/([a-zA-Z0-9.-]+)/([a-zA-Z0-9._~:-]{1,512})$#', $uri, $parts)) {
            throw XrpcException::invalidRequest('The input needs the uri of one record, at://did/collection/rkey.');
        }

        [, $did, $collection, $rkey] = $parts;

        if ($viewer === null || $viewer->isService() || $viewer->did !== $did) {
            throw new XrpcException('NotYourRecord', 'Only your own records can be synced.', 403);
        }

        if (! Collections::ingests($collection)) {
            throw XrpcException::invalidRequest("{$collection} is not indexed here.");
        }

        if ($this->index->blocked($did, $collection)) {
            return ['uri' => $uri, 'indexed' => false];
        }

        try {
            $event = $this->latest->fetch($did, $collection, $rkey);
        } catch (RepoNotFound $e) {
            throw new XrpcException('RepoNotFound', $e->getMessage(), 404);
        } catch (Throwable $e) {
            throw new XrpcException('UpstreamFailure', "Could not read the record from its PDS: {$e->getMessage()}", 502);
        }

        $indexed = $this->index->index($did, $collection, $rkey, $event->action, $event->cid, $event->rev, $event->record);

        return ['uri' => $uri, 'indexed' => $indexed];
    }
}
