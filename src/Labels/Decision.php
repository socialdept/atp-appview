<?php

namespace SocialDept\AtpAppview\Labels;

use SocialDept\AtpAppview\Models\Label;

/**
 * What to do with one piece of content, from the labels on it and on its
 * author, or with one account, from the labels on it.
 *
 * For content, the labels on the record decide how it is shown, and the
 * author's account labels decide whether it is shown at all: an account
 * label that hides, needs review or bans applies to everything the account
 * wrote, while its warnings, blurs and badges belong to the account, in
 * author.
 */
final class Decision
{
    /**
     * @param  array<int, Label>  $labels  The labels it was decided from
     * @param  array<int, array{src: string, val: string}>  $warnings
     * @param  array<int, array{src: string, val: string}>  $badges
     * @param  string|null  $blur  media or content, when something blurs
     */
    public function __construct(
        public readonly array $labels = [],
        public readonly bool $hide = false,
        public readonly bool $review = false,
        public readonly bool $ban = false,
        public readonly array $warnings = [],
        public readonly ?string $blur = null,
        public readonly array $badges = [],
        public readonly ?Decision $author = null,
    ) {
    }

    /**
     * Decide from labels, all about one target: a record, or an account.
     *
     * @param  iterable<Label>  $labels
     */
    public static function from(iterable $labels, string $target, Policies $policies, ?Decision $author = null): self
    {
        $hide = $review = $ban = false;
        $warnings = $badges = $kept = [];
        $blur = null;

        foreach ($labels as $label) {
            $kept[] = $label;
            $effect = $policies->effect($label->src, $label->val, $target);
            $named = ['src' => $label->src, 'val' => $label->val];

            match ($effect) {
                Effect::Ban => $ban = $hide = true,
                Effect::Hide => $hide = true,
                Effect::Review => $review = true,
                Effect::Warn => $warnings[] = $named,
                Effect::Blur => $blur = ($blur === 'content' ? 'content' : ($target === Target::ACCOUNT ? 'media' : $policies->blurs($label->src, $label->val))),
                Effect::Badge => $badges[] = $named,
                Effect::Ignore => null,
            };

            if ($effect === Effect::Blur) {
                $warnings[] = $named;
            }
        }

        // What hides, needs review or bans an account does so to its content.
        if ($author !== null) {
            $hide = $hide || $author->hide;
            $review = $review || $author->review;
            $ban = $author->ban;
        }

        return new self($kept, $hide, $review, $ban, $warnings, $blur, $badges, $author);
    }

    /**
     * With an account's actions in force: a ban hides and bans, a review
     * needs review.
     *
     * @param  array<int, string>  $actions
     */
    public function withActions(array $actions): self
    {
        if ($actions === []) {
            return $this;
        }

        $ban = $this->ban || in_array('ban', $actions, true);

        return new self($this->labels, $this->hide || $ban, $this->review || in_array('review', $actions, true), $ban, $this->warnings, $this->blur, $this->badges, $this->author);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'hide' => $this->hide,
            'review' => $this->review,
            'ban' => $this->ban,
            'warn' => $this->warnings,
            'blur' => $this->blur,
            'badges' => $this->badges,
            'author' => $this->author?->toArray(),
        ], fn ($value) => $value !== null && $value !== false && $value !== []);
    }
}
