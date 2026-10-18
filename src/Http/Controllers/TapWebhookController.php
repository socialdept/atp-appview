<?php

namespace SocialDept\AtpAppview\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use SocialDept\AtpAppview\Ingest\Exceptions\BatchFailed;
use SocialDept\AtpAppview\Ingest\TapWebhook;

/**
 * Receives Tap's webhook deliveries. Tap signs them with its admin password
 * over basic auth, as user "admin", so the same ingest.password that lets the
 * app reach Tap's admin API proves a request came from Tap.
 */
class TapWebhookController
{
    public function __invoke(Request $request, TapWebhook $webhook): JsonResponse
    {
        $password = (string) config('atp-appview.ingest.password');

        if ($password === '') {
            return new JsonResponse(['error' => 'Set atp-appview.ingest.password to the password Tap signs its webhooks with.'], 403);
        }

        if ($request->getUser() !== 'admin' || ! hash_equals($password, (string) $request->getPassword())) {
            return new JsonResponse(['error' => 'Unauthorized'], 401, ['WWW-Authenticate' => 'Basic realm="tap"']);
        }

        $payload = $request->json()->all();

        if (! is_array($payload) || $payload === []) {
            return new JsonResponse(['error' => 'Expected a Tap event, or {"events": [...]}.'], 400);
        }

        try {
            $handled = $webhook->handle($payload);
        } catch (BatchFailed $e) {
            // Tap retries anything but a 2xx, so the batch comes back.
            Log::error('[atp-appview] Webhook batch failed, Tap will retry it', ['error' => $e->getMessage()]);

            return new JsonResponse(['error' => 'The batch could not be handled. Tap will retry it.'], 503);
        }

        return new JsonResponse(['handled' => $handled]);
    }
}
