<?php

namespace SocialDept\AtpAppview\Features;

use Illuminate\Console\Scheduling\Schedule;
use LogicException;
use SocialDept\AtpAppview\Console\StandingRefreshCommand;
use SocialDept\AtpAppview\Features;
use SocialDept\AtpAppview\Standing\Standing as StandingService;

/**
 * Facts about accounts and records the index does not hold, and the scores
 * built from them: metrics from the index, Constellation or a repo,
 * verification by pluggable verifiers, and weighted scores. Adds
 * atp_standing, appview:standing:refresh, and stats and verification on
 * profiles.
 *
 * Off, nothing is kept or fetched, profiles are unchanged, and the Standing
 * API refuses, naming this feature.
 */
class Standing implements Feature
{
    public function requires(): array
    {
        return [];
    }

    public function register(AppView $appview): void
    {
        $appview
            ->config('standing')
            ->migrations(__DIR__.'/../../database/migrations/standing')
            ->commands([StandingRefreshCommand::class])
            ->schedule(fn (Schedule $schedule) => $schedule->command('appview:standing:refresh --stale')->everyFifteenMinutes());

        // Counts from the index read the reference fields they name.
        foreach ((array) config('atp-appview.standing.metrics', []) as $definition) {
            if (($definition['source'] ?? null) === 'index' && str_contains((string) ($definition['link'] ?? ''), ':')) {
                [$collection, $path] = explode(':', (string) $definition['link'], 2);
                $appview->field($collection, $path, 'uri');
            }
        }

        $appview->profiles(fn (array $profiles, bool $basic) => self::decorate($profiles, $basic));
    }

    /**
     * Profiles with their verification, in Bluesky's verificationState
     * shape, and, when full, their account metrics as stats.
     *
     * @param  array<string, array<string, mixed>>  $profiles
     * @return array<string, array<string, mixed>>
     */
    public static function decorate(array $profiles, bool $basic): array
    {
        $dids = array_keys($profiles);

        if ($dids === []) {
            return $profiles;
        }

        $standing = app(StandingService::class);
        $verifying = StandingService::verifiers() !== [];
        $verifications = $verifying ? $standing->verification($dids) : [];
        $stats = ! $basic && StandingService::metrics(StandingService::ACCOUNT) !== [] ? $standing->metricsFor($dids) : [];

        foreach ($profiles as $did => $profile) {
            // Like Bluesky, an account without a valid handle shows no
            // verification at all.
            if ($verifying && filled($profile['handle'] ?? null)) {
                $verification = $verifications[$did];
                $profiles[$did]['verification'] = [
                    'verifiedStatus' => $verification->status,
                    'trustedVerifierStatus' => $standing->trustedStatus($did),
                    'verifications' => array_map(fn (array $item) => [
                        'issuer' => $item['issuer'],
                        'uri' => $item['uri'],
                        'isValid' => $item['valid'],
                        'createdAt' => $item['createdAt'],
                    ], $verification->verifications),
                ];
            }

            if (isset($stats[$did])) {
                $profiles[$did]['stats'] = $stats[$did];
            }
        }

        return $profiles;
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
            throw new LogicException('Standing needs the standing feature: add Features::standing() to atp-appview.features.');
        }
    }
}
