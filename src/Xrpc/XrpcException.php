<?php

namespace SocialDept\AtpAppview\Xrpc;

use RuntimeException;

/**
 * An error returned to the caller in the XRPC shape:
 * {"error": "InvalidRequest", "message": "..."}.
 */
class XrpcException extends RuntimeException
{
    public function __construct(
        public readonly string $error,
        string $message,
        public readonly int $status = 400,
    ) {
        parent::__construct($message);
    }

    public static function invalidRequest(string $message): self
    {
        return new self('InvalidRequest', $message, 400);
    }

    public static function notFound(string $error, string $message): self
    {
        return new self($error, $message, 404);
    }

    public static function methodNotImplemented(string $nsid): self
    {
        return new self('MethodNotImplemented', "Method not implemented: {$nsid}.", 501);
    }
}
