<?php

namespace SocialDept\AtpAppview\Blocklist;

use InvalidArgumentException;

/**
 * Parsing and matching for block subjects and collection patterns.
 */
final class Patterns
{
    protected const DID = '/^did:[a-z]+:[a-zA-Z0-9._:%-]+$/';

    protected const NSID = '/^[a-zA-Z][a-zA-Z0-9-]*(\.[a-zA-Z0-9-]+)+(\.[a-zA-Z][a-zA-Z0-9]*)$/';

    protected const NSID_PREFIX = '/^[a-zA-Z][a-zA-Z0-9-]*(\.[a-zA-Z0-9-]+)*\.\*$/';

    protected const HOST = '/^[a-z0-9*]([a-z0-9*.-]*[a-z0-9*])?(:\d+)?$/';

    /**
     * The kind and stored form of a subject: a DID as given, or a host pattern
     * lowercased with any scheme, path and trailing slash removed.
     *
     * @return array{BlockKind, string}
     *
     * @throws InvalidArgumentException
     */
    public static function subject(string $subject): array
    {
        $subject = trim($subject);

        if (str_starts_with($subject, 'did:')) {
            if (! preg_match(self::DID, $subject)) {
                throw new InvalidArgumentException("\"{$subject}\" is not a valid DID.");
            }

            return [BlockKind::Did, $subject];
        }

        $host = strtolower((string) preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $subject));
        $host = explode('/', $host, 2)[0];

        if ($host === '' || ! preg_match(self::HOST, $host) || trim($host, '*.') === '') {
            throw new InvalidArgumentException("\"{$subject}\" is neither a DID nor a PDS host such as pds.example or *.example.");
        }

        return [BlockKind::Host, $host];
    }

    /**
     * The stored form of a collection pattern.
     *
     * @throws InvalidArgumentException
     */
    public static function collectionPattern(?string $pattern): string
    {
        $pattern = trim((string) $pattern);

        if ($pattern === '' || $pattern === '*') {
            return '*';
        }

        if (preg_match(self::NSID, $pattern) || preg_match(self::NSID_PREFIX, $pattern)) {
            return $pattern;
        }

        throw new InvalidArgumentException("\"{$pattern}\" is not a collection. Use an NSID such as app.example.post, or a prefix such as app.example.*");
    }

    public static function collection(string $pattern, string $collection): bool
    {
        if ($pattern === '*' || $pattern === $collection) {
            return true;
        }

        return str_ends_with($pattern, '.*') && str_starts_with($collection, substr($pattern, 0, -1));
    }

    /**
     * Whether a PDS URL's host matches a host pattern. * matches any run of
     * characters, so *.example covers a.example and a.b.example but not
     * example itself.
     */
    public static function host(string $pattern, ?string $pds): bool
    {
        $host = self::hostOf($pds);

        if ($host === null) {
            return false;
        }

        $regex = '/^'.str_replace('\*', '.*', preg_quote($pattern, '/')).'$/';

        return (bool) preg_match($regex, $host);
    }

    /**
     * The lowercase host of a PDS URL, with its port when it has one.
     */
    public static function hostOf(?string $pds): ?string
    {
        if ($pds === null || $pds === '') {
            return null;
        }

        $parts = parse_url(str_contains($pds, '://') ? $pds : 'https://'.$pds);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return null;
        }

        return strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
