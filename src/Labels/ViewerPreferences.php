<?php

namespace SocialDept\AtpAppview\Labels;

use SocialDept\AtpAppview\Models\LabelPolicy;

/**
 * One viewer's label preferences, read once for a request, and what they
 * make of each label's effect.
 *
 * A viewer may loosen or tighten only what the operator left to them: a
 * policy that ignores, badges, warns or blurs. One that hides, needs review
 * or bans stays, except that a viewer may always hide more. System labels,
 * starting with !, are never the viewer's to change. With adult content off,
 * adult-only labels hide whatever was chosen for them.
 */
final class ViewerPreferences
{
    /**
     * @param  array<string, string>  $visibilities  "labeler value" => ignore, warn or hide, labeler * for any
     */
    public function __construct(
        protected readonly Policies $policies,
        public readonly array $visibilities = [],
        public readonly bool $adultContent = true,
    ) {
    }

    /**
     * What the viewer chose for a label: for its labeler, then for any.
     */
    public function visibility(string $labeler, string $value): ?string
    {
        return $this->visibilities["{$labeler} {$value}"] ?? $this->visibilities[LabelPolicy::ANY.' '.$value] ?? null;
    }

    /**
     * A label's effect as this viewer sees it, from the operator's.
     */
    public function effect(Effect $policy, string $labeler, string $value): Effect
    {
        if (str_starts_with($value, '!')) {
            return $policy;
        }

        $wanted = ! $this->adultContent && $this->policies->adultOnly($labeler, $value)
            ? Effect::Hide
            : match ($this->visibility($labeler, $value)) {
                LabelPreferences::IGNORE => Effect::Ignore,
                // A warning keeps the blur the policy gives, if any.
                LabelPreferences::WARN => in_array($policy, [Effect::Warn, Effect::Blur], true) ? $policy : Effect::Warn,
                LabelPreferences::HIDE => Effect::Hide,
                default => null,
            };

        if ($wanted === null) {
            return $policy;
        }

        // Hiding is always the viewer's to choose, and a ban stays a ban.
        if ($wanted === Effect::Hide) {
            return $policy === Effect::Ban ? $policy : Effect::Hide;
        }

        return in_array($policy, [Effect::Hide, Effect::Review, Effect::Ban], true) ? $policy : $wanted;
    }

    /**
     * The labels this viewer hides, for leaving what they label out of
     * queries: each value with its labeler, * for any, and the labelers a
     * choice of their own excuses from a hide for any.
     *
     * @return array<int, array{labeler: string, value: string, except: array<int, string>}>
     */
    public function hidden(): array
    {
        $hidden = [];

        foreach ($this->visibilities as $key => $visibility) {
            [$labeler, $value] = explode(' ', $key, 2);

            if ($visibility === LabelPreferences::HIDE) {
                $hidden[$key] = ['labeler' => $labeler, 'value' => $value, 'except' => []];
            }
        }

        foreach ($this->visibilities as $key => $visibility) {
            [$labeler, $value] = explode(' ', $key, 2);

            if ($visibility !== LabelPreferences::HIDE && isset($hidden[LabelPolicy::ANY.' '.$value])) {
                $hidden[LabelPolicy::ANY.' '.$value]['except'][] = $labeler;
            }
        }

        // Adult-only labels hide whatever was chosen for them.
        foreach ($this->adultContent ? [] : $this->policies->adultValues() as [$labeler, $value]) {
            $hidden["{$labeler} {$value}"] = ['labeler' => $labeler, 'value' => $value, 'except' => []];
        }

        return array_values($hidden);
    }

    /**
     * Whether nothing differs from the operator's policies.
     */
    public function neutral(): bool
    {
        return $this->visibilities === [] && $this->adultContent;
    }
}
