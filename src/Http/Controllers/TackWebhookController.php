<?php

namespace SocialDept\AtpAppview\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use SocialDept\AtpAppview\Ingest\Exceptions\BatchFailed;
use SocialDept\AtpAppview\Ingest\TapWebhook;

/**
 * Receives Tack's webhook deliveries: labels and labeler declarations, one
 * event or {"events": [...]} per request, the way Tap delivers records. Tack
 * signs them with its admin password over basic auth, as user "admin", which
 * is atp-appview.labels.tack.password.
 */
class TackWebhookController
{
    public function __invoke(Request $request, TapWebhook $webhook): JsonResponse
    {
        $password = (string) config('atp-appview.labels.tack.password');

        if ($password === '') {
            return new JsonResponse(['error' => 'Set atp-appview.labels.tack.password to the password Tack signs its webhooks with.'], 403);
        }

        if ($request->getUser() !== 'admin' || ! hash_equals($password, (string) $request->getPassword())) {
            return new JsonResponse(['error' => 'Unauthorized'], 401, ['WWW-Authenticate' => 'Basic realm="tack"']);
        }

        $payload = $request->json()->all();

        if (! is_array($payload) || $payload === []) {
            return new JsonResponse(['error' => 'Expected a Tack event, or {"events": [...]}.'], 400);
        }

        try {
            $handled = $webhook->handle($payload);
        } catch (BatchFailed $e) {
            Log::error('[atp-appview] Tack webhook batch failed, Tack will retry it', ['error' => $e->getMessage()]);

            return new JsonResponse(['error' => 'The batch could not be handled. Tack will retry it.'], 503);
        }

        return new JsonResponse(['handled' => $handled]);
    }
}
