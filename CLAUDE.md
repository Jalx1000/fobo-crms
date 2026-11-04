# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

```bash
# Install PHP dependencies
composer install

# Install JS dependencies
npm install

# Run dev server (Vite)
npm run dev

# Build frontend assets
npm run build

# Run all tests
php artisan test

# Run a single test file
php artisan test tests/Feature/SomeTest.php

# Run tests with Pest directly
./vendor/bin/pest --filter="test name"

# Code style (Laravel Pint)
./vendor/bin/pint

# Database migrations
php artisan migrate

# Seed database
php artisan db:seed
```

## Architecture

This is a **Krayin CRM** - a Laravel 10 application built on a modular monorepo structure using the Concord package system. All business logic lives in `packages/Webkul/` as independent service provider-based modules, not in the root `app/` directory.

### Module layout

Each module under `packages/Webkul/<Module>/src/` follows the same structure:
- `Models/` - Eloquent models implementing contracts from `Contracts/`
- `Repositories/` - data access layer extending `Webkul\Core\Eloquent\Repository` (l5-repository pattern)
- `Services/` - business logic
- `Providers/` - service provider that bootstraps routes, migrations, translations, and views
- `Database/Migrations/` - module-specific migrations

The **Admin** module (`packages/Webkul/Admin/`) is the UI layer and contains:
- `Http/Controllers/` - grouped by domain (Lead, Contact, Products, Quote, etc.)
- `DataGrids/` - data table classes extending `Webkul\DataGrid\DataGrid`
- `Resources/views/` - Blade templates (Tailwind CSS, dark mode support)
- `Resources/assets/` - JS/CSS assets
- `Routes/Admin/` - route files per domain, all mounted under `config('app.admin_path')` with `web + admin_locale + user` middleware
- `Listeners/` - event listeners
- `Jobs/` - queued jobs
- `Mail/` & `Notifications/` - mail/notification classes

### Key modules

| Module | Responsibility |
|---|---|
| `Admin` | HTTP layer, views, DataGrids, events |
| `Lead` | Lead model, pipeline/stage/source/type repositories |
| `Contact` | Person and Organization models |
| `Attribute` | Custom attributes and values for entities |
| `Product` | Product catalog |
| `Quote` | Quote management |
| `Core` | ACL, menus, system config facade, helpers, base Repository |
| `DataGrid` | Base DataGrid class (column definitions, query builder, export) |
| `User` | CRM user management |
| `Email` / `EmailTemplate` | IMAP mail integration and templates |
| `Automation` | Workflow automation |
| `Warehouse` | Warehouse and inventory |

### Event system

Business events use string-named Laravel events (e.g., `lead.create.after`, `lead.update.after`). Listeners are registered in `packages/Webkul/Admin/src/Providers/EventServiceProvider.php`. Fire events with `event('lead.update.after', $lead)` in controllers/repositories.

### Repository pattern

All data access goes through repositories that extend `Webkul\Core\Eloquent\Repository`. Controllers receive repositories via constructor injection. Direct Eloquent queries in controllers are discouraged - put them in a repository method.

### DataGrid pattern

List views use DataGrid classes. Each DataGrid:
1. Extends `Webkul\DataGrid\DataGrid`
2. Implements `prepareQueryBuilder(): Builder` - defines the base SQL query
3. Implements `prepareColumns()` - declares columns with filters/sorting
4. Is instantiated in the controller via `app(LeadDataGrid::class)->toJson()`

### Frontend

Blade templates use Tailwind CSS utility classes with dark mode (`dark:` prefix). Components live as anonymous Blade components under `packages/Webkul/Admin/src/Resources/views/components/` and are registered with the `admin::` namespace prefix. Vite bundles `resources/css/app.css` and `resources/js/app.js`.

### Translations

All UI strings go through `@lang('admin::app.leads.index.title')` style calls. Translation files are in `packages/Webkul/<Module>/src/Resources/lang/`.

### Configuration

System config (admin-editable settings) is registered via the Core module's `SystemConfig` system. Access values with `core()->getConfigData('leads.notifications.confirmed_order.enabled')`.

### Local development

Docker Compose in `docker/docker-compose.yml` provides MySQL 9 on port 3306 and phpMyAdmin on port 8080.
