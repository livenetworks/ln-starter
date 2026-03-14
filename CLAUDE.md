# CLAUDE.md — AI context for LN-Starter

This file provides quick context for AI assistants working on projects that use this package. For detailed rules and code generation patterns, see the full skill at `.claude/skills/ln-starter/SKILL.md` (publish with `php artisan vendor:publish --tag=ln-starter-skill`).

## What this package is

LN-Starter is a Laravel foundation package by Live Networks. It provides base classes for building applications where **one URL serves both browser and API requests**. The controller logic is written once; the response layer adapts the output format based on request headers.

## Key architecture decisions

1. **Dual-mode response**: `LNController::respondWith()` checks `Accept` and `X-Requested-With` headers to decide between HTML (Blade) and JSON output. Never duplicate controller logic for web vs API routes.

2. **Read/write model separation**: `LNReadModel` maps to database views (read-only, no timestamps). `LNWriteModel` maps to writable tables (no timestamps by default). Never call `save()` on a read model.

3. **View composers**: `LNViewComposer` provides a base class. Implement `enrich(array &$content, View $view)` to add secondary data (dropdowns, related records) without cluttering the controller.

4. **Layout switching**: `_ln.blade.php` extends either `_ajax` (for XHR) or the app's full layout. Views should `@extends('ln-starter::layouts._ln')`.

5. **Message DTO**: All controller responses can carry a `Message` object with `type`, `title`, `body`, and `data`.

6. **CSRF strategy**: Authenticated users skip CSRF. Routes can opt out with `disable-csrf` middleware marker.

7. **Cookie-to-header auth bridge**: `AuthorizationFromCookie` reads `auth_token` from cookies and sets the `Authorization` header for Sanctum.

8. **Passwordless auth module**: Opt-in via `config('ln-starter.auth.enabled')`. Provides magic link login flow — `AuthController`, `MagicLinkToken` model, `MagicLinkMail`, views, routes, and migration. User model is configurable. See `docs/auth.md`.

## When generating code for projects using this package

- Controllers MUST extend `LNController`, not Laravel's base `Controller`
- Use `$this->view('...')->respondWith($data, $message)` pattern
- Read models extend `LNReadModel`, write models extend `LNWriteModel`
- View composers extend `LNViewComposer`, implement `enrich()`
- Use `new Message('success', 'Title', 'Body')` for response messages
- Register project-specific middleware separately — RBAC is NOT part of this package
- The package namespace is `LiveNetworks\LnStarter`

## Stack context

- **Backend**: Laravel, Blade SSR (no SPA frameworks)
- **Database**: PostgreSQL preferred, with JSONB for dynamic entities
- **Frontend**: Vanilla JS (IIFE pattern), SCSS, ln-acme component library
- **Auth**: Laravel Sanctum, passwordless (Passkey + Magic Link)
- **Philosophy**: Server-side rendering, minimal JavaScript, stable long-term solutions
