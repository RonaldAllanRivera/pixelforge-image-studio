# Changelog

All notable changes to this project, grouped by build phase, newest first. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## Phase 0: Project setup (2026-11-17)

### Added

- Laravel 13, Vue 3 and Inertia 3 from the official Vue starter kit, with sign-up and login only. Email verification, two-factor login and passkeys were left out: this app doesn't need them, and email verification would lock out anyone without a mail inbox set up.
- PostgreSQL everywhere: `compose.yaml` for local development, a separate `pixelforge_testing` database for tests, and a Postgres service in CI. Tests run on the same database engine as production, because the money code relies on Postgres row locks and unique indexes.
- Pest for tests. Every HTTP call in a test must be faked, so the suite can never reach Pixelforge.
- An `is_admin` flag on users for the upcoming admin report. It isn't mass assignable, and a test proves sign-up can't set it.
- The real `GET /v1/pricing` response as a test fixture.
- `brick/math` for exact decimal arithmetic.
- CI on every push: formatting, TypeScript types, Pint, PHPStan and the test suite.
- README describing the design, this changelog and the `.gitignore`.
