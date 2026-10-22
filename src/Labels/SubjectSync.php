<?php

namespace SocialDept\AtpAppview\Labels;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Events\LabelerEvent;
use SocialDept\AtpAppview\Ingest\Labels\BackfillLabels;
use SocialDept\AtpAppview\Ingest\Labels\LabelerLookup;
use SocialDept\AtpAppview\Models\Label;
use SocialDept\AtpAppview\Models\Labeler;

/**
 * Fetches the labels each account already has, so only new ones need to
 * arrive live: through Tack, which is told about the accounts, or with label
 * streams, by queueing BackfillLabels.
 *
 * - Accounts not yet registered are, a batch at a time, with the URIs of
 *   their records the index holds, for labelers that only answer for exact
 *   URIs.
 * - Records indexed after their account was registered but made before it,
 *   such as a backfill arriving late, are sent too. Records made since need
 *   nothing: their labels arrive live.
 * - Labels past their expiry are dropped, and what they hid is shown.
 * - The labelers Tack follows, with their declarations, are read from its
 *   API, so a declaration missed in delivery is caught up. With streams,
 *   each labeler is looked up again every six hours instead.
 */
class SubjectSync
{
    protected const CURSOR = 'atp-appview:labels:sync:indexed';

    /** URIs per request to Tack. */
    protected const URI_CHUNK = 1000;

    /** Hours between lookups of each labeler with streams, as Tack does. */
    protected const DECLARATIONS_HOURS = 6;

    public function __construct(
        protected Tack $tack,
    ) {
    }

    /**
     * @return array{accounts: int, uris: int, expired: int, labelers: int}
     */
    public function run(int $limit = 1000): array
    {
        $labelers = 0;

        if (! Labels::streaming()) {
            $this->syncLabelers();
        } elseif (Cache::add('atp-appview:labels:declarations-checked', true, now()->addHours(self::DECLARATIONS_HOURS))) {
            $labelers = $this->refreshDeclarations();
        }

        $expired = self::expire();
        $accounts = $this->registerAccounts($limit);
        $uris = $this->sendLateRecords();

        return ['accounts' => $accounts, 'uris' => $uris, 'expired' => $expired, 'labelers' => $labelers];
    }

    /**
     * Drop labels past their expiry and refresh the accounts they were on.
     *
     * @return int Labels dropped
     */
    public static function expire(): int
    {
        $expired = Label::query()->whereNotNull('exp')->where('exp', '<=', Carbon::now('UTC'));
        $subjects = (clone $expired)->distinct()->pluck('subject')->all();
        $count = $expired->delete();

        app(Visibility::class)->refresh($subjects);

        return $count;
    }

    /**
     * Look up each followed labeler's service and declaration again, for
     * label streams, where no one else tells the app about a change.
     *
     * @return int Labelers checked
     */
    public function refreshDeclarations(): int
    {
        $lookup = app(LabelerLookup::class);
        $events = [];

        foreach (Labeler::query()->pluck('did') as $did) {
            try {
                $events[] = new LabelerEvent(0, $did, $lookup->service($did, fresh: true)['endpoint'], $lookup->declaration($did), false);
            } catch (\Throwable $e) {
                Log::warning('[atp-appview] Could not recheck a labeler', ['labeler' => $did, 'error' => $e->getMessage()]);
            }
        }

        if ($events !== []) {
            app(LabelIndexer::class)->handle(new Batch($events));
        }

        return count($events);
    }

    /**
     * Bring atp_labelers in line with the labelers Tack follows.
     */
    protected function syncLabelers(): void
    {
        $events = [];
        $following = [];

        foreach ($this->tack->labelers() as $i => $labeler) {
            if (! is_string($labeler['did'] ?? null)) {
                continue;
            }

            $following[] = $labeler['did'];
            $events[] = new LabelerEvent(0, $labeler['did'], $labeler['endpoint'] ?? null, is_array($labeler['declaration'] ?? null) ? $labeler['declaration'] : null, false);
        }

        foreach (Labeler::query()->whereNotIn('did', $following)->pluck('did') as $gone) {
            $events[] = new LabelerEvent(0, $gone, null, null, true);
        }

        if ($events !== []) {
            app(LabelIndexer::class)->handle(new Batch($events));
        }
    }

    protected function registerAccounts(int $limit): int
    {
        $connection = DB::connection(config('atp-appview.index.connection'));
        $registered = 0;

        $dids = $connection->table('atp_identities')->whereNull('labels_synced_at')->orderBy('did')->limit(max(1, $limit))->pluck('did')->all();

        foreach (array_chunk($dids, 100) as $chunk) {
            $now = Carbon::now('UTC')->format('Y-m-d H:i:s');
            if (Labels::streaming()) {
                BackfillLabels::accounts($chunk);
            } else {
                $uris = $connection->table('atp_records')->whereIn('did', $chunk)->whereNull('deleted_at')->pluck('uri')->all();
                $batches = array_chunk($uris, self::URI_CHUNK);

                $this->tack->addSubjects($chunk, $batches[0] ?? []);

                foreach (array_slice($batches, 1) as $batch) {
                    $this->tack->addUris($batch);
                }
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
                    Labels::streaming() ? BackfillLabels::records($uris) : $this->tack->addUris($uris);
                    $sent += count($uris);
                });
        }

        Cache::forever(self::CURSOR, $until);

        return $sent;
    }
}
