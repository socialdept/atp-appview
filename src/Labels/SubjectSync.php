<?php

namespace SocialDept\AtpAppview\Labels;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Keeps Tack's subjects in step with the index, so Tack fetches the labels
 * each account already has and delivers new ones.
 *
 * - Accounts not yet registered are, a batch at a time, with the URIs of
 *   their records the index holds, for labelers that only answer for exact
 *   URIs.
 * - Records indexed after their account was registered but made before it,
 *   such as a backfill arriving late, are sent too. Records made since need
 *   nothing: their labels arrive live.
 */
class SubjectSync
{
    protected const CURSOR = 'atp-appview:labels:sync:indexed';

    /** URIs per request to Tack. */
    protected const URI_CHUNK = 1000;

    public function __construct(
        protected Tack $tack,
    ) {
    }

    /**
     * @return array{accounts: int, uris: int}
     */
    public function run(int $limit = 1000): array
    {
        $accounts = $this->registerAccounts($limit);
        $uris = $this->sendLateRecords();

        return ['accounts' => $accounts, 'uris' => $uris];
    }

    protected function registerAccounts(int $limit): int
    {
        $connection = DB::connection(config('atp-appview.index.connection'));
        $registered = 0;

        $dids = $connection->table('atp_identities')->whereNull('labels_synced_at')->orderBy('did')->limit(max(1, $limit))->pluck('did')->all();

        foreach (array_chunk($dids, 100) as $chunk) {
            $now = Carbon::now('UTC')->format('Y-m-d H:i:s');
            $uris = $connection->table('atp_records')->whereIn('did', $chunk)->whereNull('deleted_at')->pluck('uri')->all();
            $batches = array_chunk($uris, self::URI_CHUNK);

            $this->tack->addSubjects($chunk, $batches[0] ?? []);

            foreach (array_slice($batches, 1) as $batch) {
                $this->tack->addUris($batch);
            }

            $connection->table('atp_identities')->whereIn('did', $chunk)->update(['labels_synced_at' => $now]);
            $registered += count($chunk);
        }

        return $registered;
    }

    protected function sendLateRecords(): int
    {
        $connection = DB::connection(config('atp-appview.index.connection'));
        $until = Carbon::now('UTC')->format('Y-m-d H:i:s');
        $from = Cache::get(self::CURSOR);
        $sent = 0;

        // On the first run every record was sent with its account.
        if ($from !== null) {
            $connection->table('atp_records')
                ->join('atp_identities', 'atp_identities.did', '=', 'atp_records.did')
                ->whereNotNull('atp_identities.labels_synced_at')
                ->where('atp_records.indexed_at', '>', $from)
                ->where('atp_records.indexed_at', '<=', $until)
                ->whereColumn('atp_records.indexed_at', '>', 'atp_identities.labels_synced_at')
                ->whereColumn('atp_records.sort_at', '<', 'atp_identities.labels_synced_at')
                ->whereNull('atp_records.deleted_at')
                ->orderBy('atp_records.uri')
                ->select('atp_records.uri')
                ->chunk(self::URI_CHUNK, function ($rows) use (&$sent) {
                    $uris = $rows->pluck('uri')->all();
                    $this->tack->addUris($uris);
                    $sent += count($uris);
                });
        }

        Cache::forever(self::CURSOR, $until);

        return $sent;
    }
}
