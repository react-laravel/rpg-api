# RPG B hosted deployment

This proposal builds on GitHub-hosted Ubuntu24.04 and sends one locked production runtime to B. No A runner, deploy.php, PM2 or notification hook is used. Each repository gets its own forced-command account and independent encrypted SSH key/known-host Secrets. Only its own program can be updated; the key has no terminal, forwarding, direct private ENV/data reads, other-site writes or general service management.

Frontend: rpg.dogeow.com keeps its original root behavior 200. Same-origin /api and /sanctum use the existing internal 8001 on B; do not remove that Nginx listener as a preview. The original absent /api/auth/csrf route stays 404. WebSocket host is rpg.dogeow.com. Public build settings are an explicit allowlist of original names; no runtime credentials are sent to GitHub.

API: package full resources and optional lang. Root installs a fixed FPM-only ExecStartPre that runs as the dedicated app UID, reads the migration ledger without DDL, rebuilds private caches, compiles Blade and writes a private release marker. The publisher restarts FPM first, then queue and Reverb; console workers check the marker and never prepare caches concurrently. A pending migration refuses startup rather than changing the schema. The publish key does not manage scheduler/ENV/DB/Redis.

Code trees only are transferred to the deploy owner without following links; root retains the base/current gateway/home/.ssh/units/sudoers. Shared ENV, cache and user data keep their owners/modes/content. Every real code file must have one hardlink; only the fixed .env/storage/public-storage or .next/cache bindings can point outside code. Read-only B proof and protected-state equality checks precede key activation.

Upload paths, size limits and SHA hashes are verified, at least1GiB root space is preserved. Current changes atomically; startup failure returns code to prior current before removing the rejected package. Successful publish removes the old runtime version, preserving only one. No database DDL, Redis flush, user-file replacement or unrelated service restart.

First infrastructure commit uses [skip ci], then hosted validate_only=true must pass before a real publication of unchanged business code. Actual new key positive/negative checks, own-site HTTPS/anonymous auth/CSRF/WS/log/resource verification and a recovery tag follow. Runtime business ENV/data never enter code bundles or GitHub. No new persistent access, Secrets, service hook or remote ref is installed merely by preparing this proposal.
