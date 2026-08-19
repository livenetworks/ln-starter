# ADR 0004: Versioning, release and distribution

- Status: Accepted for implementation
- Date: 2026-08-19
- Scope: Semantic versioning policy, supported versions, release flow, distribution
- Builds on: [ADR 0003](0003-auth-v2-production-and-release-contract.md)

## Context

ADR 0003 defined what has to be demonstrated before a release. It did not define
what a release *is*: how the version number is chosen, which artifact is
canonical, in what order a tag becomes a published package, and what happens
when a published release turns out to be wrong.

The repository reaches this ADR in a specific state, and the policy below is
written for it rather than in the abstract:

- The last tag is `v1.2.1` (2026-06-02). It is an ancestor of the current head.
- 34 commits separate `v1.2.1` from the current head, including a complete
  replacement of the auth flow.
- Tag naming is already inconsistent: the first release is tagged `1.0.0`, every
  later one `v1.1.0` … `v1.2.1`.
- `CHANGELOG.md` opens with a `[0.1.0] — 2026-03-14` section that corresponds to
  the repository's first commit, not to any tag that exists.

This ADR adds no runtime behaviour. It constrains process only.

## Semantic versioning policy

The package follows [Semantic Versioning 2.0.0](https://semver.org). The public
API — the surface that governs the version number — is:

1. Class, interface, enum and trait names under `LiveNetworks\LnStarter`, and
   their public and protected members.
2. Route **names** registered by the package (`login`, `logout`,
   `auth.magic.*`), and the HTTP method and status contract of those routes.
3. Config keys under `config/ln-starter.php`, including whether a key is
   required.
4. Middleware aliases in `middleware_aliases`.
5. `vendor:publish` tag names, and which files each tag publishes.
6. Published Blade view names and the sections/slots a consumer's override must
   provide.
7. Migrations the package loads or publishes, and the columns they create.
8. Security event names and the shape of their envelope.
9. Artisan command signatures and their exit codes.
10. The `require` constraints in `composer.json` — raising a floor is breaking.

Anything outside that list is internal, including: the harness scripts, the
`tests/` tree, the CI workflow, private members, and the wording of log messages
and console output.

**Major** — removing or renaming anything in the list, changing a route's status
or method contract, making a previously optional config key required, or raising
a dependency floor.

**Minor** — adding to the list in a way an existing installation can ignore.

**Patch** — a fix that changes no item in the list.

A single removed public route name forces a major. There is no "mostly
compatible" release.

### One special case, stated because it has already occurred

A change that leaves an existing installation *unable to boot* is always major,
even when nothing was renamed. Auth v2 makes `auth.peppers.current` and
`auth.peppers.keys` mandatory: an installation upgrading without touching config
throws during service-provider boot. Requiring new configuration is a breaking
change to item 3.

## Supported versions

Production support tracks [ADR 0003](0003-auth-v2-production-and-release-contract.md)
and is restated here because it is part of the release contract:

| Target | Status |
|---|---|
| Laravel 13, Laravel 12 | **Supported** |
| Laravel 11 | **Compatibility only** — end of life |
| PHP 8.3 / 8.4 / 8.5 | Supported |
| MySQL/InnoDB, PostgreSQL | Supported |
| SQLite | Development and tests only; refused by production readiness |

"Compatibility only" for Laravel 11 means exactly this: the package still
installs and its suite passes there, and that lane is exempt from the
`composer audit` gate because its final upstream release carries unresolved
advisories. A green Laravel 11 lane is **not** a statement that Laravel 11 is
security-supported, and the package does not advertise it as a production
target. Dropping the Laravel 11 lane is a **minor** change, because the package
never promised it as a supported target; raising the `illuminate/*` floor in
`composer.json` is **major**, because that item is in the public API list.

## Breaking-change and deprecation policy

1. A breaking change ships only in a major release.
2. Anything to be removed is first **deprecated in a minor release**: it keeps
   working, is documented as deprecated in `UPGRADE.md` and the changelog, and
   where it is code, carries `@deprecated`.
3. The deprecation window is **one minor release minimum, and at least 90 days**,
   whichever is longer. Removal happens in the next major.
4. A deprecated endpoint must be inert rather than merely discouraged where
   leaving it live would be a security risk. The auth v1 endpoints are the
   worked example: `GET /magic/wait` redirects and `GET|POST /magic/status`
   returns HTTP 410, and neither can issue a credential during the window.
5. A security fix may break compatibility inside a minor or patch release when
   there is no compatible fix. It must say so in its changelog entry, in its own
   `### Security` section, and the release notes must lead with it.

## Release flow

A version becomes a release in exactly this order. No step may be skipped, and
no step may run on a different artifact than the one before it.

1. **Release candidate.** Release notes and changelog section are written on a
   branch; the version is *not* tagged. `scripts/release-check.php` passes.
2. **Full qualification.** The branch gets a complete green CI run: every test
   matrix lane, the artifact job, and both consumer jobs.
3. **Tag.** An annotated tag `vMAJOR.MINOR.PATCH` on the exact qualified commit.
   The `v` prefix is canonical (see below).
4. **Release workflow.** The tag triggers `.github/workflows/release.yml`, which
   re-runs qualification, builds **one** archive, generates its SHA-256, and
   installs a consumer from that same archive.
5. **GitHub Release.** Created only after every gate in step 4 is green, with the
   archive and its checksum attached.
6. **Packagist.** Publishes from the tag. No token is stored in this repository.

Steps 1–2 are the release candidate. A release candidate that has not completed
step 2 is not a release candidate; it is a branch.

### Tag naming

The canonical form is `vMAJOR.MINOR.PATCH`. The historical `1.0.0` tag is left
untouched — retagging published history is worse than an inconsistency — and
every future tag carries the `v`. The release preflight rejects a candidate that
does not match `^v\d+\.\d+\.\d+$`.

## The canonical artifact

There is exactly one artifact per release: the archive produced by
`composer archive` and inspected by `scripts/verify-artifact.php`, governed by
`.gitattributes` `export-ignore`.

Three rules follow, and they are the point of this section:

1. **The tested artifact and the published artifact are the same file.** The
   release workflow builds once. Consumer qualification installs from that build,
   not from the working tree and not from a rebuild.
2. **A rebuild is a different artifact.** Even a byte-identical rebuild is
   treated as untested, because "byte-identical" is an assumption until a
   checksum proves it. The published SHA-256 is the one computed from the tested
   build.
3. **Nothing may be published that no consumer job installed.** If the consumer
   install from the archive did not run, there is no release.

`composer install` from Packagist resolves the tag, not the workflow artifact.
The archive is therefore a *verification* artifact and a release attachment, not
the delivery channel. It exists so that what the tag contains has been installed
by something before anyone depends on it. This is why the artifact job and the
consumer job must use the same extracted directory: it is the only step that
proves the shipped file set, minus everything `export-ignore` strips, boots as a
Laravel package.

## Rollback and yank

Published versions are immutable. A tag is never moved and never deleted, and a
Packagist version is never overwritten.

1. **Superseded by a patch.** The default. A broken `2.0.0` is answered by
   `2.0.1`, and the release notes for the patch state what was wrong.
2. **Marked, not deleted.** The GitHub Release for the bad version is edited to
   carry a prominent warning at the top and a link to the fixed version. The
   assets stay attached — deleting them breaks anyone who pinned a checksum.
3. **Yank only for a credential or data-loss defect.** Removing a version from
   Packagist is reserved for a release that leaks a secret or destroys consumer
   data, because a yank breaks every lockfile that references it. It requires
   the same explicit approval as publishing.
4. **Operational rollback is a consumer procedure**, not a package one, and is
   documented in [`docs/deployment.md`](../deployment.md). Note the asymmetry
   already recorded in ADR 0003: `ln-starter:auth-v2-cutover --force` destroys
   pending v1 proofs and cannot be undone by downgrading.

## Consequences

- Auth v2 forces `2.0.0`. The evidence is the public-API inventory in
  [`docs/releases/2.0.0.md`](../releases/2.0.0.md); the shortest sufficient
  argument is that `auth.magic.show` and `auth.magic.consume` no longer exist.
- The `[0.1.0]` changelog section does not describe a release and is corrected
  against git history rather than deleted, since the entries themselves are
  accurate about what the code did at the time.
- Release automation gains a fail-closed preflight and a tag-driven workflow.
  Neither can publish anything on its own: the workflow needs a tag, and tagging
  needs a human.
