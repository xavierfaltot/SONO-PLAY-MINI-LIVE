<?php
$documentRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');

return [
  'music_path' => $documentRoot . '/MUSIC',
  'music_url' => 'https://toutvabiensepasser.com/MUSIC/',
  'state_file' => __DIR__ . '/state.json',
];
