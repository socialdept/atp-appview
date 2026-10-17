<?php

namespace SocialDept\AtpAppview\Xrpc;

use SocialDept\AtpSchema\Data\LexiconDocument;

/**
 * One registered XRPC method: its handler, how it is called and its lexicon.
 */
final class XrpcMethod
{
    public function __construct(
        public readonly string $nsid,
        public readonly string $handler,
        public readonly string $type,
        public readonly Auth $auth,
        public readonly ?LexiconDocument $lexicon = null,
    ) {
    }

    public function isQuery(): bool
    {
        return $this->type === 'query';
    }

    public function httpMethod(): string
    {
        return $this->isQuery() ? 'GET' : 'POST';
    }
}
