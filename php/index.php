<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$config = require __DIR__ . '/config.php';
$musicPath = realpath($config['music_path']) ?: $config['music_path'];
$musicUrl = rtrim($config['music_url'], '/') . '/';
$stateFile = $config['state_file'];

function out($data, $status = 200) {
  http_response_code($status);
  echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
}

function library($musicPath, $musicUrl) {
  if (!is_dir($musicPath)) return [];
  $allowed = ['mp3','m4a','aac','wav','ogg','flac'];
  $files = scandir($musicPath) ?: [];
  $tracks = [];
  foreach ($files as $file) {
    if ($file === '.' || $file === '..') continue;
    $full = $musicPath . DIRECTORY_SEPARATOR . $file;
    if (!is_file($full)) continue;
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) continue;
    $title = trim(preg_replace('/[_-]+/', ' ', pathinfo($file, PATHINFO_FILENAME)));
    $tracks[] = [
      'file' => $file,
      'title' => $title,
      'url' => $musicUrl . rawurlencode($file),
      'artist' => null,
      'bpm' => null,
      'energy' => null,
      'duration' => null
    ];
  }
  usort($tracks, fn($a,$b) => strcasecmp($a['file'], $b['file']));
  return array_values($tracks);
}

function defaultState() {
  return [
    'mode' => 'AUTO',
    'playing' => false,
    'currentIndex' => -1,
    'currentTrack' => null,
    'startedAt' => null,
    'updatedAt' => gmdate('c')
  ];
}

function readState($stateFile) {
  if (!is_file($stateFile)) return defaultState();
  $raw = file_get_contents($stateFile);
  $data = json_decode($raw, true);
  return is_array($data) ? $data : defaultState();
}

function writeState($stateFile, $state) {
  $dir = dirname($stateFile);
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function bodyJson() {
  $raw = file_get_contents('php://input');
  if (!$raw) return [];
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

$action = $_GET['action'] ?? 'root';
$tracks = null;
$state = readState($stateFile);

if ($action === 'debug') {
  $checks = [];
  foreach (($config['music_candidates'] ?? [$config['music_path']]) as $candidate) {
    $checks[] = [
      'path' => $candidate,
      'realpath' => realpath($candidate) ?: null,
      'is_dir' => is_dir($candidate),
      'readable' => is_readable($candidate),
      'entries' => is_dir($candidate) ? array_values(array_slice(scandir($candidate) ?: [], 0, 20)) : []
    ];
  }
  out([
    'documentRoot' => $_SERVER['DOCUMENT_ROOT'] ?? null,
    'scriptDir' => __DIR__,
    'selectedMusicPath' => $musicPath,
    'checks' => $checks
  ]);
}

if ($action === 'root') {
  out([
    'name' => 'SONO PLAY MINI LIVE',
    'version' => '0.1.1-php',
    'musicPath' => $musicPath,
    'musicUrl' => $musicUrl,
    'endpoints' => [
      '?action=library', '?action=status', '?action=play', '?action=next', '?action=stop', '?action=debug'
    ]
  ]);
}

if ($action === 'library') {
  $tracks = library($musicPath, $musicUrl);
  out(['source' => $musicUrl, 'count' => count($tracks), 'tracks' => $tracks]);
}

if ($action === 'status') {
  out($state + ['musicUrl' => $musicUrl]);
}

if ($action === 'play' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $tracks = library($musicPath, $musicUrl);
  $body = bodyJson();
  $index = -1;
  if (isset($body['index']) && is_int($body['index'])) $index = $body['index'];
  elseif (!empty($body['file'])) {
    foreach ($tracks as $i => $t) if ($t['file'] === $body['file']) { $index = $i; break; }
  }
  elseif (!empty($body['url'])) {
    foreach ($tracks as $i => $t) if ($t['url'] === $body['url']) { $index = $i; break; }
  }
  if ($index < 0 || $index >= count($tracks)) out(['error' => 'Track not found'], 404);
  $state['playing'] = true;
  $state['currentIndex'] = $index;
  $state['currentTrack'] = $tracks[$index];
  $state['startedAt'] = gmdate('c');
  $state['updatedAt'] = $state['startedAt'];
  writeState($stateFile, $state);
  out($state);
}

if ($action === 'next' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $tracks = library($musicPath, $musicUrl);
  if (!$tracks) out(['error' => 'Library is empty'], 409);
  $next = ($state['currentIndex'] ?? -1) < 0 ? 0 : (($state['currentIndex'] + 1) % count($tracks));
  $state['playing'] = true;
  $state['currentIndex'] = $next;
  $state['currentTrack'] = $tracks[$next];
  $state['startedAt'] = gmdate('c');
  $state['updatedAt'] = $state['startedAt'];
  writeState($stateFile, $state);
  out($state);
}

if ($action === 'stop' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $state['playing'] = false;
  $state['updatedAt'] = gmdate('c');
  writeState($stateFile, $state);
  out($state);
}

out(['error' => 'Not found'], 404);
