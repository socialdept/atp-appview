<?php

namespace SocialDept\AtpAppview\Labels;

/**
 * What a label does. Content labels are about a record, account labels about
 * an account and everything it wrote.
 *
 * - Ignore: nothing.
 * - Badge: shown, nothing hidden.
 * - Warn: shown behind a warning.
 * - Blur: media, or the whole record, blurred. On an account, its avatar and
 *   banner.
 * - Hide: left out of lists and views. On an account, every record of it.
 * - Review: the account needs review. Applied with account actions.
 * - Ban: the account is banned off the app. Applied with account actions.
 */
enum Effect: string
{
    case Ignore = 'ignore';
    case Badge = 'badge';
    case Warn = 'warn';
    case Blur = 'blur';
    case Hide = 'hide';
    case Review = 'review';
    case Ban = 'ban';

    /**
     * How strong an effect is, so the strongest of several labels wins.
     */
    public function weight(): int
    {
        return match ($this) {
            self::Ignore => 0,
            self::Badge => 1,
            self::Warn => 2,
            self::Blur => 3,
            self::Review => 4,
            self::Hide => 5,
            self::Ban => 6,
        };
    }

    /**
     * Whether it keeps content out of lists and views.
     */
    public function hides(): bool
    {
        return $this === self::Hide || $this === self::Ban;
    }

    /**
     * Whether it can apply to this target: review and ban are about accounts.
     */
    public function appliesTo(string $target): bool
    {
        return $target === Target::ACCOUNT || ! in_array($this, [self::Review, self::Ban], true);
    }
}
