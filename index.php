<?php
declare(strict_types=1);

/*
 SONO PLAY MINI LIVE — INDEX CANDIDATE V0.3
 - Europe/Berlin clock
 - hard daily reset at 06:40
 - BPM climb on accepted BPM tracks
 - UNKNOWN BPM tracks are surprise cards inserted deterministically at random positions
 - deterministic shuffle inside BPM bands, different on each loop/day
 - rescans /MUSIC and reports files not present in analysis-cache-v1.json as pending
 - NO audio streaming: this is schedule/state logic only
*/

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

date_default_timezone_set('Europe/Berlin');

$cacheFile = __DIR__ . '/analysis-cache-v1.json';
$musicPath = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/') . '/MUSIC';
$musicUrl  = 'https://toutvabiensepasser.com/MUSIC/';

function fail(string $m, int $code=500): never {
    http_response_code($code);
    echo json_encode(['ok'=>false,'error'=>$m], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
    exit;
}
if (!is_file($cacheFile)) fail('Missing analysis-cache-v1.json');

$cache = json_decode((string)file_get_contents($cacheFile), true);
if (!is_array($cache) || empty($cache['tracks'])) fail('Invalid analysis cache');

function scanMusic(string $path, string $baseUrl): array {
    if (!is_dir($path)) return [];
    $exts=['mp3','m4a','aac','wav','ogg','flac'];
    $out=[];
    foreach (scandir($path) ?: [] as $f) {
        if ($f==='.' || $f==='..') continue;
        $p=$path.'/'.$f;
        if (!is_file($p)) continue;
        $ext=strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (!in_array($ext,$exts,true)) continue;
        $out[]=[
            'file'=>$f,
            'url'=>$baseUrl . rawurlencode($f),
            'size'=>filesize($p) ?: null,
            'mtime'=>filemtime($p) ?: null
        ];
    }
    usort($out, fn($a,$b)=>strnatcasecmp($a['file'],$b['file']));
    return $out;
}

function seededShuffle(array $items, string $seed): array {
    $decor=[];
    foreach ($items as $i=>$item) {
        $key=hash('sha256',$seed.'|'.$i.'|'.($item['file'] ?? ''));
        $decor[]=['k'=>$key,'v'=>$item];
    }
    usort($decor, fn($a,$b)=>strcmp($a['k'],$b['k']));
    return array_column($decor,'v');
}

function makeOrder(array $tracks, string $seed, float $band=4.0): array {
    $known=array_values(array_filter($tracks, fn($t)=>isset($t['bpm']) && is_numeric($t['bpm']) && ($t['duration'] ?? 0)>0));
    $unknown=array_values(array_filter($tracks, fn($t)=>(!isset($t['bpm']) || !is_numeric($t['bpm'])) && ($t['duration'] ?? 0)>0));

    $bands=[];
    foreach ($known as $t) {
        $bucket=(int)floor(((float)$t['bpm'])/$band);
        $bands[$bucket][]=$t;
    }
    ksort($bands, SORT_NUMERIC);
    $ordered=[];
    foreach ($bands as $bucket=>$items) {
        $items=seededShuffle($items,$seed.'|band='.$bucket);
        foreach ($items as $t) $ordered[]=$t;
    }

    // UNKNOWN BPM = deliberate surprise. The seed makes every listener get
    // exactly the same surprises, while the positions change on the next loop.
    $unknown=seededShuffle($unknown,$seed.'|unknown');
    foreach ($unknown as $u) {
        $u['surprise']=true;
        $max=count($ordered);
        $hex=substr(hash('sha256',$seed.'|surprise|'.$u['file']),0,8);
        $pos=$max ? (hexdec($hex) % ($max+1)) : 0;
        array_splice($ordered,$pos,0,[$u]);
    }
    return $ordered;
}

function totalDuration(array $tracks): float {
    $s=0.0;
    foreach ($tracks as $t) $s += max(0.0,(float)($t['duration'] ?? 0));
    return $s;
}

function anchorForNow(DateTimeImmutable $now): DateTimeImmutable {
    $today=$now->setTime(6,40,0);
    return $now >= $today ? $today : $today->modify('-1 day');
}

$library=scanMusic($musicPath,$musicUrl);
$cacheByFile=[];
foreach ($cache['tracks'] as $t) $cacheByFile[$t['file']]=$t;

$pending=[];
foreach ($library as $f) {
    if (!isset($cacheByFile[$f['file']])) $pending[]=$f;
}
$missing=[];
$libraryNames=array_fill_keys(array_column($library,'file'),true);
foreach ($cache['tracks'] as $t) {
    if ($library && !isset($libraryNames[$t['file']])) $missing[]=$t['file'];
}

$accepted=array_values(array_filter($cache['tracks'], fn($t)=>isset($t['bpm']) && is_numeric($t['bpm']) && ($t['duration'] ?? 0)>0));
$bpmPending=array_values(array_filter($cache['tracks'], fn($t)=>!isset($t['bpm']) || !is_numeric($t['bpm'])));

$now=new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
$anchor=anchorForNow($now);
$elapsed=max(0,$now->getTimestamp()-$anchor->getTimestamp());

$baseDuration=totalDuration(array_values(array_filter($cache['tracks'], fn($t)=>($t['duration'] ?? 0)>0)));
if ($baseDuration <= 0) fail('No analyzed BPM tracks available');

$loopIndex=(int)floor($elapsed/$baseDuration);
$offset=$elapsed - ($loopIndex*$baseDuration);
$seed=$anchor->format('Y-m-d').'|loop='.$loopIndex;
$order=makeOrder($cache['tracks'],$seed,4.0);

$current=null; $next=null; $cursor=0.0; $idx=0;
foreach ($order as $i=>$t) {
    $d=(float)$t['duration'];
    if ($offset < $cursor+$d) {
        $idx=$i;
        $position=$offset-$cursor;
        $current=$t + [
            'index'=>$i,
            'position'=>round($position,1),
            'remaining'=>round($d-$position,1),
            'progress'=>round(($position/$d)*100,1)
        ];
        $next=$order[($i+1)%count($order)] ?? null;
        break;
    }
    $cursor += $d;
}
if ($current===null) {
    $current=$order[0] + ['index'=>0,'position'=>0,'remaining'=>$order[0]['duration'],'progress'=>0];
    $next=$order[1] ?? $order[0];
}

$nextDailyReset=$anchor->modify('+1 day');
$loopStart=$anchor->modify('+'.(int)round($loopIndex*$baseDuration).' seconds');
$loopEnd=$loopStart->modify('+'.(int)round($baseDuration).' seconds');

$action=$_GET['action'] ?? 'now';

$common=[
    'ok'=>true,
    'version'=>'0.3.0-live-candidate',
    'timezone'=>'Europe/Berlin',
    'serverTime'=>$now->format(DATE_ATOM),
    'dailyAnchor'=>'06:40',
    'dailyAnchorAt'=>$anchor->format(DATE_ATOM),
    'nextDailyReset'=>$nextDailyReset->format(DATE_ATOM),
    'libraryCount'=>count($library) ?: count($cache['tracks']),
    'analyzedCount'=>count($cache['tracks']),
    'scheduledBpmCount'=>count($accepted),
    'scheduledSurpriseCount'=>count($bpmPending),
    'scheduledTotalCount'=>count($order),
    'bpmPendingCount'=>count($bpmPending),
    'newFilesPendingAnalysis'=>count($pending),
    'missingFromLibrary'=>count($missing),
    'libraryDurationSeconds'=>$cache['libraryDurationSeconds'] ?? null,
    'scheduledLoopDurationSeconds'=>round($baseDuration,1),
    'scheduledLoopDurationHours'=>round($baseDuration/3600,3),
    'loopIndex'=>$loopIndex,
    'loopStart'=>$loopStart->format(DATE_ATOM),
    'loopEnd'=>$loopEnd->format(DATE_ATOM),
    'seed'=>$seed,
    'rule'=>'Known BPM ascends by 4-BPM bands. UNKNOWN BPM tracks are inserted as deterministic random surprises. All positions change on each loop. Hard reset daily at 06:40 Europe/Berlin.'
];

if ($action==='library') {
    $items=[];
    foreach ($cache['tracks'] as $t) {
        $items[]=[
            'file'=>$t['file'],
            'url'=>$t['url'] ?? null,
            'duration'=>$t['duration'] ?? null,
            'bpm'=>$t['bpm'] ?? null,
            'bpmConfidence'=>$t['bpmConfidence'] ?? null,
            'energy'=>$t['energy'] ?? null,
            'surprise'=>(!isset($t['bpm']) || !is_numeric($t['bpm']))
        ];
    }
    echo json_encode($common + [
        'count'=>count($items),
        'tracks'=>$items,
        'newPending'=>$pending,
        'missing'=>$missing
    ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
    exit;
}

if ($action==='status') {
    echo json_encode($common + [
        'bpmPending'=>array_map(fn($t)=>$t['file'],$bpmPending),
        'newPending'=>$pending,
        'missing'=>$missing
    ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
    exit;
}
if ($action==='program') {
    $program=[];
    $c=0.0;
    foreach ($order as $i=>$t) {
        $program[]=[
            'index'=>$i,'file'=>$t['file'],'url'=>$t['url'] ?? null,
            'bpm'=>$t['bpm'] ?? null,'bpmConfidence'=>$t['bpmConfidence'] ?? null,'surprise'=>$t['surprise'] ?? false,
            'energy'=>$t['energy'] ?? null,'duration'=>$t['duration'],
            'startOffset'=>round($c,1),'endOffset'=>round($c+(float)$t['duration'],1)
        ];
        $c += (float)$t['duration'];
    }
    echo json_encode($common + ['program'=>$program], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
    exit;
}

echo json_encode($common + [
    'current'=>$current,
    'next'=>$next ? [
        'file'=>$next['file'],'url'=>$next['url'] ?? null,'bpm'=>$next['bpm'] ?? null,'surprise'=>$next['surprise'] ?? false,
        'energy'=>$next['energy'] ?? null,'duration'=>$next['duration']
    ] : null
], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
