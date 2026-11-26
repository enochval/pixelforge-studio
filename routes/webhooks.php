<?php

declare(strict_types=1);

use App\Http\Controllers\PixelforgeWebhookController;
use App\Http\Middleware\VerifyPixelforgeSignature;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Provider webhooks
|--------------------------------------------------------------------------
|
| A separate file, registered in bootstrap/app.php with no middleware group
| at all, because none of the web group applies to a provider posting JSON:
|
|   There is no session and no CSRF token. The provider is not a browser and
|   has nothing to present, so CSRF verification would reject every delivery.
|
|   Nothing may re-serialise the body. The signature covers the raw bytes, and
|   middleware that decoded and re-encoded the payload would change them —
|   different key order or whitespace is enough — and every signature check
|   would then fail against a legitimate delivery.
|
| Signature verification is applied as route middleware rather than inside the
| controller, so there is no path to the controller that skips it.
|
*/

Route::post('/webhooks/pixelforge', PixelforgeWebhookController::class)
    ->middleware(VerifyPixelforgeSignature::class)
    ->name('webhooks.pixelforge');
