<?php

use Illuminate\Support\Facades\Route;
use Webkul\Doctor\Http\Controllers\Api\AvailabilityController;
use Webkul\Doctor\Http\Controllers\Api\DoctorController;
use Webkul\Doctor\Http\Controllers\Api\ProductoController;
use Webkul\Doctor\Http\Controllers\Api\SpecialtyController;

Route::prefix('api')->group(function () {
    Route::get('specialties', [SpecialtyController::class, 'index'])->name('api.specialties.index');
    Route::post('specialties', [SpecialtyController::class, 'store'])->name('api.specialties.store');
    Route::get('doctors', [DoctorController::class, 'index'])->name('api.doctors.index');
    Route::get('doctors/{id}', [DoctorController::class, 'show'])->name('api.doctors.show');
    Route::get('doctors/{doctorId}/availability/{year}/{month}', [AvailabilityController::class, 'getForMonth'])
        ->name('api.doctors.availability');
});

// Catalogue lookups the WhatsApp agent makes on every turn — same generous
// bucket as the rest of the agent surface.
Route::prefix('api')->middleware(['throttle:agent-api', 'auth:sanctum'])->group(function () {
    Route::get('productos/por-ciudad-sucursal', [ProductoController::class, 'porCiudadSucursal'])
        ->name('api.productos.por-ciudad-sucursal');
});
