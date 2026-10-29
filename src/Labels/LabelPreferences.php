<?php

namespace SocialDept\AtpAppview\Labels;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use SocialDept\AtpAppview\Features\Moderation as ModerationFeature;
use SocialDept\AtpAppview\Index\RecordTables;
use SocialDept\AtpAppview\Models\LabelPolicy;

/**
 * What each viewer chose to see for a label, in the shape of Bluesky's
 * contentLabelPref and adultContentEnabled, so an app can map those onto
 * it: a value, from one labeler or any, shown without a warning (ignore),
 * behind one (warn), or not at all (hide).
 *
 * Only what the operator left to viewers changes, see ViewerPreferences. A
 * hide leaves what the label is on out of the viewer's queries, like a mute,
 * and the Moderation view part carries the rest. Neither applies to the
 * viewer's own records.
 *
 * Read once per request. Needs the moderation feature: off, every viewer
 * sees what the policies decide, and set() refuses.
 *
 *     app(LabelPreferences::class)->set($did, 'graphic-media', 'ignore');
 *     app(LabelPreferences::class)->set($did, 'spoiler', 'hide', labeler: 'did:plc:...');
 *     app(LabelPreferences::class)->setAdultContent($did, false);
 */
class LabelPreferences
{
    public const IGNORE = 'ignore';

    public const WARN = 'warn';

    public const HIDE = 'hide';

    /** @var array<string, ViewerPreferences> */
    protected array $loaded = [];

    public function __construct(
        protected Policies $policies,
    ) {
    }

    /**
     * A viewer's choices: whether they see adult content, and each label
     * they chose for, labeler null for any.
     *
     * @return array{adultContent: bool, labels: array<int, array{labeler: string|null, value: string, visibility: string}>}
     */
    public function for(string $did): array
    {
        $preferences = $this->viewer($did);
        $labels = [];

        foreach ($preferences->visibilities as $key => $visibility) {
            [$labeler, $value] = explode(' ', $key, 2);
            $labels[] = ['labeler' => $labeler === LabelPolicy::ANY ? null : $labeler, 'value' => $value, 'visibility' => $visibility];
        }

        return ['adultContent' => $preferences->adultContent, 'labels' => $labels];
    }

    /**
     * Choose what a viewer sees for a label value, from one labeler or any.
     * Bluesky's show is taken as ignore.
     */
    public function set(string $did, string $value, string $visibility, ?string $labeler = null): void
    {
        ModerationFeature::ensureOn();

        $visibility = $visibility === 'show' ? self::IGNORE : $visibility;

        if (! in_array($visibility, [self::IGNORE, self::WARN, self::HIDE], true)) {
            throw new InvalidArgumentException("A label's visibility is ignore, warn or hide, not '{$visibility}'.");
        }

        if ($value === '' || strlen($value) > 128) {
            throw new InvalidArgumentException('A label value is 1 to 128 characters.');
        }

        if (str_starts_with($value, '!')) {
            throw new InvalidArgumentException("System labels such as {$value} are not the viewer's to change.");
        }

        if ($labeler !== null && ! str_starts_with($labeler, 'did:')) {
            throw new InvalidArgumentException('A labeler is named by its DID, or null for any.');
        }

        $this->connection()->table('atp_label_preferences')->upsert(
            [['did' => $did, 'labeler' => $labeler ?? LabelPolicy::ANY, 'value' => $value, 'visibility' => $visibility, 'updated_at' => $this->now()]],
            ['did', 'labeler', 'value'],
            ['visibility', 'updated_at'],
        );

        unset($this->loaded[$did]);
    }

    /**
     * Drop a choice, so the policy decides again.
     */
    public function clear(string $did, string $value, ?string $labeler = null): void
    {
        if (! ModerationFeature::on()) {
            return;
        }

        $this->connection()->table('atp_label_preferences')
            ->where('did', $did)->where('labeler', $labeler ?? LabelPolicy::ANY)->where('value', $value)
            ->delete();

        unset($this->loaded[$did]);
    }

    /**
     * Whether a viewer sees adult content. Off, adult-only labels hide what
     * they are on, whatever was chosen for them.
     */
    public function setAdultContent(string $did, bool $enabled): void
    {
        ModerationFeature::ensureOn();

        $this->connection()->table('atp_label_settings')->upsert(
            [['did' => $did, 'adult_content' => $enabled, 'updated_at' => $this->now()]],
            ['did'],
            ['adult_content', 'updated_at'],
        );

        unset($this->loaded[$did]);
    }

    /**
     * A viewer's preferences, read once for the request. Neutral for a
     * signed-out viewer and with the feature off.
     */
    public function viewer(?string $did): ViewerPreferences
    {
        $default = (bool) config('atp-appview.labels.adult_content', true);

        if ($did === null || ! ModerationFeature::on()) {
            return new ViewerPreferences($this->policies, adultContent: $default);
        }

        if (isset($this->loaded[$did])) {
            return $this->loaded[$did];
        }

        $visibilities = $this->connection()->table('atp_label_preferences')
            ->where('did', $did)
            ->orderBy('labeler')->orderBy('value')
            ->get(['labeler', 'value', 'visibility'])
            ->mapWithKeys(fn ($row) => ["{$row->labeler} {$row->value}" => (string) $row->visibility])
            ->all();

        $adult = $this->connection()->table('atp_label_settings')->where('did', $did)->value('adult_content');

        return $this->loaded[$did] = new ViewerPreferences($this->policies, $visibilities, $adult === null ? $default : (bool) $adult);
    }

    /**
     * Leave out of a query the records the viewer hides by their labels, or
     * by their author's account labels, by the column holding each record's
     * id in atp_records, or in the records table given. The viewer's own
     * records stay.
     */
    public function apply(Builder $query, string $viewer, string $idColumn, ?RecordTables $tables = null): void
    {
        $records = ($tables ?? RecordTables::index())->records;
        $hidden = $this->viewer($viewer)->hidden();

        if ($hidden === []) {
            return;
        }

        $now = $this->now();
        $matches = fn (Builder $query, string $alias) => $query
            ->where(function (Builder $query) use ($alias, $hidden) {
                foreach ($hidden as $label) {
                    $query->orWhere(fn (Builder $query) => $query->where("{$alias}.val", $label['value'])
                        ->when($label['labeler'] !== LabelPolicy::ANY, fn (Builder $query) => $query->where("{$alias}.src", $label['labeler']))
                        ->when($label['except'] !== [], fn (Builder $query) => $query->whereNotIn("{$alias}.src", $label['except'])));
                }
            })
            ->where(fn (Builder $query) => $query->whereNull("{$alias}.exp")->orWhere("{$alias}.exp", '>', $now));

        // Labels on the record itself, by the labels' uri index.
        $query->whereNotExists(fn (Builder $exists) => $matches($exists->selectRaw('1')
            ->from($records.' as preferred')
            ->join('atp_labels as preferred_label', 'preferred_label.uri', '=', 'preferred.uri')
            ->whereColumn('preferred.id', $idColumn)
            ->where('preferred.did', '!=', $viewer), 'preferred_label'));

        // Labels on its author: on the DID or the profile record, by the
        // labels' subject index.
        $query->whereNotExists(fn (Builder $exists) => $matches($exists->selectRaw('1')
            ->from($records.' as preferred')
            ->join('atp_labels as preferred_label', 'preferred_label.subject', '=', 'preferred.did')
            ->whereColumn('preferred.id', $idColumn)
            ->where('preferred.did', '!=', $viewer)
            ->where(fn (Builder $query) => $query->where('preferred_label.uri', 'not like', 'at://%')
                ->orWhere('preferred_label.uri', 'like', 'at://%/app.bsky.actor.profile/%')), 'preferred_label'));
    }

    protected function now(): string
    {
        return Carbon::now('UTC')->format('Y-m-d H:i:s');
    }

    protected function connection(): Connection
    {
        return DB::connection(config('atp-appview.index.connection'));
    }
}
