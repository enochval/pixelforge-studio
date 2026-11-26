<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\RecordWebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Receives a verified delivery from the provider.
 *
 * Does as little as possible: record the event and answer. Any 2xx within ten
 * seconds counts as delivered, and anything slower is redelivered up to six
 * times — so the decision about what the event *means* belongs to a queued
 * job, not to this request.
 *
 * Answers 204 in every case the delivery was genuine, including a duplicate.
 * A duplicate is not an error to report: the provider is entitled to deliver
 * the same event more than once and says so, and answering anything other than
 * 2xx would make it keep trying.
 */
final class PixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request, RecordWebhookEvent $record): Response
    {
        /** @var array<string, mixed> $payload */
        $payload = $request->json()->all();

        $record->execute($payload);

        return response()->noContent();
    }
}
