# Changelog

All notable changes to this project, grouped by build phase, newest first. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## Phase 10: Release (2026-11-17)

### Fixed

- After a "Not enough credits" refusal, buying credits clears the message, so it no longer sits under an enabled Generate button.
- The Studio asks for the price again whenever the exact balance changes, not only when its 4-decimal display does. A balance one nanodollar short of the maximum, then topped up by one nanodollar, used to keep Generate off.

### Changed

- The Credits page colours purchases and releases green by their entry type, instead of reading the "+" in the displayed amount.

### Removed

- The quote's unused `balance` field (the balance on screen comes from the shared page props), and the report rows' unused `profitNano()` and `userId`.

### Added

- Tests that a balance exactly equal to the maximum is enough, and that nothing is held once an image has settled.

## Phase 9: Credit balances and the admin report (2026-11-17)

Both stretch goals. Every account starts at $0.

### Added

- **Credit balances.** Each user has a balance in nanodollars (`users.balance_nano`), and every change to it is an entry in `credit_transactions`: purchase, hold, release or charge. One class, `CreditLedger`, writes them, each entry in the same transaction as its balance change.
    - Submitting a generation **holds its maximum charge**, with the user's row locked. A balance that can't cover the maximum creates nothing (no row, no hold, no job) and the form says "Not enough credits. This image can cost up to … and your balance is …".
    - When the generation ends, `ApplyGenerationSnapshot` **releases the hold** and, on success, **takes the actual charge**, in its existing locked transaction. A failure only releases. A duplicate webhook or a poll racing a webhook can't settle twice.
    - The database refuses a negative balance (`CHECK`) and a second hold, release or charge for one generation (unique index).
    - Generations made before balances settle without touching credits.
- **Buy credits:** a dialog with $5, $10 and $25 (a pretend purchase, no payment), on the Studio and the Credits page. On the Studio it opens in place, so a typed prompt is kept. Limited to 10 purchases a minute.
- **The balance on every page**, in the header, and under the price on the Studio. Generate is disabled and reads "Not enough credits" when the server says the chosen quality isn't affordable; a purchase or a finished image re-asks the server. Balances are shown rounded down; holds rounded up, like the maximum they hold.
- **Credits page** (`/credits`): the balance (and its exact 9-decimal value), what's held for images in progress, and every entry, newest first, with the prompt it was for. The generation's detail page lists its own entries.
- **Admin report** (`/admin/report`, admins only): what users were charged, Pixelforge's cost and the profit, per UTC day and per user, for any range of up to 92 days (the last 30 by default). Amounts show 9 decimals.
    - Each day, and the whole range, is **checked against Pixelforge's `GET /v1/usage`**: generations, all four token counts and the cost must match to the nanodollar, or the differences are listed. Pixelforge's part loads after the page; if Pixelforge can't be reached, the report says so and still shows our numbers. The comparison is limited to 5 a minute across all admins, so the report can't use up the account's rate limit.
    - Clicking a day narrows the report to it, showing who spent what that day. Emails are masked (`al•••@gmail.com`); a deleted account's generations show as "Deleted account".
- `php artisan users:make-admin {email}` (and `--remove`) makes an existing account an admin.
- **Browser tests** (Pest's browser plugin, Playwright, Chromium) for the Studio, buying credits, the balance after an image finishes, the Credits page and the admin report, at desktop and phone width. CI installs Chromium and runs them with the rest of the suite.

### Changed

- The Studio's 2-second status poll also reloads the balance.
- Local seeded users start with $10 of credit.

## Phase 7: The Studio UI (2026-11-17)

### Added

- **Studio** (`/studio`, where login lands): the form to generate an image and the user's history below it.
    - The prompt counter counts Unicode characters, as Pixelforge does, so an emoji is one.
    - Size and quality are buttons, and each quality shows its own price.
    - Up to 4 reference image URLs, each showing its +765 tokens.
    - The live price ("Estimated … · never more than …") comes from a new quote endpoint and updates 300 ms after typing stops. "How this price is made" breaks it down by token type.
    - The history shows 12 per page, newest first. Each card has the image, a status badge (with the waiting reason, or elapsed time while generating), and the estimated and actual cost. Failed cards give a plain-English reason, "You were not charged", and Try again, which refills the form.
    - The history refreshes every 2 seconds only while a generation on the page is running, and stops once all are final.
- **Detail page** (`/generations/{id}`, owner only): estimated, maximum and actual tokens per type at the generation's own rates, the cost to 9 decimals, our fee, the charge, Pixelforge's id and the timestamps.
- Every amount is formatted on the server by API resources (`GenerationResource`, `GenerationDetailResource`); the browser never does money math.
- The token counts behind each quote are stored (`quoted_usage`), so the detail page can compare them with the actual ones.
- Validation copies Pixelforge's rules (4,000 characters, 4 `http(s)` reference URLs, the known sizes and qualities), so we never send a request it would reject.
- A simple landing page, sidebar links to this repository and its README, and a new logo and favicon replace the starter kit's.

### Changed

- The webhook route moved out of the `web` middleware group (no session, cookies or CSRF), so a webhook delivery no longer creates a session row.
- Tests no longer need built front-end assets (`withoutVite()`).

### Fixed after an independent review

- "Never more than" could show less than the real cap: amounts rounded half up, so $0.340025 showed as $0.3400. Maxima now round up (`Nanodollars::formatAtMost()`), so the shown maximum is never below what a charge can reach.
- The live price could belong to the previous inputs for a moment, and a prompt over 4,000 characters left "Working out the price…" on screen forever. The price now dims with "Updating…" until it matches the form, Generate waits for it, and a too-long prompt or an unreachable server says so.
- Polling could run forever for a generation stuck by design (left pending after a `409`, or a poll Pixelforge rejected). A generation with no news for 15 minutes is now "stalled": the page stops polling it and says the hourly check will pick it up. Polls also wait for each answer before the next one, and pause in a hidden tab.
- `/generations/abc` was a server error; it's now a 404.

## Deployment (2026-11-17)

### Added

- `aws/aws-sdk-php`, which Laravel Cloud's managed queue needs: it runs on Amazon SQS. Jobs run there because queued jobs don't wake an app that has scaled to zero. See `docs/DECISIONS.md`.

### Fixed

- The first Laravel Cloud build failed at `npm ci` with "Missing: react from lock file". npm on Windows had left the dependencies of `@laravel/multiplex` (an optional package for macOS and Linux only) out of `package-lock.json`. The lock file is now generated on Linux, and CI runs `npm ci` so a lock file the deploy can't install fails CI first.

## Review fixes (2026-11-17)

An independent review of Phases 0–6 confirmed the money path (exact costs, one charge per generation, failures free) and found these, each fixed with a test that failed first:

### Fixed

- The submit job gave up after 5 POSTs of any kind once Pixelforge said "overloaded", so a single random 503 after a few rate limits failed the generation. Now only overloaded answers count (new `overloaded_attempts` column), and the 2, 4, 8, 16-second backoff uses that count.
- The free-slot check counted every queued or processing row, however old, so three rows with lost webhooks could block every new generation. Rows with no news for 10 minutes no longer count.
- Deleting an account deleted its generations, which are billing records. They now stay, with `user_id` set to null.
- A `409 idempotency_key_reused` proves a generation exists, but failed it at no cost. It now stays pending, and an error is logged.
- A 429 with an unknown error code now waits (retry later) instead of failing, and a 408 is treated as an unknown outcome.
- `metadata.local_id` is now the generation's UUID instead of its numeric id, which a database reset could reuse and point an old webhook at a new generation.
- Polling stops after 16 polls instead of 15 minutes after creation, so a generation that waited long for a slot is still polled.
- A submit retry that finds Pixelforge already accepted the generation now starts polling it.
- The waiting reason is cleared once Pixelforge accepts the generation.
- A waiting submit job touches its row, so the hourly reconcile doesn't start a second job for it.
- Both jobs back off 10, 30 and 60 seconds after an unexpected error instead of retrying in a tight loop.

## Phase 6: Polling and the hourly catch-up (2026-11-17)

### Added

- `PollGeneration` job: after a successful submit, asks Pixelforge how the generation is doing after 20 seconds, then 30, 45 and every 60 seconds until it finishes, for up to 15 minutes. Results go through the same `ApplyGenerationSnapshot` as webhooks, so a poll and a webhook reporting the same success charge once. Polls share the 50-per-minute Pixelforge limiter and wait out its rate limits.
- `generations:reconcile`, scheduled hourly: polls accepted generations with no news for 2 minutes, and resubmits never-accepted ones with their original `Idempotency-Key`, which can't create a second generation. Hourly rather than every minute keeps the app inside Laravel Cloud's free usage credit, because each scheduled run wakes the app and database.
- Seen live: a generation whose first webhooks were refused (the CSRF bug fixed in Phase 5) was recorded correctly when Pixelforge retried them 10 minutes later. It was charged once.

## Phase 5: Webhooks (2026-11-17)

### Added

- `POST /webhooks/pixelforge`: verifies the `Pixelforge-Signature` header against the raw body (HMAC-SHA256 with this environment's secret, compared in constant time, timestamps older than 5 minutes refused, any of several `v1` values accepted for secret rotation), then stores and applies the event in one transaction.
- `webhook_events`: every delivered event, unique by Pixelforge's event id. A duplicate delivery is answered `204` and changes nothing. If applying fails, the stored event rolls back and the endpoint answers `500`, so Pixelforge's retry is processed normally.
- A webhook that arrives before the create response finds its generation by the id we sent in `metadata`, but only from this environment.
- Verified live with the local key: a `[duplicate] [succeed]` generation sent 4 webhook requests. 2 events were stored and the cost was recorded once ($0.013635000, charged $0.017043750).

### Fixed

- The webhook route skips CSRF protection. Laravel 13's web group uses `PreventRequestForgery`; excluding the old `ValidateCsrfToken` name had no effect, so real webhooks got `419`. Tests miss this, because Laravel turns CSRF off while testing, so a test now checks the middleware the route really runs.

## Phase 4: Submitting generations (2026-11-17)

### Added

- `CreateGeneration`: saves the user's generation as pending with its estimate and maximum price, then queues it for Pixelforge once the row is committed.
- `SubmitGeneration` job: sends the generation with its own `Idempotency-Key` and an identical body on every attempt, so Pixelforge creates it at most once. Our generation id goes in `metadata`, so a webhook can find it even before the create response arrives.
    - Waits for a free slot when 3 generations are already running, and when Pixelforge reports its concurrency limit.
    - Waits out rate limits for as long as `Retry-After` says. All Pixelforge calls share one 50-per-minute limiter.
    - Retries an overloaded Pixelforge after 2, 4, 8 and 16 seconds, then fails the generation at no cost.
    - Fails at no cost when Pixelforge rejects the request (content policy, daily limit), and logs an error when the rejection points to our own mistake.
    - Keeps the generation pending after a timeout or unclear answer and retries with the same key, never marking it failed.
- `WaitingReason`: why a pending generation is waiting (free slot, rate limit, overloaded, unknown outcome), for the UI.

## Phase 3: Generations and the one place their status and money change (2026-11-17)

### Added

- The `generations` table and `Generation` model: the request, Pixelforge's id and rates, the estimated and maximum price, and, once finished, the exact tokens, cost and charge. All money in nanodollars.
- `ApplyGenerationSnapshot`: every report about a generation (create response, poll or webhook) goes through it. It locks the row and only applies a report that moves the generation forward, so duplicate, late and out-of-order reports change nothing and a generation is charged exactly once. It bills at the rates fixed on the generation, records failures at no cost, never charges more than the quoted maximum, and ignores reports about a different Pixelforge generation.
- `GenerationStatus::canMoveTo()`: pending → queued → processing → succeeded or failed, and never out of a final status.

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
