# 40K Campaign Ledger

A single-file web app for tracking your Warhammer 40,000 (11th edition) game
results and win rate — by faction and by detachment.

## Run it

Open `index.html` in a browser. That's it — no build, no install.

## Files

- `index.html` — the whole app (vanilla JS + CSS, self-contained).
- `CLAUDE.md` — project context for Claude Code (architecture, the storage
  caveat, data source, conventions, and the backlog). Read this first.
- `scripts/refresh-factions.py` — regenerates the embedded faction/detachment
  data from the BSData 11e repo. Run `pip install pyyaml` then
  `python3 scripts/refresh-factions.py`.

## Heads-up before hosting

Data currently persists via an API that only exists inside Claude's artifact
preview. To host this anywhere real, the storage layer needs swapping to
`localStorage` (or a small backend). See the "CRITICAL" section in `CLAUDE.md`.

## Data

Faction and detachment names come from the community
[`BSData/wh40k-11e-mfm`](https://github.com/BSData/wh40k-11e-mfm) repo (the 11th-
edition Munitorum Field Manual). Community-maintained, not official GW.
