<?php

namespace SocialDept\AtpAppview\Ingest\Jetstream;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use SocialDept\AtpSupport\Facades\Resolver;
use Throwable;

/**
 * Looks up the handle and PDS of repos Jetstream introduced, which Tap would
 * have sent as identity events. A handle counts only when it resolves back
 * to the DID. A repo whose DID document cannot be read is kept with no
 * handle, shown as @handle.invalid, until an identity event fixes it.
 */
class ResolveIdentity implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /**
     * @param  array<int, string>  $dids
     */
    public function __construct(
        public readonly array $dids,
    ) {
    }

    public function handle(): void
    {
        $connection = DB::connection(config('atp-appview.index.connection'));
        $now = Carbon::now('UTC')->format('Y-m-d H:i:s');

        foreach ($this->dids as $did) {
            $handle = null;
            $pds = null;

            try {
                $document = Resolver::resolveDid($did);
                $pds = $document->getPdsEndpoint() === null ? null : rtrim($document->getPdsEndpoint(), '/');
                $claimed = $document->getHandle();
                $handle = $claimed !== null && Resolver::handleToDid($claimed) === $did ? $claimed : null;
            } catch (Throwable) {
                // Kept without a handle, so its records still show.
            }

            $exists = $connection->table('atp_identities')->where('did', $did)->exists();

            $exists
                ? $connection->table('atp_identities')->where('did', $did)->whereNull('handle')->update(array_filter(['handle' => $handle, 'pds' => $pds, 'pds_resolved_at' => $pds === null ? null : $now, 'updated_at' => $now]))
                : $connection->table('atp_identities')->insert(['did' => $did, 'handle' => $handle, 'active' => true, 'pds' => $pds, 'pds_resolved_at' => $pds === null ? null : $now, 'updated_at' => $now, 'created_at' => $now]);
        }
    }
}
