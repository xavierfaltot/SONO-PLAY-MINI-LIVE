# SONO PLAY MINI LIVE

Server-first version of SONO PLAY MINI.

Core idea:

`FOLDER → SCAN → ANALYZE → PROGRAM → PLAY CONTINUOUSLY`

This project is independent from Pick Pocket Radio. Pick Pocket Radio is only one possible client/use case.

## V0

The first version exposes a tiny HTTP API for a remote controller (for example a Rabbit r1):

- `GET /api/library` — list tracks from the configured MUSIC source
- `GET /api/status` — current state / now playing
- `POST /api/play` — play a selected track by URL
- `POST /api/next` — advance to the next track
- `POST /api/stop` — stop playback state

Default test music source:

`https://toutvabiensepasser.com/MUSIC/`

The engine first tries to read `library.json` from that folder. If it does not exist, it tries to parse a public directory index and keep audio files.

## Run

Requires Node.js 18+.

```bash
node server.js
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

## Next

1. Validate library scan against the real `/MUSIC/` folder.
2. Add audio metadata / BPM / energy analysis.
3. Add SONO MINI energy-climb programming.
4. Add synchronized timeline for all listeners.
5. Add Rabbit remote UI: library, PLAY NOW, NEXT, AUTO, LIVE.
