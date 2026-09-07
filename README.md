# SONO PLAY MINI LIVE

Server-first version of SONO PLAY MINI.

Core idea:

`FOLDER → SCAN → ANALYZE → PROGRAM → PLAY CONTINUOUSLY → STREAM`

SONO PLAY MINI LIVE is the engine. **Pick Pocket Radio is the first live deployment / featured radio use case.**

## SONO PLAY MINI LIVE × PICK POCKET RADIO

Current deployment in Berlin:

- Public radio stream: `https://radio.pickpocketradio.org/stream.mp3`
- Icecast + Liquidsoap on the SONO PLAY MINI LIVE VPS
- Stateful queue to prevent the same current item from being repeatedly re-enqueued
- SONO MINI programming based on BPM / energy analysis
- 159-track program in the current test library
- Private live console with actual engine state, current / next, queue position and listener monitoring
- Public listener count and listener peak from Icecast
- Listener-history graph in the console
- `≫ NEXT` live control
- Audio Drop Zone candidate for adding tracks to the live queue
- Rabbit control candidate: compact listener/current/next view + `≫ NEXT`, without Drop Zone

The collaboration identity is **SONO PLAY MINI LIVE × PICK POCKET RADIO — MIND YOUR CULTURE**.

> The live console, Drop Zone and Rabbit control are being validated against the stateful test engine before the control path is moved to the public production stream.

## Architecture

```text
MUSIC LIBRARY
     ↓
SCAN / ANALYZE
BPM + ENERGY + DURATION
     ↓
SONO PROGRAM
stateful queue
     ↓
LIQUIDSOAP
     ↓
ICECAST
     ↓
https://radio.pickpocketradio.org/stream.mp3
     ↓
PICK POCKET RADIO

CONTROL / MONITORING
     ├── LIVE CONSOLE
     │    ├── Icecast / mount / queue health
     │    ├── public listeners + peak
     │    ├── listener history graph
     │    ├── current / next
     │    ├── ≫ NEXT
     │    └── DROP AUDIO candidate
     │
     └── RABBIT CONTROL candidate
          ├── listeners + peak
          ├── current / next
          └── ≫ NEXT
```

## Live console deployment

The control layer runs separately from the public audio mount. The current validation architecture uses:

```text
nginx / HTTPS
      ↓
private SONO console
      ↓
Liquidsoap telnet control
      ↓
stateful test source
      ↓
/next-test.mp3
```

This separation allows NEXT, queue behavior, monitoring and live drops to be tested without destabilizing `/stream.mp3`.

## V0 API

The first version exposes a tiny HTTP API:

- `GET /api/library` — list tracks from the configured MUSIC source
- `GET /api/status` — current state / now playing
- `POST /api/play` — select a track by URL, filename or index
- `POST /api/next` — advance to the next track
- `POST /api/stop` — stop playback state

Default test music source:

`https://toutvabiensepasser.com/MUSIC/`

The engine first tries to read `library.json` from that folder. If it does not exist, it tries to parse a public directory index and keep audio files.

## Run

Requires Node.js 18+.

```bash
npm start
```

Development mode:

```bash
npm run dev
```

Optional environment variables:

```bash
PORT=8787
MUSIC_BASE_URL=https://toutvabiensepasser.com/MUSIC/
```

Then open:

`http://localhost:8787/api/library`

## library.json format

If directory listing is disabled on the web server, put a `library.json` file inside `/MUSIC/`:

```json
[
  {"file":"track-01.mp3"},
  {"file":"track-02.mp3"}
]
```

Full URLs are also accepted:

```json
[
  {"file":"track-01.mp3","url":"https://example.com/MUSIC/track-01.mp3"}
]
```

## Current validation order

1. Stateful queue — working on `/next-test.mp3`.
2. Live console — online and displaying Icecast, public listeners, current/next and queue state.
3. Validate real `≫ NEXT` behavior.
4. Validate listener-history persistence / graph.
5. Validate live Drop Zone behavior.
6. Validate compact Rabbit control.
7. Move validated control path to the production stream.
8. Add selective CUT / FADE / MIX transitions.

The public stream remains isolated from experimental control changes until each candidate is validated.
