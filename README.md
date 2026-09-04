# SONO PLAY MINI LIVE

Server-first version of SONO PLAY MINI.

Core idea:

`FOLDER → SCAN → ANALYZE → PROGRAM → PLAY CONTINUOUSLY → STREAM`

This project is independent from Pick Pocket Radio. Pick Pocket Radio is only one possible client/use case.

## V0

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

## Build order

1. Validate library scan against the real `/MUSIC/` folder.
2. Add audio metadata, duration, BPM and energy analysis.
3. Add SONO MINI energy-climb programming.
4. Add a real synchronized playback timeline.
5. Generate a continuous radio stream URL.
6. Stress-test the stream and recovery behavior.
7. Only after the engine and stream are stable, build external control interfaces.

The existing Pick Pocket Radio player remains untouched until the new stream is proven stable.
