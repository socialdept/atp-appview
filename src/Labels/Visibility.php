<?php

namespace SocialDept\AtpAppview\Labels;

use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use SocialDept\AtpAppview\Index\RecordValues;
use SocialDept\AtpAppview\Models\AccountAction;
use SocialDept\AtpAppview\Models\Label;
use SocialDept\AtpAppview\Moderation\AccountActions;

/**
 * Keeps what moderation hides out of lists and views, and what needs review
 * out of discovery.
 *
 * - A record is moderated (moderated_at) when a label on it hides content,
 *   or its account is hidden by a label or banned.
 * - A record is limited (limited_at) when a label on it needs review, or its
 *   account needs review by a label or an operator.
 *
 * Account labels that ban or need review become account actions, lifted
 * once their label is gone. Refreshing an account works out both sets from
 * its labels and actions as they are now, and moves only what differs, so it
 * can run after any change.
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
     * labels or actions, and refresh accounts whose self-labels changed.
     *
     * @param  array<int, string>  $dids  Accounts the batch wrote records for
     * @param  array<int, string>  $changed  Accounts whose self-labels changed
     */
    public function arrivals(array $dids, array $changed = []): void
    {
        if ($dids === [] && $changed === []) {
            return;
        }

        $dids = array_values(array_unique($dids));
        $affected = $dids === [] ? [] : [
            ...Label::query()->whereIn('subject', $dids)->distinct()->pluck('subject')->all(),
            ...AccountAction::query()->active()->whereIn('did', $dids)->distinct()->pluck('did')->all(),
        ];

        $this->refresh(array_values(array_unique([...$affected, ...$changed])));
    }

    /**
     * @param  Collection<int, Label>  $labels
     */
    protected function apply(Connection $connection, string $did, Collection $labels): void
    {
        $accountHidden = $accountLimited = false;
        $hiddenUris = $limitedUris = $bans = $reviews = [];

        foreach ($labels as $label) {
            $target = Target::of($label->uri);
            $effect = $this->policies->effect($label->src, $label->val, $target);

            match (true) {
                $target === Target::ACCOUNT && $effect === Effect::Ban => $bans[] = [$label->src, $label->val],
                $target === Target::ACCOUNT && $effect === Effect::Review => $reviews[] = [$label->src, $label->val],
                $target === Target::ACCOUNT && $effect === Effect::Hide => $accountHidden = true,
                $effect->hides() => $hiddenUris[] = $label->uri,
                $effect === Effect::Review => $limitedUris[] = $label->uri,
                default => null,
            };
        }

        $actions = app(AccountActions::class);
        $actions->fromLabels($did, $bans, $reviews);

        $active = AccountAction::query()->active()->where('did', $did)->pluck('action')->all();
        $accountHidden = $accountHidden || in_array(AccountAction::BAN, $active, true);
        $accountLimited = $accountLimited || in_array(AccountAction::REVIEW, $active, true);

        $this->move($connection, $did, 'moderated_at', $accountHidden, $hiddenUris, fn (array $ids, bool $on) => app(RecordValues::class)->hideRecords($connection, $ids, $on));
        $this->move($connection, $did, 'limited_at', $accountLimited, $limitedUris, fn (array $ids, bool $on) => app(RecordValues::class)->limitRecords($connection, $ids, $on));
    }

    /**
     * Set a flag on the records that should have it, clear it from those
     * that should not, and tell the value index.
     *
     * @param  array<int, string>  $uris  Records that should have it, when not the whole account
     * @param  \Closure(array<int, int>, bool): void  $values
     */
    protected function move(Connection $connection, string $did, string $column, bool $whole, array $uris, \Closure $values): void
    {
        $live = fn () => $connection->table('atp_records')->where('did', $did)->whereNull('deleted_at');

        $want = $whole ? $live()->pluck('id')->all() : ($uris === [] ? [] : $live()->whereIn('uri', $uris)->pluck('id')->all());
        $have = $live()->whereNotNull($column)->pluck('id')->all();

        $on = array_map('intval', array_values(array_diff($want, $have)));
        $off = array_map('intval', array_values(array_diff($have, $want)));

        if ($on !== []) {
            $connection->table('atp_records')->whereIn('id', $on)->update([$column => Carbon::now('UTC')->format('Y-m-d H:i:s')]);
            $values($on, true);
        }

        if ($off !== []) {
            $connection->table('atp_records')->whereIn('id', $off)->update([$column => null]);
            $values($off, false);
        }
    }
}
