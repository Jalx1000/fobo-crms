<?php

use Illuminate\Support\Facades\Route;
use Webkul\Admin\Http\Controllers\Contact\OrganizationController;
use Webkul\Admin\Http\Controllers\Contact\Persons\ActivityController;
use Webkul\Admin\Http\Controllers\Contact\Persons\InsuranceController;
use Webkul\Admin\Http\Controllers\Contact\Persons\PersonController;
use Webkul\Admin\Http\Controllers\Contact\Persons\TagController;

Route::prefix('contacts')->group(function () {
    /**
     * Persons routes.
     */
    Route::controller(PersonController::class)->prefix('persons')->group(function () {
        Route::get('', 'index')->name('admin.contacts.persons.index');

        Route::get('create', 'create')->name('admin.contacts.persons.create');

        Route::post('create', 'store')->name('admin.contacts.persons.store');

        Route::get('view/{id}', 'show')->name('admin.contacts.persons.view');

        Route::post('{id}/verify-insurance', [InsuranceController::class, 'verify'])->name('admin.contacts.persons.verify_insurance');
        Route::post('{id}/update-insurance', [InsuranceController::class, 'updateAndVerify'])->name('admin.contacts.persons.update_insurance');
        Route::post('{id}/clear-insurance-cache', [InsuranceController::class, 'clearCache'])->name('admin.contacts.persons.clear_insurance_cache');

        Route::get('edit/{id}', 'edit')->name('admin.contacts.persons.edit');

        Route::put('edit/{id}', 'update')->name('admin.contacts.persons.update');

        Route::get('search', 'search')->name('admin.contacts.persons.search');

        Route::middleware('throttle:30,1')->get('search-smd', 'searchSmd')->name('admin.contacts.persons.search_smd');

        Route::post('verify-insurance-quick', [InsuranceController::class, 'verifyQuick'])->name('admin.contacts.persons.verify_insurance_quick');

        Route::middleware('throttle:20,1')->post('verify-insurance-ci', [InsuranceController::class, 'verifyCi'])->name('admin.contacts.persons.verify_insurance_ci');

        Route::get('insurance-options', [InsuranceController::class, 'insuranceOptions'])->name('admin.contacts.persons.insurance_options');

        Route::post('{id}/sync-smd', 'syncSmd')->name('admin.contacts.persons.sync_smd');

        Route::get('{id}/smd-data', 'smdData')->name('admin.contacts.persons.smd_data');
        Route::post('{id}/link-smd', 'linkSmd')->name('admin.contacts.persons.link_smd');
        Route::get('{id}/insurance-status', [InsuranceController::class, 'insuranceStatus'])->name('admin.contacts.persons.insurance_status');

        Route::middleware(['throttle:100,60'])->delete('{id}', 'destroy')->name('admin.contacts.persons.delete');

        Route::post('mass-destroy', 'massDestroy')->name('admin.contacts.persons.mass_delete');

        /**
         * Tag routes.
         */
        Route::controller(TagController::class)->prefix('{id}/tags')->group(function () {
            Route::post('', 'attach')->name('admin.contacts.persons.tags.attach');

            Route::delete('', 'detach')->name('admin.contacts.persons.tags.detach');
        });

        /**
         * Activity routes.
         */
        Route::controller(ActivityController::class)->prefix('{id}/activities')->group(function () {
            Route::get('', 'index')->name('admin.contacts.persons.activities.index');
        });
    });

    /**
     * Organization routes.
     */
    Route::controller(OrganizationController::class)->prefix('organizations')->group(function () {
        Route::get('', 'index')->name('admin.contacts.organizations.index');

        Route::get('create', 'create')->name('admin.contacts.organizations.create');

        Route::post('create', 'store')->name('admin.contacts.organizations.store');

        Route::get('edit/{id?}', 'edit')->name('admin.contacts.organizations.edit');

        Route::put('edit/{id}', 'update')->name('admin.contacts.organizations.update');

        Route::delete('{id}', 'destroy')->name('admin.contacts.organizations.delete');

        Route::put('mass-destroy', 'massDestroy')->name('admin.contacts.organizations.mass_delete');
    });
});
