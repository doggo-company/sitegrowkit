# Deployment Rules

## Local (Phase 1)

- Local runtime is Docker only: php (app), mysql, mailpit. Do not install PHP/MySQL on the host; do not add host-only steps to docs or scripts.
- Cross-platform: everything must work on macOS (Docker Desktop) and Windows 11 + WSL2 + Docker Desktop. CLI is Node — never require Bash-only scripts for user-facing flows.
- Ports (defaults, overridable via .env): app 8180, vite 5173, mysql 33060, mailpit smtp 1026 / ui 8026.
- `.env` is generated from `.env.example` by `agentcms create` / documented flow; `.env` is never committed.
- Never put `.env`, DB files, or `.git` under `public/`. `agentcms verify` checks tracked files; keep it green.

## Staging — XServer (Phase 4, ADR-0008)

<!-- kit-only:start -->
- Provider-specific code lives ONLY in `packages/cli/src/deploy/providers/<name>.ts`. The rest of `deploy/` is provider-neutral; all remote access goes through the `RemoteExecutor` boundary (tests use FakeExecutor — never require a real server for unit tests).
<!-- kit-only:end -->
<!-- project-only:start
- Provider adapters live inside the agentcms CLI, not in this project. Deployment from a project is driven only through `agentcms deploy <doctor|setup|plan|staging|rollback>`.
project-only:end -->
- Build is LOCAL: vite build + `composer install --no-dev` in the Docker php container; the artifact bundles vendor/ + public/build. Never assume Node or Composer exists on the rental server (XServer officially has neither guaranteed).
- Artifact contents are governed by `deploy/artifact.ts shouldInclude()` (allow-list). Secrets (.env*, keys) must never enter an artifact — unit tests enforce this; keep them green. vendor/ *.pem (CA bundles) are NOT secrets — do not exclude them.
- Release layout: `appDir/releases/<id>` + `shared/.env` + `shared/storage` + `current` symlink. docroot (public_html) stays a REAL directory (entry_stub index.php + synced assets) — do not symlink public_html itself (no official XServer support).
- The entry stub MUST resolve `current` via `realpath()` before booting. Booting through the symlink path makes every release share identical absolute paths → OPcache / compiled-view keys collide and NEW RELEASE CODE IS NEVER SERVED (shared hosting cannot reload FPM; `view:clear` does not help). Found on a real device in Phase 4.
- iOS standalone PWA facts (learned on device, encoded in sw.js/useAdminPwa — do not regress): WindowClient.navigate()/openWindow/postMessage are all unreliable for notification-click navigation → pass the target URL via Cache API and let the app consume it on visibilitychange; call `registration.update()` on every launch + `skipWaiting` in the SW (iOS barely re-checks sw.js); manifest scope must be `/` (an out-of-scope /login redirect permanently demotes the app to in-app-browser mode) while SW scope stays `/admin`; serve the manifest on /login too.
- XServer DirectoryIndex priority is `index.html > index.htm > index.shtml > index.cgi > index.php` — a leftover index.html completely shadows Laravel. Deploy MUST check these four files in docroot: the XServer-generated default page (marker `Copyright XSERVER Inc.`) is moved to `appDir/backups/` (never deleted); anything else is possibly user-created → STOP, never auto-delete. Health check verifies `/` is served by Laravel (no default-page markers + app headers present).
- Secrets live only in server-side `shared/.env` (chmod 600). Deploy config `.agentcms/deploy/<target>.json` holds connection info only, is gitignored; never write DB/SMTP credentials into it, logs, plans, or benchmarks.
- Before deploy: verify `APP_ENV=staging` / `APP_DEBUG=false` / `APP_KEY` present (existence only — never read values). DB backup (mysqldump + gzip -t + size floor) must succeed or deploy stops. Never mark backup OK on file existence alone.
- Migrations run before `current` switch; on failure the old release keeps serving and DB is NOT auto-restored (report the backup path). Never claim "rollback 可能" for DB changes that need human judgment.
- Health-check failure after switch → auto-rollback `current` to previous release. `agentcms deploy rollback` never touches the DB.
- Staging safety: all mail forced via `Mail::alwaysTo` (MAIL_STAGING_REDIRECT_TO), robots.txt Disallow + X-Robots-Tag noindex, HTTPS forced.
- Control Plane = XServer API, Deployment = SSH (ADR-0009, same split as XServer's official AI skill). API client lives in `providers/xserver-api.ts`; API key comes ONLY from env `XSERVER_API_KEY` (never config/Git/logs/plans/benchmarks — config validation rejects key-like fields; error messages are redacted). `agentcms deploy setup` provisions subdomain/SSL/DB/user/grant/shared-.env/Cron/PHP — create-only, idempotent, confirmation before writes. Deletions and backup-restore are NEVER automated (API has no confirm parameter). No API key or API failure → fall back to manual server-panel instructions; doctor reports WARN, not FAIL.
- `route:cache` is forbidden while closure routes exist (robots.txt); use config:cache + view:cache only.
- Production auto-deploy, other shared-hosting providers (Lolipop/Sakura) and CDN are out of scope (DO NOT BUILD until a later phase).

## VPS — Docker (ADR-0021)

- `provider: "vps"` deploys to one Linux VPS with Docker: one Caddy + one MySQL ("edge") shared by all sites, one PHP-FPM + one scheduler container per site. Provider code lives in `providers/vps.ts` / `providers/vps-assets.ts`; the release flow in `release.ts` is shared with XServer (only PHP execution via `inDir()`, publishing via the Caddy site block, and the DB dump inside the MySQL container differ).
- Secrets (MySQL root password, DB passwords, APP_KEY) are generated ON THE SERVER by `agentcms deploy setup` and written only to 600 files. Pass values only via stdin/pipes — never in command arguments, compose `environment`, `docker exec -e`, the deploy config, plans or logs. Setup is create-only and idempotent; it never rotates an existing `shared/.env`.
- Caddy must hand PHP the RESOLVED release path (`php_fastcgi … { resolve_root_symlink }`), same reason as the XServer entry stub's `realpath()`.
- A site's Caddy block is validated outside the running Caddy before it replaces the live file; on reload failure the previous block is restored. Duplicate domain / site name → stop without changing anything. Never let one site's deploy take down another.
- Removing a site, dropping a DB and restoring a DB are manual (docs/deployment/vps.md). The end-to-end check is `scripts/vps-e2e.mjs` (manual, needs privileged Docker).
