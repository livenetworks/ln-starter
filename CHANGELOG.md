# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

### Security
- Keep CSRF protection enabled for session and cookie-authenticated requests; only `disable-csrf:bearer` routes carrying an explicit Authorization bearer token are excluded
- Delegate `sanctum.token` authentication to Sanctum's official guard so token expiry, provider checks, events, and usage tracking are preserved
- Protect modal submissions, magic-status polling, and the built-in logout route with normal CSRF validation
- Invalidate the authenticated web session and rotate its CSRF token during logout
- Make the `auth_token` cookie HttpOnly, SameSite-aware, and Secure in production
- Publish an additive first/last-name migration without replacing consumer-owned users migrations

### Changed
- Add `<x-ln.logout-form />` as the CSRF-safe package logout control
- Change `/magic/status` from GET to POST because approval creates a token and cookie
- Accepted the magic-link v2 link-plus-code state-machine design and security acceptance matrix; implementation follows after the observability foundation
- Auth views redesigned: card-based layout with gradient backgrounds, inline SVG icons, animations, and richer UX (info boxes, countdown, troubleshooting tips)
- Auth SCSS (`auth.scss`) rewritten as fully standalone — no ln-acme dependency; uses CSS custom properties and self-contained BEM classes
- Auth layout (`_auth.blade.php`) simplified to minimal HTML shell; views handle their own full-screen layout

## [0.1.0] — 2026-03-14

### Added
- `LNController` with dual-mode response (`respondWith`)
- `LNReadModel` (read-only Eloquent for DB views)
- `LNWriteModel` (write Eloquent, no timestamps)
- `Message` DTO for unified response messages
- `BusinessException` for domain-level errors
- Middleware: `AuthenticateWithSanctum`, `AuthorizationFromCookie`, `DisableCsrf`, `VerifyCsrfToken`
- Blade layouts: `_ln` (layout switcher), `_ajax` (JSON response)
- Config file with publishable assets
- Stubs for scaffolding controllers and models
- Documentation (README, CLAUDE.md, docs/)
