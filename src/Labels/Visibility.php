<?php

namespace SocialDept\AtpAppview\Labels;

use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use SocialDept\AtpAppview\Index\RecordValues;
use SocialDept\AtpAppview\Models\Label;

/**
 * Keeps what labels hide out of lists and views. A record is moderated when
 * a label on it hides content, or a label on its account hides the account,
 * and moderated records carry moderated_at, which queries leave out through
 * the same indexes as records hidden for their account's status.
 *
 * Refreshing an account works out what should be moderated from its labels
 * as they are now, and moves only what differs, so it can run after any
 * change: a label arriving or withdrawn, a policy edited, a record indexed.
 */
class Visibility
{
    public function __construct(
        protected Policies $policies,
    ) {
    }

    /**
     * @param  array<int, string>  $dids
     */
    public function refresh(array $dids): void
    {
        if ($dids === []) {
            return;
        }

        $connection = DB::connection(config('atp-appview.index.connection'));

        foreach (array_chunk(array_values(array_unique($dids)), 200) as $chunk) {
            $labels = Label::query()->live()->whereIn('subject', $chunk)->get(['src', 'uri', 'val', 'subject'])->groupBy('subject');

            foreach ($chunk as $did) {
                $this->apply($connection, $did, $labels[$did] ?? collect());
            }
        }
    }

    /**
     * After records are indexed: moderate new records of accounts that have
     * labels, such as a new post of an account whose labels hide it, and
     * refresh accounts whose self-labels changed.
     *
     * @param  array<int, string>  $dids  Accounts the batch wrote records for
     * @param  array<int, string>  $changed  Accounts whose self-labels changed
     */
    public function arrivals(array $dids, array $changed = []): void
    {
        if ($dids === [] && $changed === []) {
            return;
        }

        $labeled = $dids === [] ? [] : Label::query()->whereIn('subject', array_values(array_unique($dids)))->distinct()->pluck('subject')->all();

        $this->refresh(array_values(array_unique([...$labeled, ...$changed])));
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Label>  $labels
     */
    protected function apply(Connection $connection, string $did, $labels): void
    {
        $accountHidden = false;
        $hiddenUris = [];

        foreach ($labels as $label) {
            $target = Target::of($label->uri);

            if (! $this->policies->effect($label->src, $label->val, $target)->hides()) {
                continue;
            }

            if ($target === Target::ACCOUNT) {
                $accountHidden = true;
            } else {
                $hiddenUris[] = $label->uri;
            }
        }

        $live = fn () => $connection->table('atp_records')->where('did', $did)->whereNull('deleted_at');

        $want = $accountHidden
            ? $live()->pluck('id')->all()
            : ($hiddenUris === [] ? [] : $live()->whereIn('uri', $hiddenUris)->pluck('id')->all());
        $have = $live()->whereNotNull('moderated_at')->pluck('id')->all();

        $hide = array_values(array_diff($want, $have));
        $show = array_values(array_diff($have, $want));

        if ($hide !== []) {
            $connection->table('atp_records')->whereIn('id', $hide)->update(['moderated_at' => Carbon::now('UTC')->format('Y-m-d H:i:s')]);
            app(RecordValues::class)->hideRecords($connection, array_map('intval', $hide));
        }

        if ($show !== []) {
            $connection->table('atp_records')->whereIn('id', $show)->update(['moderated_at' => null]);
            app(RecordValues::class)->hideRecords($connection, array_map('intval', $show), false);
        }
    }
}
