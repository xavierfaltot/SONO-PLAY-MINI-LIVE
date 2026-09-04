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

function out($data, $status = 200) { http_response_code($status); echo json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); exit; }

function mp3Duration($file) {
  $size = @filesize($file); if (!$size) return null;
  $h = @fopen($file, 'rb'); if (!$h) return null;
  $head = fread($h, 65536); fclose($h);
  $len = strlen($head);
  $bitrates = [1=>32,2=>40,3=>48,4=>56,5=>64,6=>80,7=>96,8=>112,9=>128,10=>160,11=>192,12=>224,13=>256,14=>320];
  for ($i=0; $i<$len-4; $i++) {
    $b1=ord($head[$i]); $b2=ord($head[$i+1]); $b3=ord($head[$i+2]);
    if ($b1===0xFF && (($b2 & 0xE0)===0xE0)) {
      $version=($b2>>3)&3; $layer=($b2>>1)&3; $idx=($b3>>4)&15;
      if ($version===3 && $layer===1 && isset($bitrates[$idx])) return round(($size*8)/($bitrates[$idx]*1000),1);
    }
  }
  return null;
}

function library($musicPath, $musicUrl, $analyze=false) {
  if (!is_dir($musicPath)) return [];
  $allowed=['mp3','m4a','aac','wav','ogg','flac']; $tracks=[];
  foreach (scandir($musicPath) ?: [] as $file) {
    if ($file==='.'||$file==='..') continue; $full=$musicPath.DIRECTORY_SEPARATOR.$file;
    if (!is_file($full)) continue; $ext=strtolower(pathinfo($file,PATHINFO_EXTENSION));
    if (!in_array($ext,$allowed,true)) continue;
    $duration=($analyze && $ext==='mp3') ? mp3Duration($full) : null;
    $tracks[]=['file'=>$file,'title'=>trim(preg_replace('/[_-]+/',' ',pathinfo($file,PATHINFO_FILENAME))),'url'=>$musicUrl.rawurlencode($file),'artist'=>null,'bpm'=>null,'energy'=>null,'duration'=>$duration,'bytes'=>@filesize($full) ?: null];
  }
  usort($tracks,fn($a,$b)=>strcasecmp($a['file'],$b['file'])); return array_values($tracks);
}

function energyProgram($tracks) {
  if (!$tracks) return [];
  // Until true audio BPM/energy analysis is available, use duration + file size only as a neutral variation seed.
  foreach ($tracks as &$t) {
    $dur=$t['duration'] ?: 180; $bytes=$t['bytes'] ?: 0;
    $t['_seed']=($bytes % 1000003)/1000003 + min(1,$dur/600);
  } unset($t);
  usort($tracks,fn($a,$b)=>$a['_seed']<=>$b['_seed']);
  $n=count($tracks); foreach ($tracks as $i=>&$t) { $t['energyStep']=round((($i+1)/$n)*100); unset($t['_seed']); } unset($t);
  return $tracks;
}

function defaultState(){return ['mode'=>'AUTO','playing'=>false,'currentIndex'=>-1,'currentTrack'=>null,'startedAt'=>null,'updatedAt'=>gmdate('c')];}
function readState($f){if(!is_file($f))return defaultState();$d=json_decode(file_get_contents($f),true);return is_array($d)?$d:defaultState();}
function writeState($f,$s){file_put_contents($f,json_encode($s,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX);}
function bodyJson(){ $d=json_decode(file_get_contents('php://input'),true); return is_array($d)?$d:[]; }

$action=$_GET['action']??'root'; $state=readState($stateFile);
if($action==='root') out(['name'=>'SONO PLAY MINI LIVE','version'=>'0.2.0-php','pipeline'=>'FOLDER → SCAN → ANALYZE → ENERGY CLIMB → PLAY','endpoints'=>['?action=library','?action=analyze','?action=program','?action=status','?action=play','?action=next','?action=stop']]);
if($action==='library'){ $t=library($musicPath,$musicUrl,false); out(['source'=>$musicUrl,'count'=>count($t),'tracks'=>$t]); }
if($action==='analyze'){ $t=library($musicPath,$musicUrl,true); out(['source'=>$musicUrl,'count'=>count($t),'analysis'=>'duration-v0','tracks'=>$t]); }
if($action==='program'){ $t=energyProgram(library($musicPath,$musicUrl,true)); out(['source'=>$musicUrl,'count'=>count($t),'program'=>'ENERGY CLIMB V0','warning'=>'energyStep is provisional until real BPM/audio-energy analysis is installed','tracks'=>$t]); }
if($action==='status') out($state+['musicUrl'=>$musicUrl]);
if($action==='play' && $_SERVER['REQUEST_METHOD']==='POST'){
 $t=energyProgram(library($musicPath,$musicUrl,true));$b=bodyJson();$idx=-1;
 if(isset($b['index'])&&is_int($b['index']))$idx=$b['index']; elseif(!empty($b['file']))foreach($t as $i=>$x)if($x['file']===$b['file']){$idx=$i;break;}
 if($idx<0||$idx>=count($t))out(['error'=>'Track not found'],404);
 $state=['mode'=>'AUTO','playing'=>true,'currentIndex'=>$idx,'currentTrack'=>$t[$idx],'startedAt'=>gmdate('c'),'updatedAt'=>gmdate('c')];writeState($stateFile,$state);out($state);
}
if($action==='next' && $_SERVER['REQUEST_METHOD']==='POST'){
 $t=energyProgram(library($musicPath,$musicUrl,true));if(!$t)out(['error'=>'Library is empty'],409);$next=($state['currentIndex']??-1)<0?0:(($state['currentIndex']+1)%count($t));$state=['mode'=>'AUTO','playing'=>true,'currentIndex'=>$next,'currentTrack'=>$t[$next],'startedAt'=>gmdate('c'),'updatedAt'=>gmdate('c')];writeState($stateFile,$state);out($state);
}
if($action==='stop' && $_SERVER['REQUEST_METHOD']==='POST'){ $state['playing']=false;$state['updatedAt']=gmdate('c');writeState($stateFile,$state);out($state); }
out(['error'=>'Not found'],404);
