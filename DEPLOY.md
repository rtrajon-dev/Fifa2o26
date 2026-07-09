# Deploying GoalJeeto to cPanel

GoalJeeto runs on **MySQL** (unlike QuizJeeto, which used SQLite). The schema is
imported once by hand; fixtures come from `data/schedule.php` via `sync_matches.php`.

## Everyday development

```bash
cd goaljeto
php tests/logic_test.php     # scoring + kickoff-time rules; runs with no DB
git add -A
git commit -m "what changed"
git push
```

## Cutting a release (builds the cPanel ZIP automatically)

When you're ready to deploy, create and push a **version tag**:

```bash
git tag v1.0.0          # bump each release: v1.0.1, v1.1.0, ...
git push origin v1.0.0
```

This triggers `.github/workflows/release.yml`, which:

1. Syntax-checks every PHP file — a ZIP with a parse error never ships.
2. Builds `goaljeto-v1.0.0.zip` (project contents, cPanel-ready).
3. Excludes `.git`, `.github`, `.env`, `data/otp_rate/`, session files, `*.log`, `.DS_Store`.
4. **Fails the build if `.env` ended up inside the ZIP** — it holds the bdapps
   password and the `ADMIN_TOKEN`, which decides who wins prizes.
5. **Fails if any `.htaccess` is missing** — those dotfiles do the security work.
6. Publishes a **GitHub Release** with the ZIP attached.

> You can also run it manually from **Actions → "Build cPanel ZIP on release" →
> Run workflow**, which produces a downloadable artifact instead of a release.

## First install on cPanel

1. **Create the MySQL database.** cPanel → *MySQL Databases*: create a database
   and a user, and add the user to the database with **All Privileges**. Note the
   full names — cPanel prefixes them, e.g. `cpaneluser_goaljeto`.

2. **Import the schema.** cPanel → *phpMyAdmin* → select the database → *Import*
   → upload `database/goaljeto.sql` → Go. This creates `users`, `matches`, and
   `predictions`. It drops those tables first, so **only do this once** — running
   it again wipes every prediction.

3. **Upload the code.** Download the ZIP from the repo's *Releases* page. In
   cPanel *File Manager*, open `public_html`, upload it, then select it →
   *Extract*. (Extracting preserves the `.htaccess` dotfiles; dragging files in
   one by one often does not.)

4. **Create `.env`** in `public_html` — it is deliberately not in the ZIP. Copy
   `.env.example` and fill in:
   - `BDAPPS_APP_ID`, `BDAPPS_PASSWORD`, `BDAPPS_APP_HASH` from the bdapps portal
   - `DB_HOST=localhost`, and the `DB_NAME` / `DB_USER` / `DB_PASS` from step 1
   - `ADMIN_TOKEN` — generate one: `php -r "echo bin2hex(random_bytes(24));"`

5. **Make the runtime directories writable (755):** `sessions/` and `data/otp_rate/`.
   Sessions break silently without the first; the OTP rate limiter fails *open*
   without the second (it logs and allows the request rather than locking players out).

6. **Load the fixtures:** visit `https://yoursite.com/sync_matches.php?token=<ADMIN_TOKEN>`
   or run `php sync_matches.php` over SSH.

## Verify after deploy

These must **all return 403 Forbidden**. If any returns content, `AllowOverride`
is off in Apache and the `.htaccess` files are being ignored:

- `https://yoursite.com/.env` — the bdapps password and admin token
- `https://yoursite.com/database/goaljeto.sql` — the schema
- `https://yoursite.com/data/schedule.php` — the fixture file
- `https://yoursite.com/bdapps/unsubscribe.php` — **most important.** Left
  reachable, this vendor file lets anyone on the internet cancel any
  subscriber's subscription. It is fronted by `unsubscribe_me.php` instead.
- `https://yoursite.com/bdapps/check_subscription.php` — a public "is this number
  subscribed?" oracle. Fronted by `subscription_status.php`.
- `https://yoursite.com/bdapps/send_otp.php` — unthrottled SMS. Fronted by the
  rate-limited `otp_send.php`.

And these must **work**:

- `https://yoursite.com/` — the landing page, with the fixture list
- `https://yoursite.com/bdapps/verify_otp.php` — POST-only; a GET returning JSON
  is fine, it just means "OTP and reference number are required"

### Why bdapps/ is never edited

`bdapps/` is vendor code, kept byte-for-byte as shipped. Every security guarantee
lives in a docroot wrapper that `require()`s the vendor file after enforcing its
gate — `require()` is a filesystem read, so the `.htaccess` deny above does not
block it. CI fails any commit that modifies `bdapps/`.

| Vendor file (denied to the web) | Wrapper the browser calls | Enforces |
| --- | --- | --- |
| `bdapps/send_otp.php` | `otp_send.php` | 3 OTPs/hour per number (60s apart), 10/hour per IP |
| `bdapps/check_subscription.php` | `subscription_status.php` | session-gated, own number only |
| `bdapps/unsubscribe.php` | `unsubscribe_me.php` | session-gated, POST-only, own number only |

## Running the tournament

**Fill in the teams as FIFA confirms them.** Edit `data/schedule.php`, replace
`'TBD'` with the team names in Bengali, then re-run `sync_matches.php`. A match
with `TBD` in either slot shows as "দল নিশ্চিত হয়নি" and cannot be predicted —
so **until you do this, nobody can play.** Never change a match's `code`:
everything keys off it, which is what lets a TBD match keep its predictions once
the teams are named.

**Enter the result once a match ends:**

```bash
php settle.php FINAL 2 1            # Brazil 2 – 1 France → 3 goals total
php settle.php FINAL 2 1 --force    # re-settle with a corrected score
```

or use the web form at `https://yoursite.com/settle.php?token=<ADMIN_TOKEN>`.
Settling is idempotent and transactional: running it twice with the same score
changes nothing, and `--force` recomputes rather than double-awarding.

Points are awarded on the **total** goals: exact bucket +3, one off +1, where a
pick of "৫+" matches any score of 5 or more.

## Updating an existing install

- Re-extract a newer release ZIP over the same folder (overwrites code).
- Your `.env` stays — it is never in the ZIP.
- **Do not re-import `database/goaljeto.sql`.** It begins with `DROP TABLE`, so it
  would erase every user, prediction and result.

## Prizes are not automatic

The reward labels on the leaderboard are **display only**. Nothing is sent, and no
record of a win is written — fulfil them by hand from the leaderboard. Note also
that `settle.php`'s `?token=` travels in the URL, so it can leak via browser
history and server logs. Don't paste that URL anywhere.
