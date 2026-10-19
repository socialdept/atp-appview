<?php

namespace SocialDept\AtpAppview\Pds\Drivers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use SocialDept\AtpAppview\Pds\Contracts\PdsDriver;

/**
 * A stock PDS, through its admin API: com.atproto.admin.updateSubjectStatus
 * and getSubjectStatus, with basic auth as user admin and the PDS's admin
 * password.
 */
class AdminDriver implements PdsDriver
{
    public function __construct(
        protected string $url,
        protected string $password,
    ) {
    }

    public function takedown(string $did, string $ref): void
    {
        $this->update($did, ['applied' => true, 'ref' => $ref]);
    }

    public function restore(string $did): void
    {
        $this->update($did, ['applied' => false]);
    }

    public function takenDown(string $did): bool
    {
        return (bool) $this->request()->get('/xrpc/com.atproto.admin.getSubjectStatus', ['did' => $did])->throw()->json('takedown.applied', false);
    }

    /**
     * @param  array<string, mixed>  $takedown
     */
    protected function update(string $did, array $takedown): void
    {
        $this->request()->post('/xrpc/com.atproto.admin.updateSubjectStatus', [
            'subject' => ['$type' => 'com.atproto.admin.defs#repoRef', 'did' => $did],
            'takedown' => $takedown,
        ])->throw();
    }

    protected function request(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->url, '/'))->withBasicAuth('admin', $this->password)->acceptJson()->timeout(30);
    }
}
