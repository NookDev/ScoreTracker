# 40K Campaign Ledger — Project Context

Single-file web app for tracking personal Warhammer 40,000 (11th edition) game
results and win rate. It's a personal record-keeper for one user, not a
multiplayer/league service.

## Current state

- `index.html` is the entire app. Vanilla JS, no build step, no dependencies, no
  framework. Open it in a browser and it runs.
- Working: log a game (your faction + detachment + name + score vs opponent
  faction + detachment + name + score, plus location and date); win/loss/draw
  derived automatically from scores; a win-rate "seal" with W–L–D and totals;
  filters (your faction / opponent faction / result); a Battle Honours table
  with a **By Faction / By Detachment** toggle (win rate grouped either way); a
  battle log with delete. Data persists between sessions (see caveat below).

## Architecture

- One IIFE inside a single `<script>` tag. Key functions:
  - `render()` rebuilds the dynamic DOM on every change; `wire()` reattaches
    listeners after each render.
  - `breakdown(mode)`, `tally()`, `applyFilters()` compute the stats.
  - `factionOptions()` / `detachmentOptions(faction, sel)` build the dropdowns;
    the detachment select is repopulated live when its faction select changes.
  - `addGame()` / `delGame()` mutate state and persist.
- Persistence is abstracted behind `load(k)` / `save(k, v)` — only those two
  functions need to change to swap storage backends.
- Game record shape (result is derived, not stored):
  ```
  { id, date:"YYYY-MM-DD",
    youFaction, youName, youDetachment,
    oppFaction, oppName, oppDetachment,
    location, youScore:Number, oppScore:Number }
  ```
  youScore > oppScore = win, < = loss, == = draw.
- Storage keys: `ledger:games` (array of records) and `ledger:profile`
  (`{name, faction, detachment}`, used to prefill the form from the last game).

## CRITICAL: persistence is environment-specific

The app currently persists via `window.storage`, an API that **only exists
inside Anthropic's Claude artifact runtime**. On any normal hosted page
(`window.storage` undefined) the code falls back to an in-memory object, so
**data is lost on refresh.**

**If self-hosting, the first task is to replace the storage layer.** Two clean
options (both touch only `load()`/`save()`):

1. **`localStorage`** — works on any static hosted page, no backend. Simplest;
   the right default unless cross-device sync is needed.
2. **Small backend** (Node/PM2 on web02 behind LiteSpeed already in use) — a tiny
   JSON or SQLite store with get/set endpoints, for multi-device sync. Heavier.

Note the inverse tradeoff: `localStorage`/`sessionStorage` are **banned in the
Claude artifact runtime** but are correct for real hosting. So switching to
`localStorage` also means the app stops persisting inside Claude's artifact
preview — that's expected once it's hosted for real.

## Faction & detachment data

- Source: community repo **`BSData/wh40k-11e-mfm`** — the 11th-edition Munitorum
  Field Manual (points / detachments / enhancements). Community-maintained, **not
  official GW**, and under active development post-launch.
- Embedded as a **static snapshot** in `index.html` between the
  `// === BSDATA:START ===` and `// === BSDATA:END ===` markers
  (`FACTION_GROUPS` and `DETACHMENTS`). **Do not hand-edit between those markers.**
- To refresh after the repo changes: `python3 scripts/refresh-factions.py`
  (needs `pyyaml`). It re-downloads, regenerates, and splices the block back in.
- Coverage: 28 mainline factions, 339 detachments. The two empty "Titan Legions"
  entries are intentionally excluded. `enhancements` exist in the source but are
  not used in the app yet.

## Conventions

- **Keep it a single self-contained file** unless explicitly asked to split —
  portability is the point.
- No build tooling, no npm, no framework. Plain JS + CSS.
- Design is a deliberate "Imperial campaign ledger" look: dark gunmetal, brass
  accent, Cinzel / Oswald / Barlow type, CSS variables at `:root`. Preserve the
  aesthetic when adding UI.
- Faction names use curly apostrophes (`T'au Empire`, `Emperor's Children`) to
  match the data keys — don't normalise them or the detachment lookup breaks.

## Likely next tasks (not done yet)

- Swap persistence for hosting (above) — do this first if self-hosting.
- CSV / JSON export + import (backup, and to move data between the artifact copy
  and a hosted copy).
- Detachment filter alongside the faction/result filters.
- Per-game enhancements field (data is already in the snapshot).
- Optional: charts (win rate over time, by matchup), mission/deployment field.
