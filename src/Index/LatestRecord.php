<?php

namespace SocialDept\AtpAppview\Index;

use RuntimeException;
use SocialDept\AtpAppview\Ingest\Events\RecordAction;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;
use SocialDept\AtpClient\Exceptions\AtpResponseException;
use SocialDept\AtpClient\Facades\Atp;
use SocialDept\AtpSupport\Facades\Resolver;

/**
 * A record as its author's PDS has it now, as an event ingest can take: an
 * update carrying the record, or a delete when it is gone. Used to index a
 * record ahead of Tap, and to reindex one that failed.
 */
class LatestRecord
{
    /**
     * @param  int  $id  The event id to carry, 0 when Tap did not deliver it
     *
     * @throws RepoNotFound when the DID has no PDS
     * @throws \Throwable when the PDS cannot be read
     */
    public function fetch(string $did, string $collection, string $rkey, int $id = 0): RecordEvent
    {
        $pds = Atp::public(Resolver::resolvePds($did) ?? throw new RepoNotFound("{$did} has no PDS."));

        // The revision first: the record read after it is at least that new,
        // so a later delivery from Tap can only replace it.
        $rev = $pds->atproto->sync->getLatestCommit($did)->rev;

        try {
            $response = $pds->atproto->repo->getRecord($did, $collection, $rkey);
        } catch (AtpResponseException $e) {
            if ($e->errorCode === 'RecordNotFound') {
                return new RecordEvent($id, $did, $collection, $rkey, RecordAction::Delete, null, $rev, null, true);
            }

            throw $e;
        }

        if (! is_array($response->value) || ! is_string($response->cid)) {
            throw new RuntimeException('The PDS answered without the record.');
        }

        return new RecordEvent($id, $did, $collection, $rkey, RecordAction::Update, $response->cid, $rev, $response->value, true);
    }
}
