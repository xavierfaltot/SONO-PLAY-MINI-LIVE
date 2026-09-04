<?php
$root = dirname(__DIR__);
$candidates = [
  __DIR__ . '/../MUSIC',
  __DIR__ . '/MUSIC',
  $root . '/MUSIC',
  dirname($root) . '/MUSIC',
  $_SERVER['DOCUMENT_ROOT'] . '/MUSIC',
];

$musicPath = null;
foreach ($candidates as $candidate) {
  if ($candidate && is_dir($candidate)) {
    $musicPath = $candidate;
    break;
  }
}

return [
  'music_path' => $musicPath ?: __DIR__ . '/../MUSIC',
  'music_url' => 'https://toutvabiensepasser.com/MUSIC/',
  'state_file' => __DIR__ . '/state.json',
  'music_candidates' => $candidates,
];
