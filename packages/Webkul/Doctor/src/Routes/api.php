<?php

use Illuminate\Support\Facades\Route;
use Webkul\Doctor\Http\Controllers\Api\AvailabilityController;
use Webkul\Doctor\Http\Controllers\Api\DoctorController;
use Webkul\Doctor\Http\Controllers\Api\SpecialtyController;

Route::prefix('api')->group(function () {
    Route::get('specialties', [SpecialtyController::class, 'index'])->name('api.specialties.index');
    Route::post('specialties', [SpecialtyController::class, 'store'])->name('api.specialties.store');
    Route::get('specialties/{identifier}/doctors', [SpecialtyController::class, 'doctors'])->name('api.specialties.doctors');
    Route::get('doctors', [DoctorController::class, 'index'])->name('api.doctors.index');
    Route::get('doctors/{id}', [DoctorController::class, 'show'])->name('api.doctors.show');
    Route::get('doctors/{id}/slots', [AvailabilityController::class, 'slots'])
        ->name('api.doctors.slots')
        ->where('id', '[1-9][0-9]*')
        ->middleware('throttle:doctor-availability');
    Route::get('doctors/{doctorId}/availability/{year}/{month}', [AvailabilityController::class, 'getForMonth'])
        ->name('api.doctors.availability');
});
