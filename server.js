const http = require('http');
const { URL } = require('url');

const PORT = Number(process.env.PORT || 8787);
const MUSIC_BASE_URL = (process.env.MUSIC_BASE_URL || 'https://toutvabiensepasser.com/MUSIC/').replace(/\/?$/, '/');

let state = {
  mode: 'AUTO',
  playing: false,
  currentIndex: -1,
  currentTrack: null,
  startedAt: null,
  updatedAt: new Date().toISOString()
};

let libraryCache = { tracks: [], fetchedAt: 0 };
const CACHE_MS = 30_000;

function json(res, status, payload) {
  const body = JSON.stringify(payload, null, 2);
  res.writeHead(status, {
    'Content-Type': 'application/json; charset=utf-8',
    'Content-Length': Buffer.byteLength(body),
    'Access-Control-Allow-Origin': '*',
    'Access-Control-Allow-Headers': 'Content-Type',
    'Access-Control-Allow-Methods': 'GET,POST,OPTIONS'
  });
  res.end(body);
}

function normalizeTrack(item) {
  if (typeof item === 'string') item = { file: item };
  const file = item.file || (item.url ? decodeURIComponent(new URL(item.url).pathname.split('/').pop()) : '');
  const url = item.url || new URL(encodeURIComponent(file).replace(/%2F/g, '/'), MUSIC_BASE_URL).href;
  const title = item.title || file.replace(/\.[^.]+$/, '').replace(/[_-]+/g, ' ').trim();
  return { file, title, url, artist: item.artist || null, bpm: item.bpm || null, energy: item.energy || null, duration: item.duration || null };
}

async function fetchLibrary() {
  if (Date.now() - libraryCache.fetchedAt < CACHE_MS && libraryCache.tracks.length) return libraryCache.tracks;

  const manifestUrl = new URL('library.json', MUSIC_BASE_URL).href;
  try {
    const r = await fetch(manifestUrl, { headers: { 'User-Agent': 'SONO-PLAY-MINI-LIVE/0.1' } });
    if (r.ok) {
      const data = await r.json();
      const list = Array.isArray(data) ? data : (Array.isArray(data.tracks) ? data.tracks : []);
      const tracks = list.map(normalizeTrack).filter(t => /\.(mp3|m4a|aac|wav|ogg|flac)$/i.test(t.file));
      libraryCache = { tracks, fetchedAt: Date.now() };
      return tracks;
    }
  } catch (_) {}

  const r = await fetch(MUSIC_BASE_URL, { headers: { 'User-Agent': 'SONO-PLAY-MINI-LIVE/0.1' } });
  if (!r.ok) throw new Error(`Cannot read MUSIC folder (${r.status})`);
  const html = await r.text();
  const hrefs = [...html.matchAll(/href=["']([^"']+)["']/gi)].map(m => m[1]);
  const seen = new Set();
  const tracks = [];
  for (const href of hrefs) {
    let u;
    try { u = new URL(href, MUSIC_BASE_URL); } catch (_) { continue; }
    const file = decodeURIComponent(u.pathname.split('/').pop() || '');
    if (!/\.(mp3|m4a|aac|wav|ogg|flac)$/i.test(file)) continue;
    if (seen.has(u.href)) continue;
    seen.add(u.href);
    tracks.push(normalizeTrack({ file, url: u.href }));
  }
  libraryCache = { tracks, fetchedAt: Date.now() };
  return tracks;
}

async function readBody(req) {
  return new Promise((resolve, reject) => {
    let data = '';
    req.on('data', chunk => { data += chunk; if (data.length > 1e6) req.destroy(); });
    req.on('end', () => {
      if (!data) return resolve({});
      try { resolve(JSON.parse(data)); } catch (e) { reject(e); }
    });
    req.on('error', reject);
  });
}

function setCurrent(track, index) {
  state.playing = true;
  state.currentIndex = index;
  state.currentTrack = track;
  state.startedAt = new Date().toISOString();
  state.updatedAt = state.startedAt;
}

const server = http.createServer(async (req, res) => {
  if (req.method === 'OPTIONS') {
    res.writeHead(204, {
      'Access-Control-Allow-Origin': '*',
      'Access-Control-Allow-Headers': 'Content-Type',
      'Access-Control-Allow-Methods': 'GET,POST,OPTIONS'
    });
    return res.end();
  }

  const url = new URL(req.url, `http://${req.headers.host || 'localhost'}`);

  try {
    if (req.method === 'GET' && url.pathname === '/') {
      return json(res, 200, { name: 'SONO PLAY MINI LIVE', version: '0.1.0', musicBaseUrl: MUSIC_BASE_URL, endpoints: ['/api/library','/api/status','/api/play','/api/next','/api/stop'] });
    }

    if (req.method === 'GET' && url.pathname === '/api/library') {
      const tracks = await fetchLibrary();
      return json(res, 200, { source: MUSIC_BASE_URL, count: tracks.length, tracks });
    }

    if (req.method === 'GET' && url.pathname === '/api/status') {
      return json(res, 200, { ...state, musicBaseUrl: MUSIC_BASE_URL });
    }

    if (req.method === 'POST' && url.pathname === '/api/play') {
      const body = await readBody(req);
      const tracks = await fetchLibrary();
      let index = -1;
      if (Number.isInteger(body.index)) index = body.index;
      else if (body.url) index = tracks.findIndex(t => t.url === body.url);
      else if (body.file) index = tracks.findIndex(t => t.file === body.file);
      if (index < 0 || index >= tracks.length) return json(res, 404, { error: 'Track not found' });
      setCurrent(tracks[index], index);
      return json(res, 200, state);
    }

    if (req.method === 'POST' && url.pathname === '/api/next') {
      const tracks = await fetchLibrary();
      if (!tracks.length) return json(res, 409, { error: 'Library is empty' });
      const nextIndex = state.currentIndex < 0 ? 0 : (state.currentIndex + 1) % tracks.length;
      setCurrent(tracks[nextIndex], nextIndex);
      return json(res, 200, state);
    }

    if (req.method === 'POST' && url.pathname === '/api/stop') {
      state.playing = false;
      state.updatedAt = new Date().toISOString();
      return json(res, 200, state);
    }

    return json(res, 404, { error: 'Not found' });
  } catch (error) {
    return json(res, 500, { error: error.message || 'Server error' });
  }
});

server.listen(PORT, () => {
  console.log(`SONO PLAY MINI LIVE listening on http://localhost:${PORT}`);
  console.log(`MUSIC source: ${MUSIC_BASE_URL}`);
});
