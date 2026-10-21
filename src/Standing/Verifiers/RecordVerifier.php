<?php

namespace SocialDept\AtpAppview\Standing\Verifiers;

use LogicException;
use SocialDept\AtpAppview\Ingest\Collections;
use SocialDept\AtpAppview\Models\Identity;
use SocialDept\AtpAppview\Models\Record;
use SocialDept\AtpAppview\Profiles\Profiles;
use SocialDept\AtpAppview\Standing\Contracts\Verifier;
use SocialDept\AtpAppview\Standing\Verification;
use SocialDept\AtpCbor\Proof\ProofException;
use SocialDept\AtpCbor\Proof\RecordProof;
use SocialDept\AtpClient\Facades\Atp;
use SocialDept\AtpSupport\Crypto\SignatureVerifier;
use SocialDept\AtpSupport\Facades\Microcosm;
use SocialDept\AtpSupport\Facades\Resolver;
use Throwable;

/**
 * Verification by records, as Bluesky's app.bsky.graph.verification works,
 * for any collection: a record in a trusted issuer's own repo naming the
 * account as its subject, whose fields still match the account.
 *
 * Records are found in the index when the app ingests the collection, else
 * through Constellation, filtered to the trusted issuers. Each is proven
 * before it counts: fetched from the issuer's repo with
 * com.atproto.sync.getRecord, its place under the commit checked
 * (atp-cbor's RecordProof), and the commit's signature checked against the
 * issuer's key from its DID document. A record that cannot be proven does
 * not count either way, and the account is checked again soon.
 *
 * A proven record whose fields no longer match, such as a changed handle,
 * makes the verification invalid rather than none.
 */
class RecordVerifier implements Verifier
{
    /** @var array<int, string> */
    protected array $issuers;

    protected string $collection;

    protected string $subjectPath;

    /** @var array<string, string> Record field => account field (handle or displayName) */
    protected array $match;

    protected bool $prove;

    /**
     * @param  array<string, mixed>  $options  collection, subject, issuers, match, prove
     */
    public function __construct(array $options = [])
    {
        $this->collection = (string) ($options['collection'] ?? 'app.bsky.graph.verification');
        $this->subjectPath = (string) ($options['subject'] ?? 'subject');
        $this->issuers = array_values(array_filter((array) ($options['issuers'] ?? []), 'is_string'));
        $this->match = (array) ($options['match'] ?? ['handle' => 'handle', 'displayName' => 'displayName']);
        $this->prove = (bool) ($options['prove'] ?? true);

        if ($this->prove && ! class_exists(RecordProof::class)) {
            throw new LogicException('Proving verification records needs socialdept/atp-cbor: composer require socialdept/atp-cbor, or set prove to false.');
        }
    }

    public function trusts(string $did): bool
    {
        return in_array($did, $this->issuers, true);
    }

    public function verify(array $dids): array
    {
        $results = [];
        $method = 'record:'.$this->collection;

        if ($this->issuers === []) {
            return array_fill_keys($dids, Verification::none($method));
        }

        $accounts = $this->accounts($dids);

        foreach ($dids as $did) {
            $verifications = [];
            $complete = true;

            foreach ($this->candidates($did) as [$issuer, $rkey, $indexed]) {
                $record = $this->prove ? $this->proven($issuer, $rkey) : $indexed;

                if ($record === null) {
                    // Not proven, so neither valid nor invalid: tried again soon.
                    $complete = false;

                    continue;
                }

                $verifications[] = [
                    'issuer' => $issuer,
                    'uri' => "at://{$issuer}/{$this->collection}/{$rkey}",
                    'createdAt' => is_string($record['createdAt'] ?? null) ? $record['createdAt'] : null,
                    ...$this->check($record, $did, $accounts[$did] ?? []),
                ];
            }

            $results[$did] = Verification::from($verifications, $method, $complete);
        }

        return $results;
    }

    /**
     * The trusted issuers' records naming an account: [issuer, rkey, the
     * record when the index holds it].
     *
     * @return array<int, array{0: string, 1: string, 2: array<string, mixed>|null}>
     */
    protected function candidates(string $did): array
    {
        if (Collections::ingests($this->collection)) {
            return Record::query()
                ->where('collection', $this->collection)
                ->whereIn('did', $this->issuers)
                ->where('record->'.str_replace('.', '->', $this->subjectPath), $did)
                ->get(['did', 'rkey', 'record'])
                ->map(fn (Record $record) => [$record->did, $record->rkey, $record->record])
                ->all();
        }

        try {
            $found = Microcosm::constellation()->getBacklinks($did, "{$this->collection}:{$this->subjectPath}", $this->issuers, 100);
        } catch (Throwable) {
            return [];
        }

        return array_map(fn ($reference) => [$reference->did, $reference->rkey, null], array_values(array_filter(
            $found->records,
            fn ($reference) => in_array($reference->did, $this->issuers, true) && $reference->collection === $this->collection,
        )));
    }

    /**
     * The record, once proven to be in the issuer's repo under a commit the
     * issuer signed, or null.
     *
     * @return array<string, mixed>|null
     */
    protected function proven(string $issuer, string $rkey): ?array
    {
        try {
            $document = Resolver::resolveDid($issuer);
            $pds = $document->getPdsEndpoint();
            $key = $document->getSigningKey();

            if ($pds === null || $key === null) {
                return null;
            }

            $car = Atp::public($pds)->atproto->sync->getRecord($issuer, $this->collection, $rkey)->body();
            $proven = RecordProof::verify($car, $this->collection, $rkey, $issuer);

            return app(SignatureVerifier::class)->verify($key, $proven->unsignedCommit, $proven->signature) ? $proven->record : null;
        } catch (ProofException|Throwable) {
            return null;
        }
    }

    /**
     * Whether a proven record still holds for the account: it names the
     * account, and each matched field equals the account's.
     *
     * @param  array<string, mixed>  $record
     * @param  array<string, string|null>  $account
     * @return array{valid: bool, reason: string|null}
     */
    protected function check(array $record, string $did, array $account): array
    {
        if (data_get($record, $this->subjectPath) !== $did) {
            return ['valid' => false, 'reason' => 'subject'];
        }

        foreach ($this->match as $field => $accountField) {
            if (! is_string($record[$field] ?? null) || ($account[$accountField] ?? null) !== $record[$field]) {
                return ['valid' => false, 'reason' => $field];
            }
        }

        return ['valid' => true, 'reason' => null];
    }

    /**
     * Each account's handle and display name now. The handle is the index's,
     * which identity events keep, or else the DID document's when it
     * resolves back to the same DID.
     *
     * @param  array<int, string>  $dids
     * @return array<string, array{handle: string|null, displayName: string|null}>
     */
    protected function accounts(array $dids): array
    {
        $handles = Identity::query()->whereIn('did', $dids)->pluck('handle', 'did')->all();
        $profiles = in_array('displayName', $this->match, true) ? app(Profiles::class)->for($dids) : [];
        $accounts = [];

        foreach ($dids as $did) {
            $handle = $handles[$did] ?? null;

            if ($handle === null && in_array('handle', $this->match, true)) {
                try {
                    $claimed = Resolver::resolveDid($did)->getHandle();
                    $handle = $claimed !== null && Resolver::handleToDid($claimed) === $did ? $claimed : null;
                } catch (Throwable) {
                    $handle = null;
                }
            }

            $accounts[$did] = ['handle' => $handle, 'displayName' => $profiles[$did]['displayName'] ?? null];
        }

        return $accounts;
    }
}
