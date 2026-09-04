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

function commandExists($cmd) {
  if (!function_exists('shell_exec')) return false;
  $out = @shell_exec('command -v '.escapeshellarg($cmd).' 2>/dev/null');
  return is_string($out) && trim($out) !== '';
}

function bpmNormalize($bpm) {
  if (!$bpm || $bpm < 30 || $bpm > 300) return null;
  while ($bpm < 70) $bpm *= 2;
  while ($bpm > 180) $bpm /= 2;
  return round($bpm, 1);
}

function bpmDistance($a, $b) {
  if ($a === null || $b === null) return INF;
  $candidates = [$b, $b*2, $b/2];
  $best = INF;
  foreach ($candidates as $x) $best = min($best, abs($a-$x));
  return $best;
}

function detectBpmSegment($file, $start, $length=45) {
  if (!commandExists('ffmpeg')) return null;
  $tmp = tempnam(sys_get_temp_dir(), 'sono_bpm_');
  if (!$tmp) return null;
  @unlink($tmp);
  $wav = $tmp.'.wav';
  $cmd = 'ffmpeg -hide_banner -loglevel error -ss '.escapeshellarg((string)$start).' -t '.escapeshellarg((string)$length).' -i '.escapeshellarg($file).' -ac 1 -ar 22050 -f wav '.escapeshellarg($wav).' -y 2>/dev/null';
  @shell_exec($cmd);
  if (!is_file($wav) || filesize($wav) < 1000) { @unlink($wav); return null; }

  $bpm = null;
  if (commandExists('aubio')) {
    $out = @shell_exec('aubio tempo '.escapeshellarg($wav).' 2>/dev/null');
    if (is_string($out) && preg_match_all('/([0-9]+(?:\.[0-9]+)?)\s+bpm/i', $out, $m) && !empty($m[1])) {
      $vals = array_map('floatval', $m[1]);
      sort($vals, SORT_NUMERIC);
      $bpm = $vals[(int)floor(count($vals)/2)];
    }
  }
  @unlink($wav);
  return bpmNormalize($bpm);
}

function multiSegmentBpm($file, $duration) {
  $result = [
    'bpm'=>null,
    'confidence'=>0,
    'segments'=>[],
    'method'=>'multi-segment-consensus-v1',
    'available'=>false
  ];
  if (!commandExists('ffmpeg') || !commandExists('aubio')) return $result;
  $result['available'] = true;
  if (!$duration || $duration < 30) return $result;

  $segmentLength = min(45, max(20, $duration * 0.12));
  $anchors = [0.12, 0.32, 0.52, 0.72, 0.88];
  $maxStart = max(0, $duration - $segmentLength - 1);
  $values = [];

  foreach ($anchors as $ratio) {
    $start = min($maxStart, max(0, ($duration * $ratio) - ($segmentLength/2)));
    $bpm = detectBpmSegment($file, round($start,1), round($segmentLength,1));
    $result['segments'][] = ['start'=>round($start,1), 'length'=>round($segmentLength,1), 'bpm'=>$bpm];
    if ($bpm !== null) $values[] = $bpm;
  }

  if (!$values) return $result;

  $candidates = [];
  foreach ($values as $base) {
    $score = 0; $support = 0;
    foreach ($values as $v) {
      $d = bpmDistance($base, $v);
      if ($d <= 2.5) { $score += max(0, 2.5-$d); $support++; }
    }
    $candidates[] = ['bpm'=>$base, 'score'=>$score, 'support'=>$support];
  }
  usort($candidates, function($a,$b){ if ($a['support']===$b['support']) return $b['score']<=>$a['score']; return $b['support']<=>$a['support']; });
  $winner = $candidates[0];

  $cluster = [];
  foreach ($values as $v) {
    $variants = [$v, $v*2, $v/2];
    $best = null; $bestD = INF;
    foreach ($variants as $x) {
      $d = abs($winner['bpm']-$x);
      if ($d < $bestD) { $bestD=$d; $best=$x; }
    }
    if ($bestD <= 2.5) $cluster[] = $best;
  }
  if ($cluster) {
    sort($cluster, SORT_NUMERIC);
    $median = $cluster[(int)floor(count($cluster)/2)];
    $result['bpm'] = round(bpmNormalize($median),1);
    $result['confidence'] = round(count($cluster)/count($anchors),2);
  }
  return $result;
}

function library($musicPath, $musicUrl, $analyze=false, $analyzeBpm=false) {
  if (!is_dir($musicPath)) return [];
  $allowed=['mp3','m4a','aac','wav','ogg','flac']; $tracks=[];
  foreach (scandir($musicPath) ?: [] as $file) {
    if ($file==='.'||$file==='..') continue;
    $full=$musicPath.DIRECTORY_SEPARATOR.$file;
    if (!is_file($full)) continue;
    $ext=strtolower(pathinfo($file,PATHINFO_EXTENSION));
    if (!in_array($ext,$allowed,true)) continue;
    $duration=($analyze && $ext==='mp3') ? mp3Duration($full) : null;
    $bpmInfo = $analyzeBpm ? multiSegmentBpm($full, $duration ?: 180) : ['bpm'=>null,'confidence'=>0,'segments'=>[],'method'=>null,'available'=>null];
    $tracks[]=[
      'file'=>$file,
      'title'=>trim(preg_replace('/[_-]+/',' ',pathinfo($file,PATHINFO_FILENAME))),
      'url'=>$musicUrl.rawurlencode($file),
      'artist'=>null,
      'bpm'=>$bpmInfo['bpm'],
      'bpmConfidence'=>$bpmInfo['confidence'],
      'bpmMethod'=>$bpmInfo['method'],
      'bpmSegments'=>$bpmInfo['segments'],
      'bpmEngineAvailable'=>$bpmInfo['available'],
      'energy'=>null,
      'duration'=>$duration,
      'bytes'=>@filesize($full) ?: null
    ];
  }
  usort($tracks,fn($a,$b)=>strcasecmp($a['file'],$b['file']));
  return array_values($tracks);
}

function energyProgram($tracks) {
  if (!$tracks) return [];
  foreach ($tracks as &$t) {
    $dur=$t['duration'] ?: 180; $bytes=$t['bytes'] ?: 0;
    $bpm = $t['bpm'] ?: 0;
    $confidence = $t['bpmConfidence'] ?: 0;
    $t['_seed']=($confidence > 0 && $bpm > 0) ? ($bpm + (1-$confidence)*10) : (1000 + (($bytes % 1000003)/1000003) + min(1,$dur/600));
  }
  unset($t);
  usort($tracks,fn($a,$b)=>$a['_seed']<=>$b['_seed']);
  $n=count($tracks);
  foreach ($tracks as $i=>&$t) { $t['energyStep']=round((($i+1)/$n)*100); unset($t['_seed']); }
  unset($t);
  return $tracks;
}

function timelineNow($tracks, $epoch=1704067200) {
  if (!$tracks) return null;
  $cycle=0.0;
  foreach ($tracks as $t) $cycle += max(1, (float)($t['duration'] ?: 180));
  if ($cycle <= 0) return null;
  $elapsed = fmod(max(0, microtime(true)-$epoch), $cycle);
  $cursor = 0.0;
  foreach ($tracks as $i=>$t) {
    $dur=max(1,(float)($t['duration'] ?: 180));
    if ($elapsed < $cursor+$dur) {
      $pos=$elapsed-$cursor;
      return [
        'mode'=>'AUTO','playing'=>true,'currentIndex'=>$i,'currentTrack'=>$t,
        'position'=>round($pos,1),'duration'=>round($dur,1),'remaining'=>round($dur-$pos,1),
        'progress'=>round(($pos/$dur)*100,1),'cycleDuration'=>round($cycle,1),
        'serverTime'=>gmdate('c'),'timelineEpoch'=>$epoch
      ];
    }
    $cursor += $dur;
  }
  return null;
}

function defaultState(){return ['mode'=>'AUTO','playing'=>false,'currentIndex'=>-1,'currentTrack'=>null,'startedAt'=>null,'updatedAt'=>gmdate('c')];}
function readState($f){if(!is_file($f))return defaultState();$d=json_decode(file_get_contents($f),true);return is_array($d)?$d:defaultState();}
function writeState($f,$s){file_put_contents($f,json_encode($s,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX);}
function bodyJson(){ $d=json_decode(file_get_contents('php://input'),true); return is_array($d)?$d:[]; }

$action=$_GET['action']??'root';
$state=readState($stateFile);

if($action==='root') out([
  'name'=>'SONO PLAY MINI LIVE','version'=>'0.4.0-php',
  'pipeline'=>'FOLDER → SCAN → DURATION → MULTI-SEGMENT BPM → CONSENSUS → ENERGY CLIMB → SYNCHRONIZED TIMELINE',
  'bpm'=>'Audio-only. Filename BPM is ignored.',
  'endpoints'=>['?action=library','?action=analyze','?action=analyze-bpm','?action=program','?action=now','?action=capabilities','?action=status','?action=play','?action=next','?action=stop']
]);
if($action==='capabilities') out([
  'shellExec'=>function_exists('shell_exec'),
  'ffmpeg'=>commandExists('ffmpeg'),
  'aubio'=>commandExists('aubio'),
  'note'=>'Real multi-segment BPM requires ffmpeg + aubio on the host.'
]);
if($action==='library'){ $t=library($musicPath,$musicUrl,false,false); out(['source'=>$musicUrl,'count'=>count($t),'tracks'=>$t]); }
if($action==='analyze'){ $t=library($musicPath,$musicUrl,true,false); out(['source'=>$musicUrl,'count'=>count($t),'analysis'=>'duration-v0','tracks'=>$t]); }
if($action==='analyze-bpm'){ $t=library($musicPath,$musicUrl,true,true); out(['source'=>$musicUrl,'count'=>count($t),'analysis'=>'multi-segment-consensus-v1','warning'=>'Filename BPM is ignored. Engine needs ffmpeg + aubio.','tracks'=>$t]); }
if($action==='program'){ $t=energyProgram(library($musicPath,$musicUrl,true,true)); out(['source'=>$musicUrl,'count'=>count($t),'program'=>'ENERGY CLIMB V1','warning'=>'Uses measured BPM when available; filename BPM is never trusted.','tracks'=>$t]); }
if($action==='now'){ $t=energyProgram(library($musicPath,$musicUrl,true,true)); $now=timelineNow($t); if(!$now) out(['error'=>'No playable tracks'],409); out($now); }
if($action==='status') out($state+['musicUrl'=>$musicUrl]);
if($action==='play' && $_SERVER['REQUEST_METHOD']==='POST'){
  $t=energyProgram(library($musicPath,$musicUrl,true,true)); $b=bodyJson(); $idx=-1;
  if(isset($b['index'])&&is_int($b['index'])) $idx=$b['index'];
  elseif(!empty($b['file'])) foreach($t as $i=>$x) if($x['file']===$b['file']) {$idx=$i;break;}
  if($idx<0||$idx>=count($t)) out(['error'=>'Track not found'],404);
  $state=['mode'=>'MANUAL','playing'=>true,'currentIndex'=>$idx,'currentTrack'=>$t[$idx],'startedAt'=>gmdate('c'),'updatedAt'=>gmdate('c')];
  writeState($stateFile,$state); out($state);
}
if($action==='next' && $_SERVER['REQUEST_METHOD']==='POST'){
  $t=energyProgram(library($musicPath,$musicUrl,true,true)); if(!$t) out(['error'=>'Library is empty'],409);
  $next=($state['currentIndex']??-1)<0?0:(($state['currentIndex']+1)%count($t));
  $state=['mode'=>'MANUAL','playing'=>true,'currentIndex'=>$next,'currentTrack'=>$t[$next],'startedAt'=>gmdate('c'),'updatedAt'=>gmdate('c')];
  writeState($stateFile,$state); out($state);
}
if($action==='stop' && $_SERVER['REQUEST_METHOD']==='POST'){
  $state['playing']=false; $state['updatedAt']=gmdate('c'); writeState($stateFile,$state); out($state);
}
out(['error'=>'Not found'],404);
