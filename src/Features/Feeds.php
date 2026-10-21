<?php

namespace SocialDept\AtpAppview\Features;

use Illuminate\Console\Scheduling\Schedule;
use LogicException;
use SocialDept\AtpAppview\Console\FeedsInteractionsPruneCommand;
use SocialDept\AtpAppview\Console\FeedsLexiconsCommand;
use SocialDept\AtpAppview\Console\FeedsPublishCommand;
use SocialDept\AtpAppview\Features;
use SocialDept\AtpAppview\Feeds\Feeds as FeedsService;
use SocialDept\AtpAppview\Xrpc\Auth;
use SocialDept\AtpAppview\Xrpc\Handlers\DescribeFeedGeneratorHandler;
use SocialDept\AtpAppview\Xrpc\Handlers\GetFeedHandler;
use SocialDept\AtpAppview\Xrpc\Handlers\GetFeedSkeletonHandler;
use SocialDept\AtpAppview\Xrpc\Handlers\SendFeedInteractionsHandler;

/**
 * Custom feeds in the shapes of Bluesky's feed generators, under the
 * lexicons atp-appview.feeds names: a skeleton, a description, interactions
 * and, for the app's own clients, a hydrated feed. Adds the feed service to
 * the DID document, atp_feed_interactions, and the appview:feeds:* commands.
 *
 * Off, nothing is served or kept, and the Feeds API refuses, naming this
 * feature.
 */
class Feeds implements Feature
{
    public function requires(): array
    {
        return [];
    }

    public function register(AppView $appview): void
    {
        $appview
            ->config('feeds')
            ->migrations(__DIR__.'/../../database/migrations/feeds')
            ->commands([FeedsLexiconsCommand::class, FeedsPublishCommand::class, FeedsInteractionsPruneCommand::class])
            ->schedule(fn (Schedule $schedule) => $schedule->command('appview:feeds:prune')->daily());

        $service = (array) config('atp-appview.feeds.service', []);

        if (is_string($service['id'] ?? null) && is_string($service['type'] ?? null)) {
            $appview->service($service['id'], $service['type']);
        }

        $methods = [
            'skeleton' => [GetFeedSkeletonHandler::class, 'query', Auth::Optional],
            'describe' => [DescribeFeedGeneratorHandler::class, 'query', Auth::None],
            'interactions' => [SendFeedInteractionsHandler::class, 'procedure', Auth::Required],
            'feed' => [GetFeedHandler::class, 'query', Auth::Optional],
        ];

        foreach ($methods as $key => [$handler, $type, $auth]) {
            if (($nsid = FeedsService::lexicon($key)) !== null) {
                $appview->xrpc($nsid, $handler, $type, $auth);
            }
        }
    }

    public static function on(): bool
    {
        return Features::enabled(self::class);
    }

    /**
     * @throws LogicException when the feature is off
     */
    public static function ensureOn(): void
    {
        if (! self::on()) {
            throw new LogicException('Feeds need the feeds feature: add Features::feeds() to atp-appview.features.');
        }
    }
}
