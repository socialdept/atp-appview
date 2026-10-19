<?php

namespace SocialDept\AtpAppview\Index;

use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;

/**
 * What an account's status means for its records.
 *
 * - Deleted: the records no longer exist, so they are tombstoned like any
 *   deleted record.
 * - Deactivated, taken down, suspended or otherwise inactive: the account may
 *   come back, so its records are hidden, and tombstoned only once it has
 *   been inactive for index.inactive.purge_after_days.
 * - Active again: hidden records are shown.
 *
 * Tombstones keep each record's own revision rather than a newer one. An
 * older event replayed later still cannot bring a record back, but a repo
 * that turns out to exist after all is restored by appview:reconcile, whose
 * listing is at least as new.
 */
class AccountStatus
{
    public const DELETED = 'deleted';

    /**
     * Inactive statuses a repo's own PDS reports and appview:reconcile can
     * therefore see lifted. A takedown or suspension may come from the relay
     * while the PDS still serves the repo, so only Tap lifts those.
     */
    public const LIFTED_BY_PDS = ['deactivated', self::DELETED];

    public function __construct(
        protected Connection $connection,
    ) {
    }

    /**
     * Record accounts' status and apply it to their records.
     *
     * @param  array<string, array{active: bool, status: string|null}>  $accounts  Keyed by DID
     */
    public function set(array $accounts): void
    {
        foreach ($accounts as $did => $account) {
            $this->connection->table('atp_identities')->where('did', $did)->update([
                'active' => $account['active'],
                'status' => $account['status'],
            ]);
        }

        $this->apply($accounts);
    }

    /**
     * Apply accounts' status, already stored, to their records.
     *
     * @param  array<string, array{active: bool, status: string|null}>  $accounts  Keyed by DID
     */
    public function apply(array $accounts): void
    {
        $groups = ['active' => [], 'deleted' => [], 'hidden' => []];

        foreach ($accounts as $did => $account) {
            $groups[match (true) {
                $account['active'] => 'active',
                $account['status'] === self::DELETED => 'deleted',
                default => 'hidden',
            }][] = $did;
        }

        $this->reactivate($groups['active']);
        $this->delete($groups['deleted']);
        $this->hide($groups['hidden']);
    }

    /**
     * Hide records that arrived for accounts already inactive, such as an
     * event Tap delivered after the account's status changed.
     *
     * @param  array<int, string>  $dids
     */
    public function hideArrivals(array $dids): void
    {
        if ($dids === []) {
            return;
        }

        $inactive = $this->connection->table('atp_identities')
            ->whereIn('did', array_values(array_unique($dids)))
            ->where('active', false)
            ->pluck('did')
            ->all();

        $this->hide($inactive, markInactive: false);
    }

    /**
     * Tombstone the records of accounts inactive since before the cutoff,
     * a batch of accounts at a time.
     *
     * @return array<string, int> Records tombstoned, or that would be, by DID
     */
    public function purge(Carbon $before, int $limit, bool $dryRun = false): array
    {
        $dids = $this->connection->table('atp_identities')
            ->where('active', false)
            ->where('inactive_since', '<', $before)
            ->whereExists(fn ($query) => $query->selectRaw('1')
                ->from('atp_records')
                ->whereColumn('atp_records.did', 'atp_identities.did')
                ->whereNull('atp_records.deleted_at'))
            ->orderBy('inactive_since')
            ->limit(max(1, $limit))
            ->pluck('did')
            ->all();

        $counts = [];

        foreach ($dids as $did) {
            $counts[$did] = $dryRun
                ? $this->live([$did])->count()
                : $this->tombstone([$did]);
        }

        return $counts;
    }

    /**
     * @param  array<int, string>  $dids
     */
    protected function reactivate(array $dids): void
    {
        foreach (array_chunk($dids, 500) as $chunk) {
            // Most account events are for active accounts, so touch only rows
            // that change.
            // Only accounts that were inactive have anything hidden.
            $returning = $this->connection->table('atp_identities')->whereIn('did', $chunk)->whereNotNull('inactive_since')->pluck('did')->all();

            if ($returning === []) {
                continue;
            }

            $this->connection->table('atp_identities')->whereIn('did', $returning)->update(['inactive_since' => null]);
            $this->connection->table('atp_records')->whereIn('did', $returning)->whereNotNull('hidden_at')->update(['hidden_at' => null]);
            app(RecordValues::class)->hide($this->connection, $returning, false);
        }
    }

    /**
     * @param  array<int, string>  $dids
     */
    protected function delete(array $dids): void
    {
        $this->markInactive($dids);
        $this->tombstone($dids);
    }

    /**
     * @param  array<int, string>  $dids
     */
    protected function hide(array $dids, bool $markInactive = true): void
    {
        if ($markInactive) {
            $this->markInactive($dids);
        }

        $now = $this->now();

        foreach (array_chunk($dids, 500) as $chunk) {
            $this->live($chunk)->whereNull('hidden_at')->update(['hidden_at' => $now]);
            app(RecordValues::class)->hide($this->connection, $chunk);
        }
    }

    /**
     * @param  array<int, string>  $dids
     */
    protected function markInactive(array $dids): void
    {
        $now = $this->now();

        foreach (array_chunk($dids, 500) as $chunk) {
            $this->connection->table('atp_identities')->whereIn('did', $chunk)->whereNull('inactive_since')->update(['inactive_since' => $now]);
        }
    }

    /**
     * @param  array<int, string>  $dids
     */
    protected function tombstone(array $dids): int
    {
        $count = 0;
        $now = $this->now();

        foreach (array_chunk($dids, 500) as $chunk) {
            $count += $this->live($chunk)->update(['deleted_at' => $now, 'record' => null, 'cid' => null, 'hidden_at' => null]);
            app(RecordValues::class)->drop($this->connection, $chunk);
        }

        return $count;
    }

    /**
     * @param  array<int, string>  $dids
     */
    protected function live(array $dids): \Illuminate\Database\Query\Builder
    {
        return $this->connection->table('atp_records')->whereIn('did', $dids)->whereNull('deleted_at');
    }

    protected function now(): string
    {
        return Carbon::now('UTC')->format('Y-m-d H:i:s');
    }
}
