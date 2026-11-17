# Changelog

All notable changes to this project, grouped by build phase, newest first. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## Phase 2: Pixelforge client (2026-11-17)

### Added

- `PixelforgeClient`: the only code that talks HTTP to Pixelforge. It creates a generation with an `Idempotency-Key`, fetches a generation, and reads the price sheet. Each request is sent once; retrying is the caller's decision.
- Typed exceptions for every documented error code, in three families that say what to do next: `RetryLater` (rate limit, concurrency limit, overloaded, each with how long to wait), `Rejected` (nothing was created: content policy, daily limit, validation, invalid key, not found, idempotency key reused, and unknown 4xx codes) and `OutcomeUnknown` (timeouts, server errors, unreadable answers: the generation may exist, so it is never marked failed).
- `GenerationSnapshot`: one reader for the generation object, whether it comes from creating, fetching or a webhook.
- `CurrentPrices`: live pricing for estimates, kept for 10 minutes, falling back to the published prices when Pixelforge can't be reached.
- Test fixtures captured from the real API: queued, succeeded and failed generations, every error body, and real `processing`, `succeeded` and `failed` webhook events.

## Phase 1: Money (2026-11-17)

### Added

- `Nanodollars`: money as whole nanodollars. Parses and writes Pixelforge's 9-decimal strings exactly, formats amounts for display, and adds the markup. The markup, rounded up, is the only rounding in the app.
- `TokenRates`: turns Pixelforge's "USD per 1M tokens" rates into whole nanodollars per token, and refuses a rate that would need rounding.
- `PriceSheet`: the token rules from `GET /v1/pricing` (output tokens by quality and size, reasoning ranges, 765 tokens per reference image), read from the real response.
- `PricingCalculator`: the exact cost of any token usage, the user's charge, and a quote (estimate and maximum) before a generation runs. Tested against the docs' worst case ($0.373875), the docs' `/v1/usage` example ($0.169080000), and a live generation.
- The 25% markup in `config/pricing.php`.
- `docs/DECISIONS.md`.

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
