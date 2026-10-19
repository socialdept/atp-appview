<?php

namespace SocialDept\AtpAppview\Labels;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Tack's admin API: the labelers it follows and the accounts it delivers
 * labels about. At atp-appview.labels.tack.url, with basic auth as user
 * admin and atp-appview.labels.tack.password.
 */
class Tack
{
    /**
     * Register accounts, so Tack fetches the labels they already have and
     * delivers new ones. uris are their record URIs, for labelers that only
     * answer for exact URIs.
     *
     * @param  array<int, string>  $dids
     * @param  array<int, string>  $uris
     * @return array{added: int, urisQueued: int}
     */
    public function addSubjects(array $dids, array $uris = []): array
    {
        return $this->post('/subjects/add', ['dids' => array_values($dids), 'uris' => array_values($uris)]);
    }

    /**
     * More record URIs of registered accounts to ask labelers about.
     *
     * @param  array<int, string>  $uris
     * @return array{urisQueued: int}
     */
    public function addUris(array $uris): array
    {
        return $this->post('/subjects/uris', ['uris' => array_values($uris)]);
    }

    /**
     * Stop delivering labels about accounts.
     *
     * @param  array<int, string>  $dids
     */
    public function removeSubjects(array $dids): void
    {
        $this->post('/subjects/remove', ['dids' => array_values($dids)]);
    }

    /**
     * The labelers Tack follows, with their state.
     *
     * @return array<int, array<string, mixed>>
     */
    public function labelers(): array
    {
        return (array) ($this->request()->get('/labelers')->throw()->json('labelers') ?? []);
    }

    public function addLabeler(string $did): void
    {
        $this->post('/labelers/add', ['did' => $did]);
    }

    public function removeLabeler(string $did): void
    {
        $this->post('/labelers/remove', ['did' => $did]);
    }

    /**
     * @return array<string, int>
     */
    public function stats(): array
    {
        return (array) $this->request()->get('/stats')->throw()->json();
    }

    public function healthy(): bool
    {
        try {
            return $this->request()->timeout(5)->get('/health')->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public static function configured(): bool
    {
        return filled(config('atp-appview.labels.tack.url'));
    }

    /**
     * Tack's websocket, at /channel on the same address as its API.
     */
    public static function channelUrl(): ?string
    {
        $url = rtrim((string) config('atp-appview.labels.tack.url'), '/');

        if ($url === '') {
            return null;
        }

        return preg_replace(['#^https://#', '#^http://#'], ['wss://', 'ws://'], $url).'/channel';
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    protected function post(string $path, array $body): array
    {
        return (array) $this->request()->post($path, $body)->throw()->json();
    }

    protected function request(): PendingRequest
    {
        if (! self::configured()) {
            throw new RuntimeException('Set atp-appview.labels.tack.url to reach Tack.');
        }

        return Http::baseUrl(rtrim((string) config('atp-appview.labels.tack.url'), '/'))
            ->withBasicAuth('admin', (string) config('atp-appview.labels.tack.password'))
            ->acceptJson()
            ->timeout(30);
    }
}
