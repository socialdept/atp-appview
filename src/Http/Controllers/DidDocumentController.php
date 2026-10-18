<?php

namespace SocialDept\AtpAppview\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use SocialDept\AtpAppview\Xrpc\ServiceIdentity;

/**
 * The AppView's did:web document, which a PDS reads to find where to proxy a
 * request carrying atproto-proxy.
 */
class DidDocumentController extends Controller
{
    public function __invoke(ServiceIdentity $identity): JsonResponse
    {
        return response()->json($identity->document())
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Cache-Control', 'public, max-age=300');
    }
}
