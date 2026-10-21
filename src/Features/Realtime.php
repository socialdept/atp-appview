<?php

namespace SocialDept\AtpAppview\Features;

use LogicException;
use SocialDept\AtpAppview\Features;
use SocialDept\AtpAppview\Index\Events\RecordIndexed;
use SocialDept\AtpAppview\Index\Events\RecordRemoved;
use SocialDept\AtpAppview\Notifications\Events\NotificationCreated;
use SocialDept\AtpAppview\Realtime\Realtime as RealtimeService;
use SocialDept\AtpAppview\Xrpc\Auth;
use SocialDept\AtpAppview\Xrpc\Handlers\AuthorizeChannelHandler;

/**
 * Pings over Laravel broadcasting when records, the records pointing at
 * them, and notifications change, for the browser to fetch what changed.
 * Private channels are joined through an XRPC procedure, so a Laravel
 * session and a service auth token both work.
 *
 * Off, nothing is broadcast and no procedure is served.
 */
class Realtime implements Feature
{
    public function requires(): array
    {
        return [];
    }

    public function register(AppView $appview): void
    {
        $appview
            ->config('realtime')
            ->listen(RecordIndexed::class, fn (RecordIndexed $event) => app(RealtimeService::class)->indexed($event))
            ->listen(RecordRemoved::class, fn (RecordRemoved $event) => app(RealtimeService::class)->removed($event))
            ->listen(NotificationCreated::class, fn (NotificationCreated $event) => app(RealtimeService::class)->notified($event));

        if (is_string($nsid = config('atp-appview.realtime.xrpc.authorize')) && $nsid !== '') {
            $appview->xrpc($nsid, AuthorizeChannelHandler::class, 'procedure', Auth::Required);
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
            throw new LogicException('Realtime needs the realtime feature: add Features::realtime() to atp-appview.features.');
        }
    }
}
