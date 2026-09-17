# Cloudflare Tunnel Setup (verified current as of September 2026)

Lets you run Acme ATS on your local XAMPP install while giving candidates
a real HTTPS link to join their interview from anywhere — no port
forwarding, no static IP. Cloudflare Tunnel connects outbound-only from
your machine to Cloudflare's edge (post-quantum encrypted, per
Cloudflare's current documentation) — nothing needs to be opened on your
router.

## 1. Install cloudflared

- **Windows**: `winget install --id Cloudflare.cloudflared`
- **macOS**: `brew install cloudflared`
- **Linux**: download the binary for your architecture from Cloudflare's
  GitHub releases and place it on your `PATH`.
- **No install at all** — if you have Docker:
  ```
  docker run --rm -it cloudflare/cloudflared tunnel --url http://host.docker.internal/acme-ats
  ```
  (use `http://host.docker.internal` instead of `localhost` so the
  container can reach XAMPP running on your actual machine).

Verify: `cloudflared --version`

## 2. Quick tunnel (fastest — good for one interview, a demo, or testing)

No Cloudflare account needed:
```
cloudflared tunnel --url http://localhost/acme-ats
```
This prints a line like:
```
Your quick Tunnel has been created! Visit it at:
https://some-random-words.trycloudflare.com
```
Two things worth knowing, straight from Cloudflare's own docs: quick
tunnels have **no uptime guarantee** and are meant for trying things out,
not production — and the URL is **only valid while that terminal window
stays open**. Closing it (or restarting the command) gives you a brand
new random URL, invalidating the old one — so if you've already sent an
interview invite email with a link, don't restart the tunnel before that
interview happens.

## 3. Named tunnel (stable URL — recommended if you'll do this more than once)

Requires a free Cloudflare account and a domain added to it.

```
cloudflared tunnel login
```
Opens a browser to authorize cloudflared and pick the domain to use.

```
cloudflared tunnel create acme-ats
cloudflared tunnel route dns acme-ats ats.yourdomain.com
cloudflared tunnel run --url http://localhost/acme-ats acme-ats
```

The tunnel name (`acme-ats`) and hostname (`ats.yourdomain.com`) only
need to be created once — after that, `cloudflared tunnel run acme-ats`
brings the same URL back every time, unlike a quick tunnel.

## 4. Update APP_URL

Every interview link, invite email, and internal redirect in this app is
built from `APP_URL` (see `config/app.php`) — never hardcoded. After
starting the tunnel, set it in `.env`:
```
APP_URL=https://<your-tunnel-url>
```
No code changes needed — the next page load picks it up. If you restart
a quick tunnel and get a new random URL, update `.env` again before
sending any new interview invites.

## 5. Keep it running

- **Quick tunnel**: keep the terminal window open for the duration of
  the interview; closing it kills the tunnel.
- **Named tunnel**: run it as a background service so it survives
  reboots and keeps the same URL indefinitely:
  ```
  cloudflared service install
  ```
  (Windows/macOS/Linux all support this; it registers `cloudflared` to
  start automatically using the config from step 3.)

## Troubleshooting

- **"Connection refused"** — confirm XAMPP's Apache is actually serving
  `http://localhost/acme-ats` before starting the tunnel; cloudflared
  can't tunnel to something that isn't running.
- **Interview links show the old URL** — `.env` wasn't updated after a
  new quick tunnel was started, or (rarely) a restart rotated the quick
  tunnel URL without you noticing — check the terminal output for the
  current URL.
- **DNS lookup failure for `trycloudflare.com` itself** — this has been
  reported as a transient Cloudflare-side or local-DNS issue; retrying
  the command, or switching your machine's DNS resolver, usually
  resolves it.
- **Camera/mic permissions blocked** — this is a browser HTTPS
  requirement; a Cloudflare Tunnel URL is HTTPS, so this should not
  happen through the tunnel. Bare `http://localhost` without a tunnel
  can trigger it for the *candidate's* side if they aren't on the same
  machine as the server.
