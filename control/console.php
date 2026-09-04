<?php
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
// Shared hosting cannot read the VPS systemd journal directly.
// Configure SONO_CONSOLE_URL to the authenticated/read-only VPS log endpoint.
$url = getenv('SONO_CONSOLE_URL') ?: '';
if ($url === '') {
  http_response_code(503);
  echo "SONO CONSOLE — VPS bridge not connected yet.\n";
  echo "Stream remains independent and untouched.\n";
  exit;
}
$ctx=stream_context_create(['http'=>['timeout'=>3,'ignore_errors'=>true]]);
$data=@file_get_contents($url,false,$ctx);
if($data===false){http_response_code(502);echo "SONO CONSOLE — VPS unavailable\n";exit;}
echo $data;
