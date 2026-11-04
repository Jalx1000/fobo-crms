# Docker Build Fix - 2026-05-29

## Problem
Build failure in Railway with `apk add` exit code 2 in Stage 3 (production image).

## Root Cause
Two issues identified:

1. **Unpinned base image** (`php:8.2-fpm-alpine` without version lock): Railway's build cache
   may have used an Alpine version where package availability or repo state differed,
   causing `apk add` to fail with exit code 2 (package not found).

2. **Redundant `c-client` package**: `imap-dev` already declares `c-client` as a hard
   dependency (`apk info -R imap-dev` confirms `c-client=2007f-rXX`). Listing it
   separately is redundant and could cause a conflict if versions diverge across Alpine
   minor releases.

3. **Redundant runtime libs**: `libpng`, `libjpeg-turbo`, `libwebp`, `freetype`, `libzip`
   were listed alongside their `-dev` counterparts. The `-dev` packages automatically pull
   in the runtime libraries as dependencies, making the explicit runtime entries redundant.

## Fix Applied
- **Pinned base image**: `php:8.2-fpm-alpine` → `php:8.2-fpm-alpine3.22`
  - Alpine 3.22.4 confirmed to have all required packages in `main` + `community` repos
- **Removed `c-client`**: already a transitive dependency of `imap-dev`
- **Removed redundant runtime libs**: `libpng`, `libjpeg-turbo`, `libwebp`, `freetype`, `libzip`
  (all pulled in automatically by their `-dev` variants)

## Verified
All packages tested locally with Docker:
- `php:8.2-fpm-alpine3.22` - all packages install, exit code 0
- `php:8.2-fpm-alpine3.22` - PHP ext configure (gd, imap) completes successfully
