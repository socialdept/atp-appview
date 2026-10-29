<?php

namespace SocialDept\AtpAppview\Spaces;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use SocialDept\AtpAppview\Auth\Viewer;
use SocialDept\AtpAppview\Auth\ViewerSource;
use SocialDept\AtpAppview\Features\Spaces as SpacesFeature;
use SocialDept\AtpAppview\Spaces\Contracts\ExpiringSpaceAccess;
use SocialDept\AtpSpaces\Auth\SpaceCredential;
use SocialDept\AtpSpaces\Auth\SpaceToken;
use SocialDept\AtpSpaces\Auth\SpaceTokenType;
use SocialDept\AtpSpaces\Exceptions\CredentialException;
use SocialDept\AtpSpaces\Exceptions\SpaceTokenException;
use SocialDept\AtpSpaces\SpaceRef;
use SocialDept\AtpSpaces\Spaces;

/**
 * Who may read the spaces the app follows.
 *
 * A viewer enters a space by getting a credential through the AppView. If
 * the space's authority issues one, the viewer holds a grant until it
 * expires, so the authority's own decision is reused, whatever its policy.
 * Entering again while using the space renews the grant, so a viewer who
 * stops using the app drops out within a credential's lifetime.
 *
 * The credential is stored in atp-spaces' credential store, and the space
 * syncs with the one entered last, so a space stays synced while anyone
 * uses it. A space gone stale for want of one catches up on the next entry.
 *
 * The default SpaceAccess: a viewer is allowed while their grant lasts,
 * and blob URLs signed for them expire with it.
 */
class SpaceGrants implements ExpiringSpaceAccess
{
    public const TABLE = 'atp_space_grants';

    public function __construct(
        protected Spaces $spaces,
        protected SpaceSync $sync,
    ) {
    }

    /**
     * Enter a space with the viewer's server-side session, in backend mode.
     * A credential stored for them that is still good is reused.
     *
     * @param  string|Viewer  $viewer  A DID, or the request's viewer
     * @param  string|null  $clientAttestation  Required when the space gates on app identity
     * @return CarbonImmutable When the grant expires
     *
     * @throws LogicException when the spaces feature is off
     * @throws InvalidArgumentException when the space is not followed or was deleted
     * @throws CredentialException when the server holds no session for the viewer, or the authority refuses
     */
    public function enter(SpaceRef $space, string|Viewer $viewer, ?string $clientAttestation = null): CarbonImmutable
    {
        SpacesFeature::ensureOn();

        $viewer = is_string($viewer) ? new Viewer($viewer, ViewerSource::Session) : $viewer;
        $row = $this->followed($space);
        $client = $viewer->client()
            ?? throw new CredentialException("The server holds no session for {$viewer->did}, so it cannot enter {$space} for them. In frontend mode, enter with a delegation token.");

        return $this->entering($space, $row, $viewer->did, fn () => $this->spaces->credential($space, $client, $clientAttestation));
    }

    /**
     * Enter a space with a delegation token the viewer's browser obtained
     * from its PDS, in frontend mode. The token must name the viewer.
     *
     * @param  string  $viewer  The DID of the viewer who handed the token over
     * @param  string  $delegationToken  The compact JWT from getDelegationToken
     * @param  string|null  $clientAttestation  Required when the space gates on app identity
     * @return CarbonImmutable When the grant expires
     *
     * @throws LogicException when the spaces feature is off
     * @throws InvalidArgumentException when the space is not followed or was deleted
     * @throws CredentialException when the token is unusable or names someone else, or the authority refuses
     */
    public function enterFromDelegation(SpaceRef $space, string $viewer, string $delegationToken, ?string $clientAttestation = null): CarbonImmutable
    {
        SpacesFeature::ensureOn();

        $row = $this->followed($space);

        try {
            $issuer = SpaceToken::parse(SpaceTokenType::Delegation, $delegationToken)->issuer();
        } catch (SpaceTokenException $e) {
            throw new CredentialException('Not a usable delegation token: '.$e->getMessage(), 'InvalidDelegationToken', $e);
        }

        // Checked before the token is spent, so one viewer cannot file a
        // credential under another.
        if ($issuer !== $viewer) {
            throw new CredentialException("The delegation token is for {$issuer}, not {$viewer}.", 'InvalidDelegationToken');
        }

        return $this->entering($space, $row, $viewer, fn () => $this->spaces->credentialFromDelegation($space, $delegationToken, $clientAttestation));
    }

    /**
     * Record that the authority issued this credential, so its account may
     * read the space until it expires.
     *
     * @return CarbonImmutable When the grant expires
     */
    public function grant(SpaceCredential $credential): CarbonImmutable
    {
        $expires = CarbonImmutable::createFromTimestampUTC($credential->expiresAt());
        $now = $this->now();

        $this->connection()->table(self::TABLE)->upsert([[
            'space' => (string) $credential->space,
            'did' => $credential->userDid,
            'expires_at' => $expires->format('Y-m-d H:i:s'),
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['space', 'did'], ['expires_at', 'updated_at']);

        return $expires;
    }

    /**
     * Whether the viewer holds a grant for the space that has not expired.
     *
     * @throws LogicException when the spaces feature is off
     */
    public function allows(SpaceRef $space, ?string $viewer): bool
    {
        SpacesFeature::ensureOn();

        if ($viewer === null) {
            return false;
        }

        return $this->connection()->table(self::TABLE)
            ->where('space', (string) $space)
            ->where('did', $viewer)
            ->where('expires_at', '>', $this->now())
            ->exists();
    }

    /**
     * When the viewer's grant for the space expires, or null when they hold
     * none still good.
     *
     * @throws LogicException when the spaces feature is off
     */
    public function until(SpaceRef $space, ?string $viewer): ?CarbonImmutable
    {
        SpacesFeature::ensureOn();

        if ($viewer === null) {
            return null;
        }

        $expires = $this->connection()->table(self::TABLE)
            ->where('space', (string) $space)
            ->where('did', $viewer)
            ->where('expires_at', '>', $this->now())
            ->value('expires_at');

        return $expires === null ? null : CarbonImmutable::parse($expires, 'UTC');
    }

    /**
     * Drop a viewer's grant for a space.
     */
    public function revoke(SpaceRef $space, string $viewer): void
    {
        $this->connection()->table(self::TABLE)->where('space', (string) $space)->where('did', $viewer)->delete();
    }

    /**
     * Drop the grants that have expired.
     *
     * @return int How many were dropped
     *
     * @throws LogicException when the spaces feature is off
     */
    public function prune(): int
    {
        SpacesFeature::ensureOn();

        return $this->connection()->table(self::TABLE)->where('expires_at', '<=', $this->now())->delete();
    }

    /**
     * Mint a credential and grant the viewer the space, pointing its sync
     * at that credential. A refusal from the authority drops any grant the
     * viewer held, and a deleted space is dropped too.
     *
     * @param  Closure(): SpaceCredential  $mint
     *
     * @throws CredentialException
     */
    protected function entering(SpaceRef $space, object $row, string $viewer, Closure $mint): CarbonImmutable
    {
        try {
            $credential = $mint();
        } catch (CredentialException $e) {
            if ($e->isSpaceDeleted()) {
                $this->sync->deleted($space);
            } elseif ($e->isUserRefusal() || $e->isAppRefusal() || $e->isRevoked()) {
                $this->revoke($space, $viewer);
            }

            throw $e;
        }

        if ($credential->userDid !== $viewer || (string) $credential->space !== (string) $space) {
            throw new CredentialException("The credential issued is for {$credential->userDid} in {$credential->space}, not {$viewer} in {$space}.");
        }

        $expires = $this->connection()->transaction(function (Connection $connection) use ($credential, $row, $viewer) {
            $expires = $this->grant($credential);

            $connection->table(SpaceSync::SPACES)->where('id', $row->id)->update([
                'credential_did' => $viewer,
                'stale_at' => null,
                'updated_at' => $this->now(),
            ]);

            return $expires;
        });

        // A stale space has waited for a credential, so it catches up now.
        if ($row->stale_at !== null) {
            SyncSpace::dispatch((string) $space);
        }

        return $expires;
    }

    /**
     * The space's row, when it is followed and not deleted.
     *
     * @throws InvalidArgumentException
     */
    protected function followed(SpaceRef $space): object
    {
        $row = $this->sync->state($space);

        if ($row === null || $row->deleted_at !== null) {
            throw new InvalidArgumentException("{$space} is not followed, so it cannot be entered. Follow it with SpaceSync::follow() first.");
        }

        return $row;
    }

    protected function now(): string
    {
        return Carbon::now('UTC')->format('Y-m-d H:i:s');
    }

    protected function connection(): Connection
    {
        return DB::connection(config('atp-appview.index.connection'));
    }
}
