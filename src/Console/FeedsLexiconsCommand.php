<?php

namespace SocialDept\AtpAppview\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use SocialDept\AtpAppview\Feeds\Feeds;

/**
 * Writes the feed lexicons under the app's own names: Bluesky's documents
 * with every NSID the app renamed rewritten, the shapes unchanged.
 */
class FeedsLexiconsCommand extends Command
{
    protected $signature = 'appview:feeds:lexicons
                            {--path= : Where to write them, resources/lexicons by default}
                            {--force : Overwrite files that exist}';

    protected $description = 'Write the feed lexicons under the NSIDs in atp-appview.feeds.lexicons';

    /**
     * Each key's template, by Bluesky's NSID.
     */
    public const TEMPLATES = [
        'generator' => 'app.bsky.feed.generator',
        'defs' => 'app.bsky.feed.defs',
        'skeleton' => 'app.bsky.feed.getFeedSkeleton',
        'describe' => 'app.bsky.feed.describeFeedGenerator',
        'interactions' => 'app.bsky.feed.sendInteractions',
        'feed' => 'app.bsky.feed.getFeed',
    ];

    public function handle(Filesystem $files): int
    {
        $renames = [];

        foreach (self::TEMPLATES as $key => $bluesky) {
            if (($nsid = Feeds::lexicon($key)) !== null && $nsid !== $bluesky) {
                $renames[$bluesky] = $nsid;
            }
        }

        if ($renames === []) {
            $this->components->info('The feed lexicons are Bluesky\'s, which exist already. Rename them in atp-appview.feeds.lexicons to write your own.');

            return self::SUCCESS;
        }

        $base = rtrim((string) ($this->option('path') ?: resource_path('lexicons')), '/');
        $written = 0;

        foreach (self::TEMPLATES as $key => $bluesky) {
            $nsid = $renames[$bluesky] ?? null;

            if ($nsid === null) {
                continue;
            }

            $path = $base.'/'.str_replace('.', '/', $nsid).'.json';

            if ($files->exists($path) && ! $this->option('force')) {
                $this->components->warn("{$path} exists. Pass --force to overwrite it.");

                continue;
            }

            $template = $files->get(__DIR__.'/../../resources/feed-lexicons/'.substr($bluesky, strlen('app.bsky.feed.')).'.json');
            $files->ensureDirectoryExists(dirname($path));
            $files->put($path, self::rename($template, $renames));
            $this->components->twoColumnDetail($nsid, $path);
            $written++;
        }

        $this->components->info("Wrote {$written} lexicons.");

        return self::SUCCESS;
    }

    /**
     * Rewrites each NSID wherever it stands whole: an id, or a ref with or
     * without a fragment.
     *
     * @param  array<string, string>  $renames  Bluesky's NSID => the app's
     */
    public static function rename(string $json, array $renames): string
    {
        return (string) preg_replace_callback(
            '/"('.implode('|', array_map(fn (string $nsid) => preg_quote($nsid, '/'), array_keys($renames))).')(#[A-Za-z0-9_]+)?"/',
            fn (array $match) => '"'.$renames[$match[1]].($match[2] ?? '').'"',
            $json,
        );
    }
}
