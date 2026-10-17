<?php

namespace SocialDept\AtpAppview\Xrpc\Attributes;

use Attribute;
use SocialDept\AtpAppview\Xrpc\Auth;

/**
 * Marks an invokable class as the handler for one XRPC method.
 *
 *     #[Xrpc('com.example.getTimeline', auth: Auth::Required)]
 *     class GetTimeline
 *     {
 *         public function __invoke(XrpcRequest $request): array { ... }
 *     }
 *
 * Whether the method is a query (GET) or a procedure (POST) comes from its
 * lexicon. A handler without a lexicon says so with `type`.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Xrpc
{
    /**
     * @param  string|null  $type  'query' or 'procedure', only needed when no lexicon is loaded
     */
    public function __construct(
        public readonly string $nsid,
        public readonly Auth $auth = Auth::Optional,
        public readonly ?string $type = null,
    ) {
    }
}
