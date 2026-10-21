<?php

namespace SocialDept\AtpAppview\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use SocialDept\AtpAppview\Feeds\Contracts\Feed;
use SocialDept\AtpAppview\Feeds\Feeds;
use SocialDept\AtpAppview\Xrpc\ServiceIdentity;
use SocialDept\AtpClient\Facades\Atp;
use Throwable;

/**
 * Writes each configured feed's generator record to the publisher's repo,
 * signed in with its app password. A record that exists keeps its
 * createdAt.
 */
class FeedsPublishCommand extends Command
{
    protected $signature = 'appview:feeds:publish
                            {--prune : Delete generator records for feeds no longer configured}';

    protected $description = 'Write the generator records of the feeds in atp-appview.feeds.feeds';

    public function handle(ServiceIdentity $identity): int
    {
        $publisher = Feeds::publisher();
        $password = config('atp-appview.feeds.publisher.password');
        $collection = Feeds::lexicon('generator');
        $service = $identity->did();

        foreach ([
            'atp-appview.feeds.publisher.did is not a DID.' => $publisher === null,
            'atp-appview.feeds.publisher.password is not set.' => ! is_string($password) || $password === '',
            'atp-appview.feeds.lexicons.generator is off.' => $collection === null,
            'The AppView has no DID: set atp-client.service_auth.audience.' => $service === null,
        ] as $problem => $failed) {
            if ($failed) {
                $this->components->error($problem);

                return self::FAILURE;
            }
        }

        $client = Atp::login($publisher, $password);
        $existing = [];

        foreach ($client->atproto->repo->listRecords($publisher, $collection, 100)->records as $record) {
            $existing[last(explode('/', $record['uri']))] = (array) $record['value'];
        }

        foreach (Feeds::configured() as $rkey => $class) {
            $feed = app($class);

            if (! $feed instanceof Feed) {
                $this->components->error("{$class} does not implement Feeds\\Contracts\\Feed.");

                return self::FAILURE;
            }

            $describe = $feed->describe();
            $record = array_filter([
                '$type' => $collection,
                'did' => $service,
                'displayName' => (string) ($describe['displayName'] ?? $rkey),
                'description' => $describe['description'] ?? null,
                'acceptsInteractions' => $describe['acceptsInteractions'] ?? null,
                'contentMode' => $describe['contentMode'] ?? null,
                'avatar' => $this->avatar($client, $describe['avatar'] ?? null) ?? ($existing[$rkey]['avatar'] ?? null),
                'createdAt' => $existing[$rkey]['createdAt'] ?? Carbon::now('UTC')->toIso8601ZuluString('millisecond'),
            ], fn ($value) => $value !== null);

            $client->atproto->repo->putRecord($collection, (string) $rkey, $record, validate: false);
            $this->components->twoColumnDetail($rkey, Feeds::uri((string) $rkey));
        }

        if ($this->option('prune')) {
            foreach (array_diff(array_map('strval', array_keys($existing)), array_map('strval', array_keys(Feeds::configured()))) as $rkey) {
                $client->atproto->repo->deleteRecord($collection, $rkey);
                $this->components->twoColumnDetail($rkey, 'deleted');
            }
        }

        $this->components->info('Published '.count(Feeds::configured()).' feeds.');

        return self::SUCCESS;
    }

    /**
     * Uploads a local avatar, or null without one.
     *
     * @return array<string, mixed>|null
     */
    protected function avatar(mixed $client, mixed $path): ?array
    {
        if (! is_string($path) || $path === '') {
            return null;
        }

        try {
            return $client->atproto->repo->uploadBlob($path)->toArray();
        } catch (Throwable $e) {
            $this->components->warn("The avatar {$path} was not uploaded: {$e->getMessage()}");

            return null;
        }
    }
}
