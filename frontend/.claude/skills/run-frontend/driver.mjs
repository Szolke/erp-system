// REPL driver for the ERP frontend (Vite dev server) via headless system-Chromium.
// Run inside the `frontend` docker compose service. Designed for agents: wrap in
// tmux, send-keys commands, capture-pane output.
import { chromium } from 'playwright-core'
import * as readline from 'node:readline'
import * as fs from 'node:fs'
import * as path from 'node:path'

const SHOT_DIR = process.env.SCREENSHOT_DIR || '/tmp/shots'
fs.mkdirSync(SHOT_DIR, { recursive: true })

const EXECUTABLE_PATH = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || '/usr/bin/chromium-browser'
const BASE_URL = process.env.BASE_URL || 'http://localhost:5173'
// A frontend bundle 'http://localhost' (port 80) alá van sütve API-baseURL-ként
// (compose.yaml VITE_API_URL) — kívülről ez helyesen a hostra mutat, de a driver
// EBBEN a konténerben fut, ahol "localhost" saját magát jelenti, nem a laravel.test-et.
// DNS-szintű átirányítás kell (Chromium --host-resolver-rules), NEM URL-átírás —
// utóbbi a Set-Cookie válaszokat "laravel.test" hosthoz kötné, és a frontend
// document.cookie-alapú XSRF-token-olvasása (api/client.js) "localhost"-ot vár,
// így néma 419 CSRF-hibát okozna.
import { execSync } from 'node:child_process'
const API_HOST = process.env.API_HOST || 'laravel.test'
function resolveApiHostIp() {
  try {
    return execSync(`getent hosts ${API_HOST}`).toString().trim().split(/\s+/)[0]
  } catch {
    console.log(`WARN: nem sikerült feloldani a(z) ${API_HOST} nevet — az API-hívások valószínűleg elbuknak`)
    return null
  }
}

let browser = null
let page = null

const COMMANDS = {
  async launch() {
    if (browser) return console.log('already launched')
    const apiIp = resolveApiHostIp()
    const hostResolverArgs = apiIp ? [`--host-resolver-rules=MAP localhost:80 ${apiIp}:80`] : []
    browser = await chromium.launch({
      executablePath: EXECUTABLE_PATH,
      args: ['--no-sandbox', '--disable-gpu', ...hostResolverArgs],
    })
    const context = await browser.newContext({ viewport: { width: 1400, height: 900 } })
    page = await context.newPage()
    page.on('console', (msg) => { if (msg.type() === 'error') console.log('[console error]', msg.text()) })
    page.on('pageerror', (err) => console.log('[page error]', err.message))
    console.log('launched.')
  },

  async nav(url) {
    if (!page) return console.log('ERROR: launch first')
    const target = url && url.startsWith('http') ? url : `${BASE_URL}${url || '/'}`
    await page.goto(target, { waitUntil: 'domcontentloaded', timeout: 30_000 })
    console.log('nav →', target)
  },

  // login <email> <password> — kitölti és beküldi a login formot, megvárja hogy
  // eltűnjön a login-wrap (sikeres bejelentkezés → redirect a főoldalra).
  async login(args) {
    if (!page) return console.log('ERROR: launch first')
    const [email, password] = args.split(/\s+/)
    await page.goto(BASE_URL, { waitUntil: 'domcontentloaded' })
    await page.fill('input[type="email"]', email)
    await page.fill('input[type="password"]', password)
    await page.click('button[type="submit"], form button')
    try {
      await page.waitForSelector('.login-wrap', { state: 'detached', timeout: 15_000 })
      console.log('login → OK')
    } catch {
      console.log('login → TIMEOUT (still on login page?)')
    }
  },

  async ss(name) {
    if (!page) return console.log('ERROR: launch first')
    const f = path.join(SHOT_DIR, (name || `ss-${Date.now()}`) + '.png')
    await page.screenshot({ path: f, fullPage: true })
    console.log('screenshot:', f)
  },

  async 'screenshot-element'(args) {
    if (!page) return console.log('ERROR: launch first')
    const sp = args.indexOf(' ')
    const sel  = sp === -1 ? args : args.slice(0, sp)
    const name = sp === -1 ? undefined : args.slice(sp + 1)
    const f = path.join(SHOT_DIR, (name || `ss-${Date.now()}`) + '.png')
    try { await page.locator(sel).first().screenshot({ path: f }); console.log('screenshot:', f) }
    catch (e) { console.log('screenshot-element → ERROR:', e.message) }
  },

  async click(sel) {
    if (!page) return console.log('ERROR: launch first')
    try { await page.click(sel, { timeout: 10_000 }); console.log('click', sel, '→ OK') }
    catch (e) { console.log('click', sel, '→ ERROR:', e.message) }
  },

  async 'click-text'(text) {
    if (!page) return console.log('ERROR: launch first')
    try {
      await page.getByText(text, { exact: false }).first().click({ timeout: 10_000 })
      console.log('click-text', JSON.stringify(text), '→ OK')
    } catch (e) { console.log('click-text', JSON.stringify(text), '→ ERROR:', e.message) }
  },

  async fill(args) {
    if (!page) return console.log('ERROR: launch first')
    const sp = args.indexOf(' ')
    const sel = sp === -1 ? args : args.slice(0, sp)
    const val = sp === -1 ? '' : args.slice(sp + 1)
    try { await page.fill(sel, val, { timeout: 10_000 }); console.log('fill', sel, '→ OK') }
    catch (e) { console.log('fill', sel, '→ ERROR:', e.message) }
  },

  async type(text)  { if (page) await page.keyboard.type(text, { delay: 20 }) },
  async press(key)  { if (page) await page.keyboard.press(key) },

  async 'wait-for'(sel) {
    if (!page) return console.log('ERROR: launch first')
    try { await page.waitForSelector(sel, { timeout: 15_000, state: 'visible' }); console.log('found:', sel) }
    catch { console.log('TIMEOUT:', sel) }
  },

  async 'wait-for-text'(text) {
    if (!page) return console.log('ERROR: launch first')
    try { await page.getByText(text, { exact: false }).first().waitFor({ timeout: 15_000 }); console.log('found text:', text) }
    catch { console.log('TIMEOUT text:', text) }
  },

  async eval(expr) {
    if (!page) return console.log('ERROR: launch first')
    try { console.log(JSON.stringify(await page.evaluate(expr))) }
    catch (e) { console.log('ERROR:', e.message) }
  },

  async text(sel) {
    if (!page) return console.log('ERROR: launch first')
    console.log(await page.evaluate(
      (s) => (s ? document.querySelector(s) : document.body)?.innerText ?? '(null)',
      sel || null,
    ))
  },

  async url() {
    if (!page) return console.log('ERROR: launch first')
    console.log(page.url())
  },

  async quit() { if (browser) await browser.close().catch(() => {}); browser = null; page = null },
  help() { console.log('commands:', Object.keys(COMMANDS).join(', ')) },
}

const stdin = fs.createReadStream(null, { fd: fs.openSync('/dev/stdin', 'r') })
const rl = readline.createInterface({ input: stdin, output: process.stdout, prompt: 'driver> ' })

rl.on('line', async (line) => {
  const [cmd, ...rest] = line.trim().split(/\s+/)
  if (!cmd) return rl.prompt()
  const fn = COMMANDS[cmd]
  if (!fn) { console.log('unknown:', cmd, '— try: help'); return rl.prompt() }
  try { await fn(rest.join(' ')) } catch (e) { console.log('ERROR:', e.message) }
  if (cmd === 'quit') { rl.close(); process.exit(0) }
  rl.prompt()
})
rl.on('close', async () => { await COMMANDS.quit(); process.exit(0) })

console.log('ERP frontend driver — "help" for commands, "launch" to start')
rl.prompt()
