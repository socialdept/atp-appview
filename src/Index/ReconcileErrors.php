<?php

namespace SocialDept\AtpAppview\Index;

/**
 * Short names for reconcile errors, so a status page can group them: most
 * failures are a handful of network problems on a handful of PDS hosts.
 */
final class ReconcileErrors
{
    /**
     * cURL error numbers worth naming. Matched as whole numbers, since error
     * 6 is not error 60.
     */
    protected const CURL = [
        6 => 'Host not found',
        7 => 'Connection refused',
        28 => 'Timed out',
        35 => 'TLS failure',
        60 => 'TLS failure',
    ];

    public static function kind(string $error): string
    {
        if (preg_match('/cURL error (\d+)\b/', $error, $m) === 1) {
            return self::CURL[(int) $m[1]] ?? "Network error {$m[1]}";
        }

        return match (true) {
            str_contains($error, 'holds more than') => 'Too many records',
            preg_match('/^([A-Z][A-Za-z]+):/', $error, $m) === 1 => $m[1],
            default => 'Other',
        };
    }
}
