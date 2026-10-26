# PHP 8.5.12 deployment

The root Dockerfile replaces Nixpacks with `php:8.5.12-fpm-bookworm`, nginx on port 80, and Supervisor. It preserves the database queue with one transcription/legacy worker and one ingest/report worker, 30 MB PHP upload limit, and 35 MB request limit. Node 22.23.4 is used only to compile assets. The final image uses the same PHP build for CLI workers and FPM.

Composer targets PHP 8.5.12 for dependency resolution. Installation does not ignore platform requirements, and `composer check-platform-reqs` checks the actual runtime regardless of that resolution setting. Keep the PHP base, Composer platform/requirement, CI PHP versions, and smoke-test assertion in sync when updating PHP. Keep the Node image, package engine, and CI Node versions pinned to the same patched release.

## Build and test

With Docker BuildKit and the existing Flux credentials in your shell environment:

```sh
docker build --pull \
  --secret id=FLUX_USERNAME,env=FLUX_USERNAME \
  --secret id=FLUX_LICENSE_KEY,env=FLUX_LICENSE_KEY \
  -t search-my-audio:php8512 .
bash docker/smoke-test.sh search-my-audio:php8512
```

Never pass credentials through build arguments. The Dockerfile requires secret mounts and does not write Composer authentication to disk. `.dockerignore` excludes local environment files, credentials, dependencies, databases, and runtime storage. The image build needs no production database, APP_KEY, or API credentials. Frontend assets include Flux's vendor templates.

The Container smoke test workflow (`.github/workflows/container-smoke-test.yml`) uses the existing Testing environment's Flux secrets. It builds the real production image, verifies CLI/FPM versions and platform requirements, and boots nginx, FPM, and both queue workers against a disposable SQLite database. It explicitly migrates only the disposable fixture, verifies ordinary startup leaves a fresh database unmigrated, and checks Laravel's `/up`, and blocked sensitive/PHP paths. The existing application test job runs on PHP 8.5.12.

## Coolify cutover

1. Back up the database and persistent storage. Test this branch in a staging resource with separate credentials/database before the production cutover. Review the dependency lock updates for PHP 8.5 compatibility.
2. Change **Build Pack** to **Dockerfile**, Dockerfile location to `/Dockerfile`, base directory to `/`, and exposed port to `80`. Use the final `production` stage (or leave the target blank). Publishing `/public` is unnecessary: nginx serves `/app/public` inside the image.
3. Enable **Docker BuildKit secrets**. Keep `FLUX_USERNAME` and `FLUX_LICENSE_KEY` available during build only. Make application credentials/runtime settings runtime-only. The Dockerfile deliberately fails if the Flux secret mounts are unavailable; it has no build-argument fallback.
4. Keep the existing production runtime environment and storage mounts. Remove obsolete `NIXPACKS_*` variables and Nixpacks build/start overrides. Check any Coolify pre/post-deployment commands: this image owns runtime optimization; migrations require a separate reviewed operator action. Remove automatic migration commands. Preserve existing scheduled tasks, pointing them to `php /app/artisan ...` as appropriate.
5. Force a fresh build. The entrypoint prepares writable storage, discovers packages and optimizes Laravel using runtime configuration before starting web/queue processes. Any initialization failure prevents startup. It never runs migrations automatically. Follow [the stages 1–2 runbook](deployment/stages-1-2.md) for backup, pending-migration review and worker coordination before any explicit migration.
6. Enable the HTTP health check on `/up`, port `80`, allowing enough startup time for runtime optimization. Verify `php -v` and `php-fpm -v` both report **8.5.12** in the deployed container. Inspect Supervisor status and test sign-in, upload, transcription/search, queue processing, and billing callbacks.
7. Repeat the build-pack/secret changes for previews before restarting them. Retain a known-good **patched** image for rollback; reverting to the old PHP 8.2.27 image reintroduces the security vulnerability. Database rollback is a separate decision.

Supervisor forwards shutdown to worker process groups and permits up to 900 seconds for jobs to finish. Configure the container stop grace period in Coolify to accommodate actual job durations; an orchestrator's shorter timeout overrides Supervisor's wait. Monitor memory usage before increasing FPM or queue concurrency.

Production already uses Dockerfile builds (verified through Coolify MCP). Recent main deployments were webhook-triggered; verify auto-deploy controls before merging the bounded-worker rollout. The provisional FPM ceiling is four ondemand children and nginx uses one worker; validate memory sizing before release.
