<?php

namespace SocialDept\AtpAppview\Features;

use Illuminate\Console\Scheduling\Schedule;
use LogicException;
use SocialDept\AtpAppview\Claims\Claims as ClaimsService;
use SocialDept\AtpAppview\Console\ClaimsRefreshCommand;
use SocialDept\AtpAppview\Features;

/**
 * Verdicts on the claims records make, such as owning the website they
 * name, checked by a pluggable checker per collection and optionally
 * inherited from a parent record in the same repo. Adds atp_record_claims,
 * appview:claims:refresh and Record::verified().
 *
 * Off, nothing is kept or fetched, and verified() refuses, naming this
 * feature.
 */
class Claims implements Feature
{
    public function requires(): array
    {
        return [];
    }

    public function register(AppView $appview): void
    {
        $appview
            ->config('claims')
            ->migrations(__DIR__.'/../../database/migrations/claims')
            ->commands([ClaimsRefreshCommand::class])
            ->schedule(fn (Schedule $schedule) => $schedule->command('appview:claims:refresh --stale')->everyFifteenMinutes())
            ->afterWrite(fn ($connection, array $rows, string $now) => app(ClaimsService::class)->written($connection, $rows, $now))
            ->afterPrune(fn ($connection, array $uris) => $connection->table(ClaimsService::TABLE)->whereIn('uri', $uris)->delete());
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
            throw new LogicException('Claims need the claims feature: add Features::claims() to atp-appview.features.');
        }
    }
}
