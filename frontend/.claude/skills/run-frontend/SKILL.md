---
name: run-frontend
description: Launch and drive the ERP React frontend (Vite dev server) in a headless browser to visually verify UI changes. Use when asked to run, start, or screenshot the frontend, or to confirm a frontend change works in the real app.
---

The frontend is a React SPA served by Vite inside the `frontend` docker
compose service (`node:20-alpine` + system Chromium, built from
`frontend/docker/Dockerfile`). An agent can't open a browser window, so
"run the app" here means driving headless Chromium against the Vite dev
server via the Playwright REPL at `frontend/.claude/skills/run-frontend/driver.mjs`.

All paths below are relative to the repo root
(`/home/szolke/projects/erp-system`).

## Prerequisites

The `frontend` service image already has everything needed (system
Chromium + `playwright-core`, installed via `npm install` on container
start). Just make sure the stack is up:

```bash
docker compose up -d
docker compose ps   # frontend, laravel.test, pgsql, redis should all be Up
```

If `frontend/docker/Dockerfile` or `frontend/package.json` changed,
rebuild first: `docker compose up -d --build frontend`.

## Run (agent path)

```bash
docker compose exec frontend node .claude/skills/run-frontend/driver.mjs
```

Wrap in tmux for interactive, iterative use (recommended — avoids
relaunching Chromium on every command):

```bash
tmux new-session -d -s fe -x 200 -y 50
tmux send-keys -t fe 'docker compose exec frontend node .claude/skills/run-frontend/driver.mjs' Enter
timeout 20 bash -c 'until tmux capture-pane -t fe -p | grep -q "driver>"; do sleep 0.2; done'
tmux send-keys -t fe 'launch' Enter
timeout 20 bash -c 'until tmux capture-pane -t fe -p | grep -q "launched"; do sleep 0.2; done'
tmux send-keys -t fe 'login test@example.com password' Enter
timeout 20 bash -c 'until tmux capture-pane -t fe -p | grep -qE "login → (OK|TIMEOUT)"; do sleep 0.2; done'
tmux send-keys -t fe 'nav /company' Enter
tmux send-keys -t fe 'ss company' Enter
timeout 10 bash -c 'until tmux capture-pane -t fe -p | grep -q "screenshot:"; do sleep 0.2; done'
tmux capture-pane -t fe -p
```

Screenshots land in `/tmp/shots/` **inside the container**. Copy one out
to inspect it:

```bash
docker compose cp frontend:/tmp/shots/company.png /tmp/shots-company.png
```

Then actually look at the file with the Read tool — a blank or
login-screen screenshot means something upstream failed.

### Commands

| command | what it does |
|---|---|
| `launch` | launch headless Chromium, open a page |
| `nav <path or url>` | navigate; bare path resolves against `BASE_URL` (default `http://localhost:5173`, the in-container Vite port — **not** 5174) |
| `login <email> <password>` | fills and submits the login form, waits for `.login-wrap` to detach |
| `ss [name]` | full-page screenshot → `/tmp/shots/<name>.png` |
| `screenshot-element <css-sel> [name]` | crop screenshot to one element |
| `click <css-sel>` | Playwright `.click()` |
| `click-text <text>` | click first element containing text |
| `fill <css-sel> <value>` | fill a controlled input (goes through Playwright's input pipeline — required for React `onChange` to fire) |
| `type <text>` / `press <key>` | keyboard input |
| `wait-for <css-sel>` | wait for element visible, 15s timeout |
| `wait-for-text <text>` | wait for text to appear, 15s timeout |
| `eval <js>` | evaluate in the page, print JSON |
| `text [css-sel]` | print innerText (body if no selector) |
| `url` | print current page URL |
| `quit` | close browser, exit |

Console errors and page errors print automatically as they occur
(`[console error] ...` / `[page error] ...`) — check the pane output,
not just the screenshot, before declaring success.

## Demo login

`test@example.com` / `password` — superadmin, active company "Demo Kft."
See `docs/progress.md` → *Demo bejelentkezés*.

## Gotchas

- **React controlled inputs.** Don't `eval el.value = '…'` — it doesn't
  fire React's `onChange`. Use `fill` (it goes through Playwright's real
  input pipeline).
- **`BASE_URL` is the in-container port (5173), not the host-mapped one
  (5174).** The driver runs *inside* the `frontend` container via
  `docker compose exec`, so it talks to Vite directly.
- **Alpine + Playwright bundled Chromium download is broken** (musl
  libc). The Dockerfile installs the **system** `chromium` package and
  sets `PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1` +
  `PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH=/usr/bin/chromium-browser` — the
  driver launches with `executablePath` pointing at that binary. If this
  breaks after an Alpine/Chromium upgrade, check
  `docker compose exec frontend which chromium-browser`.
- **Hungarian/German diacritics in screenshots** need a font with those
  glyphs — the Dockerfile installs `font-noto` for this.
- **First `nav` after `launch` can be slow** (Vite compiles routes on
  demand). `wait-for` / `wait-for-text` handle it; don't add blind
  `sleep`.

## Run (human path)

```bash
# open http://localhost:5174 in a real browser — useless for an agent,
# but this is what a human developer does instead of the driver.
```
