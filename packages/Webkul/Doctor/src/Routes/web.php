<?php

use Illuminate\Support\Facades\Route;
use Webkul\Doctor\Http\Controllers\DoctorController;
use Webkul\Doctor\Http\Controllers\ScheduleController;
use Webkul\Doctor\Http\Controllers\SpecialtyController;

Route::prefix('doctor')->group(function () {
    Route::get('', [DoctorController::class, 'index'])->name('admin.doctor.index');
    Route::get('create', [DoctorController::class, 'create'])->name('admin.doctor.create');
    Route::post('', [DoctorController::class, 'store'])->name('admin.doctor.store');
    Route::get('edit/{id}', [DoctorController::class, 'edit'])->name('admin.doctor.edit');
    Route::put('edit/{id}', [DoctorController::class, 'update'])->name('admin.doctor.update');
    Route::delete('{id}', [DoctorController::class, 'destroy'])->name('admin.doctor.delete');
    Route::get('search', [DoctorController::class, 'search'])->name('admin.doctor.search');
});

Route::prefix('specialties')->group(function () {
    Route::get('', [SpecialtyController::class, 'index'])->name('admin.specialties.index');
    Route::get('sync', [SpecialtyController::class, 'sync'])->name('admin.specialties.sync');
    Route::get('create', [SpecialtyController::class, 'create'])->name('admin.specialties.create');
    Route::post('create', [SpecialtyController::class, 'store'])->name('admin.specialties.store');
    Route::get('edit/{id}', [SpecialtyController::class, 'edit'])->name('admin.specialties.edit');
    Route::put('edit/{id}', [SpecialtyController::class, 'update'])->name('admin.specialties.update');
    Route::delete('{id}', [SpecialtyController::class, 'destroy'])->name('admin.specialties.delete');
});

Route::prefix('schedules')->group(function () {
    Route::get('', [ScheduleController::class, 'index'])->name('admin.schedules.index');
    Route::get('week', [ScheduleController::class, 'week'])->name('admin.schedules.week');
    Route::get('create', [ScheduleController::class, 'create'])->name('admin.schedules.create');
    Route::post('create', [ScheduleController::class, 'store'])->name('admin.schedules.store');
    Route::get('edit/{id}', [ScheduleController::class, 'edit'])->name('admin.schedules.edit');
    Route::put('edit/{id}', [ScheduleController::class, 'update'])->name('admin.schedules.update');
    Route::delete('{id}', [ScheduleController::class, 'destroy'])->name('admin.schedules.delete');
    // {doctorId}, no {doctor}: con el nombre del modelo Concord llegaría el modelo, no el id.
    Route::delete('doctor/{doctorId}', [ScheduleController::class, 'destroyAll'])->name('admin.schedules.delete_all');
    Route::delete('doctor/{doctorId}/range', [ScheduleController::class, 'destroyRange'])->name('admin.schedules.delete_range');
});
