<?php

namespace SocialDept\AtpAppview\Moderation;

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use SocialDept\AtpAppview\Features\Moderation as ModerationFeature;
use SocialDept\AtpAppview\Ingest\TapAdmin;
use SocialDept\AtpAppview\Labels\Visibility;
use SocialDept\AtpAppview\Models\AccountAction;
use SocialDept\AtpAppview\Moderation\Events\AccountActioned;
use SocialDept\AtpAppview\Pds\Hosts;
use Throwable;

/**
 * What an operator, or a label, does to an account.
 *
 * - Ban: the account cannot sign in, and everything it wrote is hidden and
 *   pruned after moderation.prune_banned_after_days unless the pruning is
 *   cancelled. Nothing new is indexed for it while banned.
 * - Review: everything it wrote needs review, left out of discovery and
 *   shown where asked for directly.
 * - Takedown: the account is taken down on a PDS the operator runs.
 *
 * Every action is a row in atp_account_actions, kept when lifted.
 */
class AccountActions
{
    public function __construct(
        protected Visibility $visibility,
        protected Hosts $hosts,
    ) {
    }

    public function ban(string $did, ?string $reason = null, ?string $actor = null): AccountAction
    {
        ModerationFeature::ensureOn();

        $days = config('atp-appview.moderation.prune_banned_after_days', 365);

        return $this->apply($did, AccountAction::BAN, AccountAction::MANUAL, $reason, $actor, [
            'prune_at' => $days === null ? null : Carbon::now('UTC')->addDays((int) $days),
        ]);
    }

    /**
     * Lift every ban, manual or from a label, and ask Tap to send the
     * account again, since nothing was indexed for it while banned.
     */
    public function unban(string $did, ?string $actor = null): int
    {
        ModerationFeature::ensureOn();

        $lifted = $this->lift($did, AccountAction::BAN, $actor);

        if ($lifted > 0) {
            try {
                app(TapAdmin::class)->resyncRepo($did);
            } catch (Throwable) {
                // appview:reconcile brings the account back in step anyway.
            }
        }

        return $lifted;
    }

    public function review(string $did, ?string $reason = null, ?string $actor = null): AccountAction
    {
        ModerationFeature::ensureOn();

        return $this->apply($did, AccountAction::REVIEW, AccountAction::MANUAL, $reason, $actor);
    }

    public function unreview(string $did, ?string $actor = null): int
    {
        ModerationFeature::ensureOn();

        return $this->lift($did, AccountAction::REVIEW, $actor);
    }

    /**
     * Take the account down on the PDS hosting it, which the operator runs.
     *
     * @throws RuntimeException when the operator does not run its PDS
     */
    public function takedown(string $did, ?string $reason = null, ?string $actor = null): AccountAction
    {
        ModerationFeature::ensureOn();

        $driver = $this->hosts->for($did) ?? throw new RuntimeException("{$did} is not hosted on a PDS in atp-appview.pds.hosts.");

        $action = $this->apply($did, AccountAction::TAKEDOWN, AccountAction::MANUAL, $reason, $actor, ['pds' => $this->hosts->urlFor($did)], refresh: false);
        $driver->takedown($did, 'atp-appview:'.$action->id);

        return $action;
    }

    public function restore(string $did, ?string $actor = null): int
    {
        ModerationFeature::ensureOn();

        $driver = $this->hosts->for($did) ?? throw new RuntimeException("{$did} is not hosted on a PDS in atp-appview.pds.hosts.");
        $driver->restore($did);

        return $this->lift($did, AccountAction::TAKEDOWN, $actor, refresh: false);
    }

    /**
     * Keep a banned account's records, hidden, for as long as the ban lasts.
     */
    public function cancelPrune(string $did): void
    {
        ModerationFeature::ensureOn();

        AccountAction::query()->active()->where('did', $did)->where('action', AccountAction::BAN)->update(['prune_cancelled' => true]);
    }

    public function resumePrune(string $did): void
    {
        ModerationFeature::ensureOn();

        AccountAction::query()->active()->where('did', $did)->where('action', AccountAction::BAN)->update(['prune_cancelled' => false]);
    }

    /**
     * Whether the account is banned. Before the moderation migration has run
     * there is no table, and nobody is.
     */
    public function banned(string $did): bool
    {
        if (! ModerationFeature::on()) {
            return false;
        }

        try {
            return AccountAction::query()->active()->where('did', $did)->where('action', AccountAction::BAN)->exists();
        } catch (QueryException) {
            return false;
        }
    }

    public function reviewed(string $did): bool
    {
        if (! ModerationFeature::on()) {
            return false;
        }

        return $this->active($did)->contains('action', AccountAction::REVIEW);
    }

    /**
     * Of these accounts, the banned ones.
     *
     * @param  array<int, string>  $dids
     * @return array<int, string>
     */
    public function bannedAmong(array $dids): array
    {
        if (! ModerationFeature::on()) {
            return [];
        }

        if ($dids === []) {
            return [];
        }

        try {
            return AccountAction::query()->active()->where('action', AccountAction::BAN)->whereIn('did', array_values(array_unique($dids)))->distinct()->pluck('did')->all();
        } catch (QueryException) {
            return [];
        }
    }

    /**
     * @return Collection<int, AccountAction>
     */
    public function active(string $did): Collection
    {
        if (! ModerationFeature::on()) {
            return new Collection();
        }

        return AccountAction::query()->active()->where('did', $did)->get();
    }

    /**
     * @return Collection<int, AccountAction>
     */
    public function history(string $did): Collection
    {
        if (! ModerationFeature::on()) {
            return new Collection();
        }

        return AccountAction::query()->where('did', $did)->orderByDesc('id')->get();
    }

    /**
     * Bring the bans and reviews labels apply in line with the labels on an
     * account now: one for each label that bans or needs review, lifted once
     * its label is gone. Manual actions are left alone.
     *
     * @param  array<int, array{0: string, 1: string}>  $bans  [labeler, value] of each label that bans
     * @param  array<int, array{0: string, 1: string}>  $reviews  The same, for needs review
     */
    public function fromLabels(string $did, array $bans, array $reviews): void
    {
        ModerationFeature::ensureOn();

        $days = config('atp-appview.moderation.prune_banned_after_days', 365);

        foreach ([AccountAction::BAN => $bans, AccountAction::REVIEW => $reviews] as $action => $labels) {
            $existing = AccountAction::query()->active()->where('did', $did)->where('action', $action)->where('source', AccountAction::LABEL)->get();
            $wanted = [];

            foreach ($labels as [$src, $val]) {
                $wanted["{$src} {$val}"] = true;

                if (! $existing->contains(fn (AccountAction $a) => $a->label_src === $src && $a->label_val === $val)) {
                    $this->record(AccountAction::query()->create([
                        'did' => $did, 'action' => $action, 'source' => AccountAction::LABEL,
                        'label_src' => $src, 'label_val' => $val,
                        'prune_at' => $action === AccountAction::BAN && $days !== null ? Carbon::now('UTC')->addDays((int) $days) : null,
                    ]), lifted: false);
                }
            }

            foreach ($existing as $row) {
                if (! isset($wanted["{$row->label_src} {$row->label_val}"])) {
                    $row->update(['lifted_at' => Carbon::now('UTC'), 'lifted_by' => 'label withdrawn']);
                    $this->record($row, lifted: true);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    protected function apply(string $did, string $action, string $source, ?string $reason, ?string $actor, array $extra = [], bool $refresh = true): AccountAction
    {
        $existing = AccountAction::query()->active()->where(['did' => $did, 'action' => $action, 'source' => $source])->first();

        if ($existing !== null) {
            return $existing;
        }

        $row = AccountAction::query()->create(['did' => $did, 'action' => $action, 'source' => $source, 'reason' => $reason, 'actor' => $actor, ...$extra]);

        if ($refresh) {
            $this->visibility->refresh([$did]);
        }

        $this->record($row, lifted: false);

        return $row;
    }

    protected function lift(string $did, string $action, ?string $actor, bool $refresh = true): int
    {
        $rows = AccountAction::query()->active()->where('did', $did)->where('action', $action)->get();

        foreach ($rows as $row) {
            $row->update(['lifted_at' => Carbon::now('UTC'), 'lifted_by' => $actor]);
            $this->record($row, lifted: true);
        }

        if ($refresh && $rows->isNotEmpty()) {
            $this->visibility->refresh([$did]);
        }

        return $rows->count();
    }

    /**
     * Tell listeners once the change is committed.
     */
    protected function record(AccountAction $action, bool $lifted): void
    {
        DB::connection(config('atp-appview.index.connection'))->afterCommit(fn () => event(new AccountActioned($action, $lifted)));
    }
}
