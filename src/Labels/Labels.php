<?php

namespace SocialDept\AtpAppview\Labels;

use Illuminate\Support\Collection;
use SocialDept\AtpAppview\Models\Label;
use SocialDept\AtpAppview\Models\Labeler;
use SocialDept\AtpAppview\Models\Record;

/**
 * Everything about labels in one place: what is labeled, what labels do,
 * the policies that decide it, and the labelers Tack follows.
 *
 *     app(Labels::class)->decide($record)->hide;
 *     app(Labels::class)->account('did:plc:...')->review;
 *     app(Labels::class)->set('*', 'spam', Target::ACCOUNT, Effect::Review);
 *     app(Labels::class)->follow('did:plc:...');
 */
class Labels
{
    public function __construct(
        protected Policies $policies,
        protected Visibility $visibility,
    ) {
    }

    /**
     * The live labels on these uris or DIDs, by uri.
     *
     * @param  string|array<int, string>  $uris
     * @return Collection<string, Collection<int, Label>>
     */
    public function on(string|array $uris): Collection
    {
        return Label::query()->live()->whereIn('uri', (array) $uris)->orderBy('cts')->get()->groupBy('uri');
    }

    /**
     * Every live label about an account: on it, and on its records.
     *
     * @return Collection<int, Label>
     */
    public function about(string $did): Collection
    {
        return Label::query()->live()->where('subject', $did)->orderBy('cts')->get();
    }

    /**
     * What to do with an account, from the labels on it and its profile.
     */
    public function account(string $did): Decision
    {
        return $this->accounts([$did])[$did];
    }

    /**
     * @param  array<int, string>  $dids
     * @return array<string, Decision> By DID
     */
    public function accounts(array $dids): array
    {
        $dids = array_values(array_unique($dids));
        $labels = Label::query()->live()->whereIn('subject', $dids)->get()
            ->filter(fn (Label $label) => Target::of($label->uri) === Target::ACCOUNT)
            ->groupBy('subject');

        $decisions = [];
        foreach ($dids as $did) {
            $decisions[$did] = Decision::from($labels[$did] ?? [], Target::ACCOUNT, $this->policies);
        }

        return $decisions;
    }

    /**
     * What to do with one record.
     */
    public function decide(Record $record): Decision
    {
        return $this->decideMany(new Collection([$record]))[$record->uri];
    }

    /**
     * What to do with each record of a page, in two queries.
     *
     * @param  Collection<int, Record>  $records
     * @return array<string, Decision> By uri
     */
    public function decideMany(Collection $records): array
    {
        if ($records->isEmpty()) {
            return [];
        }

        $authors = $this->accounts($records->pluck('did')->all());
        $labels = Label::query()->live()->whereIn('uri', $records->pluck('uri')->all())->get()->groupBy('uri');

        return $records->mapWithKeys(fn (Record $record) => [
            $record->uri => Decision::from($labels[$record->uri] ?? [], Target::CONTENT, $this->policies, $authors[$record->did]),
        ])->all();
    }

    /**
     * What a label does to content or to an account.
     */
    public function effect(string $labeler, string $value, string $target): Effect
    {
        return $this->policies->effect($labeler, $value, $target);
    }

    /**
     * Decide what a label does, for one labeler or every one (*), and apply
     * it to what it labels.
     */
    public function set(string $labeler, string $value, string $target, Effect $effect): void
    {
        $this->policies->set($labeler, $value, $target, $effect);
    }

    /**
     * Drop a decision, so config and the labeler's declaration decide again.
     */
    public function clear(string $labeler, string $value, string $target): void
    {
        $this->policies->clear($labeler, $value, $target);
    }

    /**
     * The labelers Tack has told the app about, with their declarations.
     *
     * @return Collection<int, Labeler>
     */
    public function labelers(): Collection
    {
        return Labeler::query()->orderBy('did')->get();
    }

    /**
     * Ask Tack to follow a labeler. Its declaration arrives as an event.
     */
    public function follow(string $did): void
    {
        app(Tack::class)->addLabeler($did);
    }

    /**
     * Ask Tack to stop following a labeler. Its labels go once Tack says so.
     */
    public function unfollow(string $did): void
    {
        app(Tack::class)->removeLabeler($did);
    }

    /**
     * Apply what labels do to these accounts' records again.
     *
     * @param  array<int, string>  $dids
     */
    public function refresh(array $dids): void
    {
        $this->visibility->refresh($dids);
    }
}
