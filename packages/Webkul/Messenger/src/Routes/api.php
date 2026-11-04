<?php

use Illuminate\Support\Facades\Route;
use Webkul\Whatsapp\Http\Controllers\WebhookController;

/*
|--------------------------------------------------------------------------
| Messenger webhook
|--------------------------------------------------------------------------
| Same controller, same queue, same ingest job as every other channel - only
| the driver differs, and it is named explicitly here because Messenger traffic
| must be parsed as Messenger regardless of which WhatsApp provider happens to
| be the default.
|
| The path is the one already registered in the Meta app, so switching to this
| pipeline needs no change on Meta's side.
*/

Route::prefix('api/v1/webhooks')->group(function () {
    Route::get('messenger', [WebhookController::class, 'verify'])
        ->defaults('channel', 'messenger')
        ->name('messenger.webhook.verify');

    Route::post('messenger', [WebhookController::class, 'receive'])
        ->defaults('channel', 'messenger')
        ->name('messenger.webhook.receive');
});
