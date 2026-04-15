<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Events\Webhooks\Smtp2goWebhookReceived;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

class Smtp2goWebhooksController extends Controller
{
    public function handle(Request $request): Response
    {
        // smtp2go can POST JSON or form-encoded depending on webhook config.
        // We support both: try JSON body first, fall back to form fields.
        $contentType = $request->header('Content-Type', '');

        if (str_contains($contentType, 'application/json')) {
            $payload = $request->json()->all();
        } else {
            $payload = $request->all();
        }

        $event   = $payload['event']    ?? null;
        $emailId = $payload['email_id'] ?? null;

        if (! $event || ! $emailId) {
            Log::warning('smtp2go webhook received with missing event or email_id', ['payload' => $payload]);
            return response('OK');
        }

        Log::info('smtp2go webhook received', ['event' => $event, 'email_id' => $emailId]);

        event(new Smtp2goWebhookReceived($payload));

        return response('OK');
    }
}
