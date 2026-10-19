<?php

namespace SocialDept\AtpAppview\Labels;

/**
 * What a label is about: a record, or an account. A label on an account's
 * profile record counts as about the account.
 */
final class Target
{
    public const CONTENT = 'content';

    public const ACCOUNT = 'account';

    public static function of(string $uri): string
    {
        if (! str_starts_with($uri, 'at://')) {
            return self::ACCOUNT;
        }

        $parts = explode('/', substr($uri, 5));

        return count($parts) === 3 && $parts[1] === 'app.bsky.actor.profile' ? self::ACCOUNT : self::CONTENT;
    }

    /**
     * The account a label is about: the uri for an account, the repo for a
     * record.
     */
    public static function subject(string $uri): string
    {
        return str_starts_with($uri, 'at://') ? explode('/', substr($uri, 5))[0] : $uri;
    }
}
