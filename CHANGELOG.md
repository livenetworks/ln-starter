# Changelog

All notable changes to this project will be documented in this file.

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
