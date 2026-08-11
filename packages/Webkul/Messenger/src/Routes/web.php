<?php

use Illuminate\Support\Facades\Route;
use Webkul\Messenger\Http\Controllers\MessengerController;

Route::prefix('messenger')->group(function () {
    Route::get('', [MessengerController::class, 'index'])->name('admin.messenger.index');
});
