<?php

use Illuminate\Support\Facades\Route;
use Webkul\Admin\Http\Controllers\Activity\ActivityController;
use Webkul\Admin\Http\Controllers\Activity\SmdSyncController;

Route::controller(SmdSyncController::class)->prefix('activities/smd')->group(function () {
    Route::post('sync', 'sync')->name('admin.activities.smd.sync');
    Route::get('sync/status', 'status')->name('admin.activities.smd.sync.status');
    Route::post('pause', 'pause')->name('admin.activities.smd.pause');
});

Route::controller(ActivityController::class)->prefix('activities')->group(function () {
    Route::get('', 'index')->name('admin.activities.index');

    Route::get('get', 'get')->name('admin.activities.get');

    Route::post('create', 'store')->name('admin.activities.store');

    Route::post('appointments/create', 'storeAppointment')->name('admin.activities.appointments.store');

    Route::post('appointments/{id}/status', 'updateAppointmentStatus')->name('admin.activities.appointments.status');

    Route::get('persons/{id}/appointments', 'personAppointments')->name('admin.activities.persons.appointments');

    Route::get('edit/{id}', 'edit')->name('admin.activities.edit');

    Route::put('edit/{id}', 'update')->name('admin.activities.update');

    Route::get('download/{id}', 'download')->name('admin.activities.file_download');

    Route::delete('{id}', 'destroy')->name('admin.activities.delete');

    Route::post('mass-update', 'massUpdate')->name('admin.activities.mass_update');

    Route::post('mass-destroy', 'massDestroy')->name('admin.activities.mass_delete');
});
