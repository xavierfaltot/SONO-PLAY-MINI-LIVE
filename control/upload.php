<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$root = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
$music = $root . '/MUSIC';
if (!is_dir($music) || !is_writable($music)) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'MUSIC folder unavailable']); exit; }
if (empty($_FILES['tracks'])) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'No files']); exit; }
$names=(array)$_FILES['tracks']['name']; $tmp=(array)$_FILES['tracks']['tmp_name']; $err=(array)$_FILES['tracks']['error']; $size=(array)$_FILES['tracks']['size'];
$out=[]; $max=80*1024*1024;
foreach($names as $i=>$original){
  if(($err[$i]??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) continue;
  if(($size[$i]??0)>$max) continue;
  $name=basename((string)$original);
  $name=preg_replace('/[^A-Za-z0-9._()\- ]/u','_', $name);
  if(!preg_match('/\.mp3$/i',$name)) continue;
  $mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp[$i]);
  if(!in_array($mime,['audio/mpeg','audio/mp3','application/octet-stream'],true)) continue;
  $dest=$music.'/'.$name;
  if(file_exists($dest)){ $p=pathinfo($name); $dest=$music.'/'.$p['filename'].'_'.date('Ymd_His').'.'.($p['extension']??'mp3'); }
  if(move_uploaded_file($tmp[$i],$dest)) $out[]=basename($dest);
}
if(!$out){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>'No valid MP3 uploaded']); exit; }
echo json_encode(['ok'=>true,'files'=>$out,'state'=>'UPLOADED_PENDING_ANALYSIS','note'=>'Tracks are uploaded; analysis/activation is a separate safe step.'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
