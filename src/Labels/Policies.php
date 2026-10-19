<?php

namespace SocialDept\AtpAppview\Labels;

use Illuminate\Support\Facades\Cache;
use SocialDept\AtpAppview\Models\Label;
use SocialDept\AtpAppview\Models\Labeler;
use SocialDept\AtpAppview\Models\LabelPolicy;

/**
 * What each label does, for content and for accounts. In order:
 *
 * 1. An operator's policy in atp_label_policies, for the labeler, then for
 *    any labeler (*).
 * 2. atp-appview.labels.policies, the same way.
 * 3. The global values every labeler may use: !hide, !warn, !takedown and
 *    !suspend hide, and !no-promote keeps content out of discovery.
 * 4. The labeler's declaration of the value: its defaultSetting, what it
 *    blurs and its severity.
 * 5. The defaults for the values Bluesky defines, such as porn and
 *    graphic-media, which labelers use without defining them. On an
 *    account they blur its avatar and banner, as Bluesky does, and leave
 *    what it wrote to the labels on that.
 *
 * Anything else is ignored: still listed on what it labels, doing nothing.
 */
class Policies
{
    /**
     * Bluesky's own values, with what they do when a labeler leaves them
     * undefined, and what a blur covers.
     */
    public const DEFAULTS = [
        'porn' => [Effect::Hide, 'media'],
        'sexual' => [Effect::Blur, 'media'],
        'sexual-figurative' => [Effect::Blur, 'media'],
        'nudity' => [Effect::Blur, 'media'],
        'graphic-media' => [Effect::Blur, 'media'],
        'gore' => [Effect::Blur, 'media'],
    ];

    public const GLOBAL = [
        '!hide' => Effect::Hide,
        '!takedown' => Effect::Hide,
        '!suspend' => Effect::Hide,
        '!warn' => Effect::Warn,
        '!no-promote' => Effect::Review,
    ];

    protected const CACHE_KEY = 'atp-appview:label-policies';

    /** @var array<string, Effect>|null Operator policies by "labeler value target" */
    protected ?array $overrides = null;

    /** @var array<string, Labeler>|null */
    protected ?array $labelers = null;

    protected int $loadedAt = 0;

    /**
     * What a label does to its target.
     */
    public function effect(string $labeler, string $value, string $target): Effect
    {
        $effect = $this->decide($labeler, $value, $target);

        // A ban on content means nothing: the strongest that does is hiding.
        return $effect->appliesTo($target) ? $effect : Effect::Hide;
    }

    /**
     * What a blur from this label covers: media, or the whole content.
     */
    public function blurs(string $labeler, string $value): string
    {
        $declared = $this->labelers()[$labeler] ?? null;
        $blurs = $declared?->definition($value)['blurs'] ?? self::DEFAULTS[$value][1] ?? 'content';

        return $blurs === 'media' ? 'media' : 'content';
    }

    /**
     * Set what a label does, for one labeler or every one (*).
     */
    public function set(string $labeler, string $value, string $target, Effect $effect): void
    {
        LabelPolicy::query()->updateOrCreate(['labeler' => $labeler, 'value' => $value, 'target' => $target], ['effect' => $effect]);
        $this->changed($labeler, $value);
    }

    /**
     * Drop an operator's policy, so config and the declaration decide again.
     */
    public function clear(string $labeler, string $value, string $target): void
    {
        LabelPolicy::query()->where(['labeler' => $labeler, 'value' => $value, 'target' => $target])->delete();
        $this->changed($labeler, $value);
    }

    /**
     * Forget what is loaded, here and in every other process.
     */
    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
        $this->overrides = null;
        $this->labelers = null;
    }

    protected function decide(string $labeler, string $value, string $target): Effect
    {
        $overrides = $this->overrides();

        foreach ([$labeler, LabelPolicy::ANY] as $who) {
            if (isset($overrides["{$who} {$value} {$target}"])) {
                return $overrides["{$who} {$value} {$target}"];
            }
        }

        $config = (array) config('atp-appview.labels.policies', []);

        foreach ([$labeler, LabelPolicy::ANY] as $who) {
            $configured = $config[$who][$value][$target] ?? null;

            if (is_string($configured) && ($effect = Effect::tryFrom($configured)) !== null) {
                return $effect;
            }
        }

        if (isset(self::GLOBAL[$value])) {
            return self::GLOBAL[$value];
        }

        $definition = ($this->labelers()[$labeler] ?? null)?->definition($value);

        if ($definition !== null) {
            return self::fromDefinition($definition);
        }

        if (! isset(self::DEFAULTS[$value])) {
            return Effect::Ignore;
        }

        return $target === Target::ACCOUNT ? Effect::Blur : self::DEFAULTS[$value][0];
    }

    /**
     * A declared definition as an effect: its defaultSetting, then whether
     * it blurs, then how severe it is.
     *
     * @param  array<string, mixed>  $definition
     */
    public static function fromDefinition(array $definition): Effect
    {
        return match ($definition['defaultSetting'] ?? 'warn') {
            'ignore' => Effect::Ignore,
            'hide' => Effect::Hide,
            default => match (true) {
                ($definition['blurs'] ?? 'none') !== 'none' => Effect::Blur,
                ($definition['severity'] ?? 'none') === 'alert' => Effect::Warn,
                default => Effect::Badge,
            },
        };
    }

    /**
     * Apply a changed policy to the accounts its labels are on.
     */
    protected function changed(string $labeler, string $value): void
    {
        $this->forget();

        $subjects = Label::query()
            ->when($labeler !== LabelPolicy::ANY, fn ($query) => $query->where('src', $labeler))
            ->where('val', $value)
            ->distinct()
            ->pluck('subject')
            ->all();

        app(Visibility::class)->refresh($subjects);
    }

    /**
     * @return array<string, Effect>
     */
    protected function overrides(): array
    {
        $this->expire();

        if ($this->overrides === null) {
            $stored = Cache::remember(self::CACHE_KEY, 300, fn () => LabelPolicy::query()->get()
                ->mapWithKeys(fn (LabelPolicy $policy) => ["{$policy->labeler} {$policy->value} {$policy->target}" => $policy->effect->value])
                ->all());

            $this->overrides = array_map(fn (string $effect) => Effect::from($effect), (array) $stored);
        }

        return $this->overrides;
    }

    /**
     * @return array<string, Labeler>
     */
    protected function labelers(): array
    {
        $this->expire();

        return $this->labelers ??= Labeler::query()->get()->keyBy('did')->all();
    }

    /**
     * Long-running workers read policies again every half minute, so a
     * change made elsewhere reaches them.
     */
    protected function expire(): void
    {
        if (time() - $this->loadedAt > 30) {
            $this->overrides = null;
            $this->labelers = null;
            $this->loadedAt = time();
        }
    }
}
