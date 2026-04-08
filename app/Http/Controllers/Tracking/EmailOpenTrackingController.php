<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tracking;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Sendportal\Base\Models\Message;

class EmailOpenTrackingController extends Controller
{
    public function track(string $messageHash): Response
    {
        $message = Message::where('hash', $messageHash)->first();

        if ($message) {
            if (! $message->opened_at) {
                $message->opened_at = now();
                $message->ip = $message->ip ?: request()->ip();
            }

            $message->open_count = ((int) $message->open_count) + 1;
            $message->save();
        }

        return response($this->transparentGif(), 200, [
            'Content-Type' => 'image/gif',
            'Cache-Control' => 'no-cache, no-store, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    private function transparentGif(): string
    {
        return base64_decode('R0lGODlhAQABAPAAAAAAAAAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==') ?: '';
    }
}
