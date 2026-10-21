<?php

namespace SocialDept\AtpAppview\Standing;

use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use SocialDept\AtpAppview\Features\Moderation as ModerationFeature;
use SocialDept\AtpAppview\Features\Standing as StandingFeature;
use SocialDept\AtpAppview\Models\Identity;
use SocialDept\AtpAppview\Models\Label;
use SocialDept\AtpAppview\Standing\Contracts\Metric;
use SocialDept\AtpAppview\Standing\Contracts\Verifier;
use SocialDept\AtpAppview\Standing\Metrics\BacklinkCount;
use SocialDept\AtpAppview\Standing\Metrics\IndexCount;
use SocialDept\AtpAppview\Standing\Metrics\RepoCount;

/**
 * Facts about accounts and records, and the scores built from them. Reads
 * come from atp_standing and never wait on the network: what is missing or
 * stale is refreshed in the queue, and read as unknown until then.
 */
class Standing
{
    public const ACCOUNT = 'account';

    public const RECORD = 'record';

    public const VERIFICATION = 'verification';

    public const SCORE = 'score';

    /**
     * Whether a subject is an account (a DID) or a record (an AT-URI).
     */
    public static function kindOf(string $subject): string
    {
        return str_starts_with($subject, 'at://') ? self::RECORD : self::ACCOUNT;
    }

    /**
     * The configured metrics, by name.
     *
     * @return array<string, Metric>
     */
    public static function metrics(?string $kind = null): array
    {
        $metrics = [];

        foreach ((array) config('atp-appview.standing.metrics', []) as $name => $definition) {
            $metric = self::metric((string) $name, (array) $definition);

            if ($kind === null || $metric->subject() === $kind) {
                $metrics[(string) $name] = $metric;
            }
        }

        return $metrics;
    }

    /**
     * The configured verifiers.
     *
     * @return array<int, Verifier>
     */
    public static function verifiers(): array
    {
        return array_map(function ($definition) {
            $definition = is_string($definition) ? ['class' => $definition] : (array) $definition;
            $verifier = app((string) $definition['class'], ['options' => $definition]);

            if (! $verifier instanceof Verifier) {
                throw new InvalidArgumentException("{$definition['class']} does not implement Standing\\Contracts\\Verifier.");
            }

            return $verifier;
        }, array_values((array) config('atp-appview.standing.verification.verifiers', [])));
    }

    /**
     * Each subject's metrics, by name: the stored values, null where not
     * known yet.
     *
     * @param  array<int, string>  $subjects
     * @return array<string, array<string, int|float|null>>
     */
    public function metricsFor(array $subjects): array
    {
        $stored = $this->stored($subjects);
        $result = [];

        foreach ($subjects as $subject) {
            foreach (array_keys(self::metrics(self::kindOf($subject))) as $name) {
                $value = $stored[$subject][$name]['value'] ?? null;
                $result[$subject][$name] = $value === null ? null : (fmod($value, 1.0) === 0.0 ? (int) $value : $value);
            }
        }

        return $result;
    }

    /**
     * Each account's verification, none where not known yet.
     *
     * @param  array<int, string>  $dids
     * @return array<string, Verification>
     */
    public function verification(array $dids): array
    {
        $stored = $this->stored($dids);
        $impersonated = $this->impersonated($dids);
        $result = [];

        foreach ($dids as $did) {
            $detail = $stored[$did][self::VERIFICATION]['detail'] ?? null;
            $verification = is_array($detail) ? Verification::fromArray($detail) : Verification::none();

            // An impersonation label outweighs every verification, as on
            // Bluesky. Checked as read, so a new label applies at once.
            if (($impersonated[$did] ?? false) && $verification->status === Verification::VALID) {
                $verification = new Verification(Verification::INVALID, $verification->verifications, $verification->method, $verification->complete);
            }

            $result[$did] = $verification;
        }

        return $result;
    }

    /**
     * Whether an account is a verifier the configured verifiers trust.
     */
    public function trusted(string $did): bool
    {
        foreach (self::verifiers() as $verifier) {
            if ($verifier->trusts($did)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Bluesky's trustedVerifierStatus: valid for a trusted issuer, invalid for
     * one labeled an impersonation, none for everyone else.
     */
    public function trustedStatus(string $did): string
    {
        if (! $this->trusted($did)) {
            return Verification::NONE;
        }

        return ($this->impersonated([$did])[$did] ?? false) ? Verification::INVALID : Verification::VALID;
    }

    /**
     * Which of these accounts carry an impersonation label from the labelers
     * standing.verification.impersonation names. None without moderation, or
     * with no labelers named.
     *
     * @param  array<int, string>  $dids
     * @return array<string, bool>
     */
    public function impersonated(array $dids): array
    {
        $config = (array) config('atp-appview.standing.verification.impersonation', []);
        $labelers = array_values(array_filter((array) ($config['labelers'] ?? []), 'is_string'));

        if ($dids === [] || $labelers === [] || ! ModerationFeature::on()) {
            return [];
        }

        // On the account itself, from a named labeler, its signature checked.
        return Label::query()->live()
            ->whereIn('uri', $dids)
            ->whereIn('src', $labelers)
            ->whereIn('val', (array) ($config['values'] ?? ['impersonation']))
            ->where('verified', true)
            ->pluck('uri')
            ->mapWithKeys(fn (string $did) => [$did => true])
            ->all();
    }

    /**
     * Each subject's score, 0 where not known yet.
     *
     * @param  array<int, string>  $subjects
     * @return array<string, float>
     */
    public function scores(array $subjects): array
    {
        $stored = $this->stored($subjects);

        return array_combine($subjects, array_map(fn (string $subject) => (float) ($stored[$subject][self::SCORE]['value'] ?? 0), $subjects));
    }

    /**
     * Compute and keep everything about these subjects now: their metrics,
     * an account's verification, and their scores. Accounts are scored
     * before records, which take a share of their author's score.
     *
     * @param  array<int, string>  $subjects
     */
    public function refresh(array $subjects): void
    {
        StandingFeature::ensureOn();

        $subjects = array_values(array_unique($subjects));
        $accounts = array_values(array_filter($subjects, fn (string $subject) => self::kindOf($subject) === self::ACCOUNT));
        $records = array_values(array_diff($subjects, $accounts));

        foreach ([self::ACCOUNT => $accounts, self::RECORD => $records] as $kind => $group) {
            if ($group === []) {
                continue;
            }

            foreach (self::metrics($kind) as $name => $metric) {
                $this->keep($name, $metric->measure($group), (int) config('atp-appview.standing.fresh.metrics', 86400));
            }

            if ($kind === self::ACCOUNT && self::verifiers() !== []) {
                $this->keepVerification($this->verify($group));
            }

            $this->keep(self::SCORE, $this->score($group, $kind), (int) config('atp-appview.standing.fresh.metrics', 86400));
        }
    }

    /**
     * Ask for a refresh of what is missing or stale among these subjects,
     * in the queue, once per subject until it runs.
     *
     * @param  array<int, string>  $subjects
     */
    public function queue(array $subjects): void
    {
        $due = array_values(array_filter(array_unique($subjects), fn (string $subject) => Cache::add('atp-appview.standing.queued.'.sha1($subject), true, (int) config('atp-appview.standing.fresh.retry', 900))));

        foreach (array_chunk($due, max(1, (int) config('atp-appview.standing.batch', 25))) as $batch) {
            RefreshStanding::dispatch($batch);
        }
    }

    /**
     * Subjects with a stale or missing value, for a scheduled refresh.
     *
     * @return array<int, string>
     */
    public function stale(int $limit = 500): array
    {
        return $this->connection()->table('atp_standing')
            ->where('stale_at', '<=', Carbon::now('UTC')->format('Y-m-d H:i:s'))
            ->orderBy('stale_at')
            ->limit($limit * 4)
            ->pluck('subject')->unique()->take($limit)->values()->all();
    }

    /**
     * Every verifier's answer for these accounts, combined under the policy:
     * any (valid if any verifier says so) or all (valid only if every one
     * does).
     *
     * @param  array<int, string>  $dids
     * @return array<string, Verification>
     */
    protected function verify(array $dids): array
    {
        $answers = array_map(fn (Verifier $verifier) => $verifier->verify($dids), self::verifiers());
        $all = config('atp-appview.standing.verification.policy', 'any') === 'all';
        $combined = [];

        foreach ($dids as $did) {
            $mine = array_map(fn (array $answer) => $answer[$did] ?? Verification::none(), $answers);
            $valid = array_filter($mine, fn (Verification $verification) => $verification->verified());
            $status = match (true) {
                $all ? count($valid) === count($mine) && $mine !== [] : $valid !== [] => Verification::VALID,
                array_filter($mine, fn (Verification $verification) => $verification->status !== Verification::NONE) !== [] => Verification::INVALID,
                default => Verification::NONE,
            };

            $combined[$did] = new Verification(
                $status,
                array_merge(...array_map(fn (Verification $verification) => $verification->verifications, [...$mine, Verification::none()])),
                implode(',', array_filter(array_map(fn (Verification $verification) => $verification->method, $mine))),
                array_filter($mine, fn (Verification $verification) => ! $verification->complete) === [],
            );
        }

        return $combined;
    }

    /**
     * Scores from the stored metrics and verification, as
     * atp-appview.standing.scores weighs them.
     *
     * @param  array<int, string>  $subjects
     * @return array<string, float>
     */
    protected function score(array $subjects, string $kind): array
    {
        $weights = (array) config("atp-appview.standing.scores.{$kind}", []);
        $metrics = $this->metricsFor($subjects);
        $verified = $kind === self::ACCOUNT ? $this->verification($subjects) : [];
        $handles = $kind === self::ACCOUNT && isset($weights['handle']) ? Identity::query()->whereIn('did', $subjects)->pluck('handle', 'did')->all() : [];
        $authors = $kind === self::RECORD && isset($weights['author'])
            ? $this->scores(array_values(array_unique(array_map(fn (string $uri) => explode('/', $uri)[2] ?? '', $subjects))))
            : [];
        $scores = [];

        foreach ($subjects as $subject) {
            $score = 0.0;

            foreach ($weights as $name => $weight) {
                $score += match ((string) $name) {
                    'verified' => ($verified[$subject] ?? null)?->verified() ? (float) $weight : 0.0,
                    'handle' => array_sum(array_map(
                        fn (string $pattern, $points) => is_string($handles[$subject] ?? null) && fnmatch($pattern, $handles[$subject]) ? (float) $points : 0.0,
                        array_keys((array) $weight),
                        (array) $weight,
                    )),
                    'author' => (float) $weight * ($authors[explode('/', $subject)[2] ?? ''] ?? 0.0),
                    default => self::weigh($metrics[$subject] ?? [], (string) $name, $weight),
                };
            }

            $scores[$subject] = round($score, 4);
        }

        return $scores;
    }

    /**
     * One metric's part of a score: its value transformed, times its weight.
     * An unknown value adds nothing.
     *
     * @param  array<string, int|float|null>  $metrics
     */
    protected static function weigh(array $metrics, string $name, mixed $weight): float
    {
        $spec = is_array($weight) ? $weight : ['weight' => $weight];
        // A score entry may name its metric, so one metric can count twice,
        // such as followers alone and as a ratio.
        $value = $metrics[(string) ($spec['metric'] ?? $name)] ?? null;

        if ($value === null) {
            return 0.0;
        }

        $value = (float) $value;

        foreach (array_filter(explode('|', (string) ($spec['transform'] ?? ''))) as $transform) {
            [$operation, $argument] = array_pad(explode(':', $transform, 2), 2, null);

            $value = match ($operation) {
                'log10' => log10(max(0.0, $value) + 1),
                'sqrt' => sqrt(max(0.0, $value)),
                'cap' => min($value, (float) $argument),
                'bool' => $value > 0 ? 1.0 : 0.0,
                'ratio' => ($metrics[$argument] ?? 0) > 0 ? $value / (float) $metrics[$argument] : null,
                default => throw new InvalidArgumentException("Unknown transform {$operation}."),
            };

            if ($value === null) {
                return 0.0;
            }
        }

        return $value * (float) ($spec['weight'] ?? 1);
    }

    /**
     * Keep values of one kind. An unknown value keeps what was there, and is
     * tried again sooner.
     *
     * @param  array<string, int|float|null>  $values
     */
    protected function keep(string $kind, array $values, int $fresh): void
    {
        $now = Carbon::now('UTC');

        foreach ($values as $subject => $value) {
            $stale = $now->copy()->addSeconds($value === null ? (int) config('atp-appview.standing.fresh.retry', 900) : $fresh)->format('Y-m-d H:i:s');
            $row = $this->connection()->table('atp_standing')->where('subject', $subject)->where('kind', $kind);

            if ($value === null && $row->exists()) {
                $row->update(['stale_at' => $stale]);

                continue;
            }

            $this->connection()->table('atp_standing')->upsert([[
                'subject' => (string) $subject,
                'kind' => $kind,
                'value' => $value,
                'detail' => null,
                'computed_at' => $now->format('Y-m-d H:i:s'),
                'stale_at' => $stale,
            ]], ['subject', 'kind'], ['value', 'computed_at', 'stale_at']);
        }
    }

    /**
     * @param  array<string, Verification>  $results
     */
    protected function keepVerification(array $results): void
    {
        $now = Carbon::now('UTC');

        foreach ($results as $did => $verification) {
            $fresh = $verification->complete
                ? (int) config('atp-appview.standing.fresh.verification', 21600)
                : (int) config('atp-appview.standing.fresh.retry', 900);

            $this->connection()->table('atp_standing')->upsert([[
                'subject' => $did,
                'kind' => self::VERIFICATION,
                'value' => $verification->verified() ? 1 : 0,
                'detail' => json_encode($verification->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'computed_at' => $now->format('Y-m-d H:i:s'),
                'stale_at' => $now->copy()->addSeconds($fresh)->format('Y-m-d H:i:s'),
            ]], ['subject', 'kind'], ['value', 'detail', 'computed_at', 'stale_at']);
        }
    }

    /**
     * What is stored for these subjects, by subject and kind. Subjects with
     * something missing or stale are queued for a refresh.
     *
     * @param  array<int, string>  $subjects
     * @return array<string, array<string, array{value: float|null, detail: array<string, mixed>|null}>>
     */
    protected function stored(array $subjects): array
    {
        if ($subjects === [] || ! StandingFeature::on()) {
            return [];
        }

        $now = Carbon::now('UTC')->format('Y-m-d H:i:s');
        $stored = [];
        $due = [];

        foreach (array_chunk(array_values(array_unique($subjects)), 500) as $chunk) {
            foreach ($this->connection()->table('atp_standing')->whereIn('subject', $chunk)->get() as $row) {
                $stored[$row->subject][$row->kind] = [
                    'value' => $row->value === null ? null : (float) $row->value,
                    'detail' => $row->detail === null ? null : (array) json_decode((string) $row->detail, true),
                ];

                if ((string) $row->stale_at <= $now) {
                    $due[$row->subject] = true;
                }
            }
        }

        foreach ($subjects as $subject) {
            if (! isset($stored[$subject][self::SCORE])) {
                $due[$subject] = true;
            }
        }

        if ($due !== []) {
            $this->queue(array_keys($due));
        }

        return $stored;
    }

    /**
     * A metric from its definition.
     *
     * @param  array<string, mixed>  $definition
     */
    protected static function metric(string $name, array $definition): Metric
    {
        $subject = (string) ($definition['subject'] ?? self::ACCOUNT);
        [$collection, $path] = array_pad(explode(':', (string) ($definition['link'] ?? ''), 2), 2, '');

        $metric = match ($definition['source'] ?? null) {
            'index' => new IndexCount($subject, $collection, $path),
            'backlinks' => new BacklinkCount($subject, (string) $definition['link']),
            'repo' => new RepoCount((string) $definition['collection'], (int) ($definition['cap'] ?? 5000)),
            default => isset($definition['class']) ? app((string) $definition['class'], ['options' => $definition]) : null,
        };

        if (! $metric instanceof Metric) {
            throw new InvalidArgumentException("The {$name} metric needs a source of index, backlinks or repo, or a class implementing Standing\\Contracts\\Metric.");
        }

        return $metric;
    }

    protected function connection(): Connection
    {
        return DB::connection(config('atp-appview.index.connection'));
    }
}
