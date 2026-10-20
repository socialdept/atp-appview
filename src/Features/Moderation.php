<?php

namespace SocialDept\AtpAppview\Features;

use Illuminate\Routing\Router;
use LogicException;
use SocialDept\AtpAppview\Console\LabelsConsumeCommand;
use SocialDept\AtpAppview\Console\LabelsSelfCommand;
use SocialDept\AtpAppview\Console\LabelsSyncCommand;
use SocialDept\AtpAppview\Console\ModerationPruneCommand;
use SocialDept\AtpAppview\Features;
use SocialDept\AtpAppview\Http\Controllers\TackWebhookController;
use SocialDept\AtpAppview\Labels\LabelIndexer;
use SocialDept\AtpAppview\Labels\SelfLabels;
use SocialDept\AtpAppview\Labels\Visibility;

/**
 * Moderation: labels from labelers through Tack, policies, self-labels and
 * account actions (bans, review, takedowns). Adds the label and account
 * action tables, the Tack webhook and the labels and moderation commands.
 *
 * Off, no labels are kept or applied, nobody counts as banned or under
 * review, the Moderation and Labels view parts are neutral, and writing a
 * label policy or an account action refuses, naming this feature.
 */
class Moderation implements Feature
{
    public function requires(): array
    {
        return [];
    }

    public function register(AppView $appview): void
    {
        $appview
            // Options given to Features::moderation() go over atp-appview.moderation.
            ->config('moderation')
            ->migrations(__DIR__.'/../../database/migrations/moderation')
            ->handler(LabelIndexer::class)
            ->afterWrite(function ($connection, array $rows, string $now) {
                $selfLabeled = config('atp-appview.labels.self_labels', true) ? app(SelfLabels::class)->sync($connection, $rows, $now) : [];

                // New records of accounts whose labels hide them, hidden too.
                app(Visibility::class)->arrivals(array_column($rows, 'did'), $selfLabeled);
            })
            ->commands([LabelsConsumeCommand::class, LabelsSelfCommand::class, LabelsSyncCommand::class, ModerationPruneCommand::class])
            ->routes(function (Router $router) {
                if (config('atp-appview.labels.webhook.enabled')) {
                    $router->post((string) config('atp-appview.labels.webhook.path', 'atp-appview/tack'), TackWebhookController::class)
                        ->middleware((array) config('atp-appview.labels.webhook.middleware', []))
                        ->name('atp-appview.tack-webhook');
                }
            });
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
            throw new LogicException('This needs the moderation feature: add Features::moderation() to atp-appview.features.');
        }
    }
}
