<?php

namespace SocialDept\AtpAppview\Ingest\Labels;

use Illuminate\Support\Facades\Log;
use SocialDept\AtpCbor\Core\CBOR;
use SocialDept\AtpSupport\Crypto\SignatureVerifier;
use Throwable;

/**
 * Checks labels against their labeler's #atproto_label key, over the label
 * as the labeler signed it: every field but sig, encoded as DAG-CBOR.
 *
 * Keys are kept for the process. When a label fails, the key is looked up
 * again in case it was rotated, at most every ten minutes per labeler.
 */
class LabelVerifier
{
    /** Seconds before a labeler's key is looked up again after a failed label. */
    protected const REKEY_SECONDS = 600;

    /** @var array<string, string> */
    protected array $keys = [];

    /** @var array<string, int> */
    protected array $rekeyedAt = [];

    protected SignatureVerifier $verifier;

    public function __construct(
        protected LabelerLookup $lookup,
    ) {
        $this->verifier = new SignatureVerifier();
    }

    /**
     * The labeler's key, looked up once.
     *
     * @throws \RuntimeException when it cannot be looked up
     */
    public function key(string $labeler): string
    {
        return $this->keys[$labeler] ??= $this->lookup->service($labeler)['key'];
    }

    /**
     * @param  array<string, mixed>  $label  With sig as raw bytes
     */
    public function verify(string $labeler, array $label): bool
    {
        $sig = $label['sig'] ?? null;
        unset($label['sig']);

        if (! is_string($sig)) {
            return false;
        }

        $bytes = CBOR::encode($label);

        if (isset($this->keys[$labeler]) && $this->verifier->verify($this->keys[$labeler], $bytes, $sig)) {
            return true;
        }

        if (time() - ($this->rekeyedAt[$labeler] ?? 0) < self::REKEY_SECONDS) {
            return false;
        }

        $this->rekeyedAt[$labeler] = time();

        try {
            $this->keys[$labeler] = $this->lookup->service($labeler, fresh: true)['key'];
        } catch (Throwable $e) {
            Log::warning('[atp-appview] Could not look up a labeler\'s key', ['labeler' => $labeler, 'error' => $e->getMessage()]);

            return false;
        }

        return $this->verifier->verify($this->keys[$labeler], $bytes, $sig);
    }
}
