<?php

namespace SocialDept\AtpAppview\Ingest;

/**
 * A signature of the files the config comes from: .env, the config cache, and
 * every file in config/. appview:consume compares it with the one it started
 * with, and restarts when they differ, so a new handler, collection or sort
 * field takes effect without a manual appview:restart.
 *
 * Files rather than values: Laravel copies .env into the process environment,
 * and an environment variable that is already set wins over .env, so a fresh
 * process started from the consumer would inherit the old values.
 */
final class DeployedConfig
{
    public static function signature(): string
    {
        clearstatcache();

        $files = array_merge(
            [base_path('.env'), app()->getCachedConfigPath()],
            glob(config_path('*.php')) ?: [],
        );

        sort($files);

        return sha1(implode("\n", array_map(
            fn (string $file) => is_file($file) ? $file.' '.filemtime($file).' '.filesize($file) : $file.' missing',
            $files,
        )));
    }
}
