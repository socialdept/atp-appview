<?php

namespace SocialDept\AtpAppview\Spaces;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use LogicException;
use SocialDept\AtpAppview\Features\AppView;
use SocialDept\AtpAppview\Features\Moderation as ModerationFeature;
use SocialDept\AtpAppview\Features\Spaces as SpacesFeature;
use SocialDept\AtpAppview\Labels\Effect;
use SocialDept\AtpAppview\Labels\Policies;
use SocialDept\AtpAppview\Labels\Target;
use SocialDept\AtpAppview\Models\AccountAction;
use SocialDept\AtpAppview\Models\Casts\PreciseDateTime;
use SocialDept\AtpAppview\Models\Label;
use SocialDept\AtpAppview\Models\SpaceRecord;
use SocialDept\AtpAppview\Spaces\Contracts\SpaceAccess;
use SocialDept\AtpSpaces\SpaceRef;

/**
 * A page of a followed space's records, for a viewer SpaceAccess allows.
 *
 *     SpaceRecordQuery::in($space)
 *         ->visibleTo($did)
 *         ->collection('com.example.post')
 *         ->after($cursor)
 *         ->limit(50)
 *         ->get();
 *
 * Without visibleTo(), or for a viewer SpaceAccess does not allow, it finds
 * nothing. Records come newest first by sort_at, in keyset pages that never
 * skip or repeat a record while new ones arrive.
 *
 * Moderation applies as it does to public records: nothing by accounts the
 * viewer blocks, is blocked by or muted, or by inactive accounts, and with
 * the moderation feature nothing by banned accounts or that a label hides.
 * What needs review is left out unless the query is for one repo. Record filters, such as muted words, read the public value
 * index, which space records are never in, so they do not apply.
 */
class SpaceRecordQuery
{
    protected ?string $collection = null;

    protected ?string $did = null;

    protected bool $scoped = false;

    protected ?string $viewer = null;

    protected ?string $cursor = null;

    protected int $limit = 50;

    /**
     * @throws LogicException when the spaces feature is off
     */
    public function __construct(protected SpaceRef $space)
    {
        SpacesFeature::ensureOn();
    }

    /**
     * @param  SpaceRef|string  $space  A space, or its URI
     */
    public static function in(SpaceRef|string $space): static
    {
        return new static($space instanceof SpaceRef ? $space : SpaceRef::parse($space));
    }

    /**
     * Only records in one collection.
     */
    public function collection(string $collection): static
    {
        $this->collection = $collection;

        return $this;
    }

    /**
     * Only records in one member's repo.
     */
    public function by(string $did): static
    {
        $this->did = $did;

        return $this;
    }

    /**
     * Who is reading, a DID or null for a signed-out viewer. Records come
     * back only when SpaceAccess allows them the space.
     */
    public function visibleTo(?string $viewer): static
    {
        $this->scoped = true;
        $this->viewer = $viewer;

        return $this;
    }

    public function after(?string $cursor): static
    {
        $this->cursor = $cursor === '' ? null : $cursor;

        return $this;
    }

    public function limit(int $limit): static
    {
        $this->limit = max(1, $limit);

        return $this;
    }

    /**
     * @return array{records: Collection<int, SpaceRecord>, cursor: string|null}
     */
    public function get(): array
    {
        if (! $this->scoped || ! app(SpaceAccess::class)->allows($this->space, $this->viewer)) {
            return ['records' => new Collection(), 'cursor' => null];
        }

        $rows = $this->query()->limit($this->limit + 1)->get();
        $page = $rows->take($this->limit)->values();
        $last = $page->last();

        return [
            'records' => $page,
            'cursor' => $rows->count() > $this->limit ? self::encode($last->sort_at->format(PreciseDateTime::FORMAT), $last->uri) : null,
        ];
    }

    /**
     * @return Builder<SpaceRecord>
     */
    protected function query(): Builder
    {
        $query = SpaceRecord::query()
            ->where('space', (string) $this->space)
            ->whereNotNull('sort_at')
            ->when($this->collection !== null, fn (Builder $query) => $query->where('collection', $this->collection))
            ->when($this->did !== null, fn (Builder $query) => $query->where('did', $this->did));

        if ($this->viewer !== null && ($hidden = app(AppView::class)->hidden($this->viewer)) !== []) {
            $query->whereNotIn('did', $hidden);
        }

        $this->moderate($query);

        if ($this->cursor !== null) {
            [$value, $uri] = self::decode($this->cursor);

            $query->where('sort_at', '<=', $value);
            $query->where(fn (Builder $query) => $query->where('sort_at', '<', $value)
                ->orWhere(fn (Builder $query) => $query->where('sort_at', $value)->where('uri', '<', $uri)));
        }

        return $query->orderByDesc('sort_at')->orderByDesc('uri');
    }

    /**
     * Leave out what moderation hides, and, across repos, what needs review.
     * Space records carry no moderation columns, since labels and account
     * actions are about accounts and public URIs, so it is worked out here
     * from the accounts and labels as they are now.
     *
     * @param  Builder<SpaceRecord>  $query
     */
    protected function moderate(Builder $query): void
    {
        $records = $query->getModel()->getTable();

        // Deactivated, taken down or suspended.
        $query->whereNotExists(fn ($identities) => $identities->selectRaw('1')->from('atp_identities')
            ->whereColumn('atp_identities.did', "{$records}.did")
            ->where('atp_identities.active', false));

        // Labels and account actions come with the moderation feature.
        if (! ModerationFeature::on()) {
            return;
        }

        $actions = $this->did === null ? [AccountAction::BAN, AccountAction::REVIEW] : [AccountAction::BAN];

        $query->whereNotExists(fn ($active) => $active->selectRaw('1')->from('atp_account_actions')
            ->whereColumn('atp_account_actions.did', "{$records}.did")
            ->whereIn('atp_account_actions.action', $actions)
            ->whereNull('atp_account_actions.lifted_at'));

        [$accounts, $uris] = $this->labelled();

        if ($accounts !== []) {
            $query->whereNotIn('did', $accounts);
        }

        if ($uris !== []) {
            $query->whereNotIn('uri', $uris);
        }
    }

    /**
     * The accounts and records labels keep out: what a label hides, and what
     * needs review unless the query is for one repo.
     *
     * @return array{0: array<int, string>, 1: array<int, string>} Account DIDs and record URIs
     */
    protected function labelled(): array
    {
        $space = (string) $this->space;
        $held = fn (string $column) => SpaceRecord::query()->withTrashed()->select($column)->where('space', $space);

        $labels = Label::query()->live()
            ->where(fn ($query) => $query
                // Labels on members' accounts, or their profiles.
                ->where(fn ($query) => $query->whereIn('subject', $held('did')->distinct())
                    ->where(fn ($query) => $query->where('uri', 'not like', 'at://%')->orWhere('uri', 'like', 'at://%/app.bsky.actor.profile/%')))
                // Labels on this space's records.
                ->orWhereIn('uri', $held('uri')))
            ->get(['src', 'uri', 'val']);

        $policies = app(Policies::class);
        $accounts = $uris = [];

        foreach ($labels as $label) {
            $target = str_starts_with($label->uri, $space.'/') ? Target::CONTENT : Target::ACCOUNT;
            $effect = $policies->effect($label->src, $label->val, $target);

            if (! $effect->hides() && ($effect !== Effect::Review || $this->did !== null)) {
                continue;
            }

            if ($target === Target::ACCOUNT) {
                $accounts[] = Target::subject($label->uri);
            } else {
                $uris[] = $label->uri;
            }
        }

        return [array_values(array_unique($accounts)), array_values(array_unique($uris))];
    }

    protected static function encode(string $value, string $uri): string
    {
        return rtrim(strtr(base64_encode($value.'|'.$uri), '+/', '-_'), '=');
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected static function decode(string $cursor): array
    {
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        $parts = $decoded === false ? [] : explode('|', $decoded, 2);

        if (count($parts) !== 2 || $parts[0] === '' || ! str_starts_with($parts[1], 'at://')) {
            throw new InvalidArgumentException('Malformed cursor.');
        }

        return $parts;
    }
}
