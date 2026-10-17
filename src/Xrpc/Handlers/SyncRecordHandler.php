<?php

namespace SocialDept\AtpAppview\Xrpc\Handlers;

use SocialDept\AtpAppview\Index\IndexesRecordsNow;
use SocialDept\AtpAppview\Ingest\Events\RecordAction;
use SocialDept\AtpAppview\Xrpc\XrpcException;
use SocialDept\AtpAppview\Xrpc\XrpcRequest;
use SocialDept\AtpClient\Exceptions\AtpResponseException;
use SocialDept\AtpClient\Facades\Atp;
use SocialDept\AtpSupport\Facades\Resolver;
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

        if (! in_array($collection, (array) config('atp-appview.ingest.collections', []), true)) {
            throw XrpcException::invalidRequest("{$collection} is not indexed here.");
        }

        if ($this->index->blocked($did, $collection)) {
            return ['uri' => $uri, 'indexed' => false];
        }

        try {
            $pds = Atp::public(Resolver::resolvePds($did) ?? throw new XrpcException('RepoNotFound', "{$did} has no PDS.", 404));

            // The revision first: the record read after it is at least that
            // new, so a later delivery from Tap can only replace it.
            $rev = $pds->atproto->sync->getLatestCommit($did)->rev;
            $record = $this->fetch($pds, $did, $collection, $rkey);
        } catch (XrpcException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new XrpcException('UpstreamFailure', "Could not read the record from its PDS: {$e->getMessage()}", 502);
        }

        $record === null
            ? $this->index->index($did, $collection, $rkey, RecordAction::Delete, null, $rev, null)
            : $this->index->index($did, $collection, $rkey, RecordAction::Update, $record['cid'], $rev, $record['value']);

        return ['uri' => $uri, 'indexed' => true];
    }

    /**
     * @return array{cid: string, value: array<string, mixed>}|null Null when the record is gone
     */
    protected function fetch(mixed $pds, string $did, string $collection, string $rkey): ?array
    {
        try {
            $response = $pds->atproto->repo->getRecord($did, $collection, $rkey);
        } catch (AtpResponseException $e) {
            if ($e->errorCode === 'RecordNotFound') {
                return null;
            }

            throw $e;
        }

        if (! is_array($response->value) || ! is_string($response->cid)) {
            throw new XrpcException('UpstreamFailure', 'The PDS answered without the record.', 502);
        }

        return ['cid' => $response->cid, 'value' => $response->value];
    }
}
