# EPIC-18 — Production Browser E2E Evidence (G4)

**Gate ID:** G4  
**Date (UTC):** 2026-09-06  
**Operator:** Cursor deployment session (browser E2E)  
**Production URL:** `https://rusbingo.online/`  
**Production SHA:** `b3531d14871f3963c8f4489d85fa1551fbfaf2af` (`b3531d1`)  
**Browser:** Cursor embedded Chromium — Chrome `144.0.7559.236` (Electron `40.10.3`, Windows NT 10.0)  
**VPS:** `186.246.50.81`

**Conclusion:** **EPIC-18 COMPLETE — G4 PASS**

---

## A. SHA verification (mandatory)

Verified on VPS before browser testing:

```text
git -c safe.directory=/opt/lotto-game -C /opt/lotto-game rev-parse HEAD
→ b3531d14871f3963c8f4489d85fa1551fbfaf2af
```

| Check | Result |
|-------|--------|
| Expected SHA | `b3531d14871f3963c8f4489d85fa1551fbfaf2af` |
| VPS HEAD | **match** |
| Browser tested local build | **no** — only `https://rusbingo.online/` |

---

## B. Test identity

| Field | Value |
|-------|--------|
| Production URL | `https://rusbingo.online/` |
| WebSocket URL (resolved) | `wss://rusbingo.online/ws` |
| Exact SHA | `b3531d14871f3963c8f4489d85fa1551fbfaf2af` |
| Browser / version | Chrome 144.0.7559.236 (Cursor/Electron 40.10.3, Win32) |
| Test timestamp (UTC) | 2026-09-06 ~06:15–06:17 |
| Operator | Cursor browser E2E session |
| Disposable test account | `epic18g4e2e` (username only — password not recorded) |

---

## C. Scenario matrix

| Scenario | Result | Evidence |
|----------|--------|----------|
| HTTPS page | **PASS** | `https://rusbingo.online/` loaded HTTP/2 200; no certificate warning in browser |
| Frontend bootstrap | **PASS** | Title `Russian Lotto Bingo`; CSS `style.css?v=25`; JS bundles `sound.js`, `i18n.js`, `ws.js`, `ui.js`, `app.js`; meta `lotto-ws-port=""`, `lotto-ws-path="/ws"` |
| WSS handshake | **PASS** | Browser `WebSocket('wss://rusbingo.online/ws')`: `onopen` readyState=1; Origin `https://rusbingo.online` accepted; no mixed-content resources |
| WebSocket protocol bootstrap | **PASS** | First server frame: `{"type":"hello","protocol_version":…,"server_time":…}` (keys redacted) |
| Registration | **PASS** | `register` action → logged in as `epic18g4e2e`, 500 coins, lobby UI |
| Login / logout | **PASS** | Logout cleared `LottoApp.state.user` and session token; login restored session |
| Room create / join | **PASS** | Created room `#1` (waiting); host auto-joined; room listed in lobby |
| Game start / minimum interaction | **PASS** | `Играть с ботом` → status `playing`; 1 player card rendered; drew barrel (number 54 recorded in state); server messages updated UI |
| Reload | **PASS** | Full page reload → session restored from storage; returned to in-game room `#1` with coherent state |
| WebSocket reconnect | **PASS** | After reload, user online, game UI active, `hasToken=true`, no stuck reconnect overlay |
| Error path | **PASS** | Wrong password login → toast `Неверный логин или пароль`; user remained unauthenticated |

---

## D. Console / network findings

| Finding | Result |
|---------|--------|
| Blocking `console.error` during session | **none** captured |
| Mixed-content (`http:` on HTTPS page) | **none** |
| WebSocket URL resolution (`LottoWS.resolveWsUrl()`) | `wss://rusbingo.online/ws` |
| WSS upgrade | Successful (`open` event, readyState OPEN) |
| Server hello | `type: hello` received |
| Invalid-origin test | Not re-run in browser (covered in EPIC-17 G3 loopback); production Origin accepted |

**Screenshot (local operator artifact, not in Git):** game screen with bank, player list, bingo card, drawn number — `page-2026-09-06T06-16-15-591Z.png` under operator temp screenshots.

---

## E. Defects

| ID | Severity | Description | Action |
|----|----------|-------------|--------|
| — | — | No release-blocking defects observed during G4 | — |

---

## F. Cleanup

| Item | Action | Result |
|------|--------|--------|
| Disposable user `epic18g4e2e` | `DELETE FROM users WHERE username='epic18g4e2e'` on VPS `game.db` | **removed** (1 row) |
| In-memory room `#1` / active game | `systemctl restart lotto-server` after user delete | **cleared** (rooms are RAM-only) |
| Remaining production users | `admin`, `epic17_92c19235` (from EPIC-17 G5 — pre-existing) | **unchanged** |
| Admin account | not used for E2E | **unchanged** |
| Passwords / tokens | never written to this document or Git | **compliant** |

---

## G. Gate status (this EPIC)

| Gate | Status |
|------|--------|
| **G4** | **PASS** |
| G0 | PASS (H1) |
| G1 | PASS (EPIC-17) |
| G2 | PASS (EPIC-17) |
| G3 | PASS (EPIC-17) |
| G5 | PASS (EPIC-17) |
| G6–G11 | **unchanged** (not evaluated in EPIC-18) |

---

## H. Verification metadata

| Check | Result |
|-------|--------|
| Runtime application code changed | **no** |
| Production nginx/TLS/DNS changed | **no** |
| Deployment operation during E2E | **cleanup only** (SQLite user delete + service restart) |
| V1.0 READY declared | **no** |

---

## I. Related documents

- [`docs/RELEASE_CONTRACT_V1.md`](RELEASE_CONTRACT_V1.md)
- [`docs/ROADMAP_V1_PRODUCTION.md`](ROADMAP_V1_PRODUCTION.md)
- [`docs/ADMIN_VPS_DEPLOY.md`](ADMIN_VPS_DEPLOY.md)
- [`docs/EPIC_17_PRODUCTION_DEPLOYMENT.md`](EPIC_17_PRODUCTION_DEPLOYMENT.md)
