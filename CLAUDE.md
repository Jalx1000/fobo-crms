# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

This is **Odontoking CRM**, a dental clinic CRM built on top of [Krayin CRM](https://krayincrm.com) - an open-source Laravel + Vue 3 CRM framework. The project extends Krayin's modular package architecture with a custom `Doctor` module for managing dental doctors, specialties, and schedules.

## Commands

### PHP / Laravel

```bash
# Run all tests
php artisan test

# Run a single test file
php artisan test tests/Feature/DoctorCrudTest.php

# Run tests with a filter
php artisan test --filter DoctorCrudTest

# Using Pest directly
./vendor/bin/pest
./vendor/bin/pest tests/Feature/DoctorCrudTest.php

# Code style (Laravel Pint)
./vendor/bin/pint
./vendor/bin/pint --test   # dry run only

# Artisan commands
php artisan migrate
php artisan migrate:fresh --seed
php artisan cache:clear && php artisan config:clear && php artisan view:clear
```

### Frontend (Vite per package)

Each package with a UI has its own `vite.config.js` and `package.json`. Run Vite from the relevant package directory:

```bash
# Admin package (main UI)
cd packages/Webkul/Admin && npm run dev
cd packages/Webkul/Admin && npm run build

# Doctor package
cd packages/Webkul/Doctor && npm run dev
cd packages/Webkul/Doctor && npm run build
```

The root `package.json` / `vite.config.js` at the project root is for the base Laravel app and does **not** build the CRM admin UI.

## Architecture

### Modular Package Structure

All domain logic lives in `packages/Webkul/`. Each package is symlinked via Composer (`"repositories": [{"type": "path", "url": "packages/*/*"}]`) and follows the Krayin/Concord modular convention:

```
packages/Webkul/<Module>/
├── src/
│   ├── Config/          # menu.php, acl.php, etc.
│   ├── Contracts/       # Interfaces for models
│   ├── DataGrids/       # Admin data table definitions
│   ├── Database/
│   │   └── Migrations/
│   ├── Http/
│   │   └── Controllers/
│   ├── Models/          # Eloquent models + Proxy classes
│   ├── Providers/       # ServiceProvider + ModuleServiceProvider
│   ├── Repositories/    # l5-repository pattern
│   ├── Resources/
│   │   ├── assets/      # Vite-compiled JS/CSS
│   │   └── views/       # Blade templates
│   └── Routes/          # web.php, api.php
```

**Model Proxies**: Every model has a companion `*Proxy.php` (e.g., `DoctorProxy`) that resolves the concrete class at runtime. Always use `DoctorProxy::modelClass()` in relationships rather than referencing `Doctor::class` directly - this is the Concord extensibility pattern used throughout.

### Custom Doctor Module (`packages/Webkul/Doctor`)

This is the primary custom extension. It provides:
- **Doctors** - dental practitioners with specialties and shifts
- **Specialties** - dental specialties (`Orthodontics`, etc.)
- **Shifts** - doctor availability slots
- Doctor availability API endpoints (`/api/doctor/availability`)

The Doctor module hooks into the Activity model: `Activity` has a `doctors()` belongsToMany relationship via `doctor_activities`.

### Admin Package

`packages/Webkul/Admin` is the main UI package:
- **Blade** views in `src/Resources/views/` - use `<x-admin::*>` Blade components
- **Vue 3** inline components embedded directly in Blade files (no separate `.vue` SFC files in the Admin package). Components are defined as `<template>` / `<script>` inside `<x-admin::...>` components or as inline `app.component(...)` registered in `app.js`.
- **Tailwind CSS** for styling
- Vue plugins registered globally: `$admin` (formatPrice/formatDate/getLabelColor), `$axios`, `$emitter` (mitt), `flatpickr`, `vee-validate`, `vue-cal`, `vuedraggable`
- Event system uses `view_render_event()` for extensible layout hooks

### Key Domain Concepts

| Krayin Entity | Dental CRM Meaning |
|---|---|
| Lead | Patient appointment / case |
| Person | Patient |
| Organization | Clinic / branch (sucursal) |
| Activity | Appointment / consultation (type hardcoded to "Consulta") |
| Pipeline / Stage | Treatment workflow stages |
| Product | Dental service / procedure |

### Testing

Tests use Pest (built on PHPUnit). Feature tests live in `tests/Feature/` and include a mix of custom dental-clinic tests (Doctor, Specialty, Insurance, Patient) and API tests. The `.env.testing` file is used automatically.

### Code Style

Laravel Pint with the `laravel` preset, plus aligned `=>` operators:
```json
{ "preset": "laravel", "rules": { "binary_operator_spaces": { "operators": { "=>": "align" } } } }
```
