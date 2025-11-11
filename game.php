<?php
// game.php - API simplificada
error_reporting(E_ALL);
ini_set('display_errors', '0'); // Ocultar errores en producción

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Manejar OPTIONS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Log para debugging
function logDebug($msg) {
    error_log("[GAME-API] " . $msg);
}

logDebug("Request: " . $_SERVER['REQUEST_METHOD'] . " " . ($_SERVER['REQUEST_URI'] ?? ''));

// Verificar db.php
$dbFile = __DIR__ . '/db.php';
if (!file_exists($dbFile)) {
    logDebug("ERROR: db.php not found at $dbFile");
    http_response_code(500);
    echo json_encode(['error' => 'db.php not found', 'path' => __DIR__]);
    exit;
}

require $dbFile;

try {
    $db = getDb();
    logDebug("DB connected");
} catch (Exception $e) {
    logDebug("DB ERROR: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'DB error: ' . $e->getMessage()]);
    exit;
}

function jerr($code, $msg) {
    logDebug("ERROR $code: $msg");
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

function ok($arr = []) {
    echo json_encode($arr);
    exit;
}

// Obtener parámetros
$input = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (strpos($contentType, 'application/json') !== false) {
        $json = file_get_contents('php://input');
        $input = json_decode($json, true) ?? [];
    } else {
        $input = $_POST;
    }
}

$method    = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action    = $_GET['action'] ?? $input['action'] ?? null;
$game_id   = $_GET['game_id'] ?? $input['game_id'] ?? null;
$player_id = $_GET['player_id'] ?? $input['player_id'] ?? null;

logDebug("Action: $action, Method: $method");

if (!$action) jerr(400, 'Missing action');

function default_platforms() {
    return [
        ['x'=>20,'y'=>520,'width'=>120], ['x'=>240,'y'=>520,'width'=>120],
        ['x'=>60,'y'=>450,'width'=>120], ['x'=>210,'y'=>380,'width'=>120],
        ['x'=>40,'y'=>310,'width'=>120], ['x'=>250,'y'=>240,'width'=>120],
        ['x'=>100,'y'=>170,'width'=>120], ['x'=>220,'y'=>100,'width'=>120]
    ];
}

function new_point_platform($plats) {
    if (empty($plats)) $plats = default_platforms();
    $p = $plats[array_rand($plats)];
    return ['x'=>$p['x'],'y'=>$p['y']-15,'width'=>$p['width'],'points'=>10,'active'=>true];
}

try {
    switch ($action) {
        case 'join':
            logDebug("JOIN request");
            $player = bin2hex(random_bytes(8));
            $stmt = $db->query("SELECT * FROM games WHERE player2 IS NULL AND winner IS NULL LIMIT 1");
            $game = $stmt->fetch();

            if (!$game) {
                $gid = bin2hex(random_bytes(8));
                $plats = default_platforms();
                $pp = new_point_platform($plats);
                logDebug("New game: $gid");
                $stmt = $db->prepare("INSERT INTO games (game_id, player1, player2, platforms, point_platform, player1_x, player1_y, player2_x, player2_y, player1_score, player2_score, winner) VALUES (?, ?, NULL, ?, ?, 50, 550, 350, 550, 0, 0, NULL)");
                $stmt->execute([$gid, $player, json_encode($plats), json_encode($pp)]);
                ok(['game_id'=>$gid, 'player_id'=>$player, 'role'=>'player1']);
            } else {
                $gid = $game['game_id'];
                logDebug("Join game: $gid");
                $stmt = $db->prepare("UPDATE games SET player2=? WHERE game_id=?");
                $stmt->execute([$player, $gid]);
                ok(['game_id'=>$gid, 'player_id'=>$player, 'role'=>'player2']);
            }
            break;

        case 'status':
            if (!$game_id) jerr(400, 'Missing game_id');
            $stmt = $db->prepare("SELECT * FROM games WHERE game_id=?");
            $stmt->execute([$game_id]);
            $g = $stmt->fetch();
            if (!$g) jerr(404, 'Game not found');
            ok([
                'game_id'=>$g['game_id'], 'player1'=>$g['player1'], 'player2'=>$g['player2'],
                'player1_x'=>(int)$g['player1_x'], 'player1_y'=>(int)$g['player1_y'],
                'player2_x'=>(int)$g['player2_x'], 'player2_y'=>(int)$g['player2_y'],
                'points'=>[(int)$g['player1_score'], (int)$g['player2_score']],
                'winner'=>$g['winner'],
                'platforms'=>json_decode($g['platforms'], true) ?: default_platforms(),
                'point_platform'=>json_decode($g['point_platform'], true)
            ]);
            break;

        case 'update':
            if (!$game_id || !$player_id) jerr(400, 'Missing params');
            $x = isset($_GET['x']) ? (int)$_GET['x'] : (int)($input['x'] ?? 0);
            $y = isset($_GET['y']) ? (int)$_GET['y'] : (int)($input['y'] ?? 0);
            $stmt = $db->prepare("SELECT player1, player2 FROM games WHERE game_id=?");
            $stmt->execute([$game_id]);
            $g = $stmt->fetch();
            if (!$g) jerr(404, 'Game not found');
            if ($player_id === $g['player1']) {
                $db->prepare("UPDATE games SET player1_x=?, player1_y=? WHERE game_id=?")->execute([$x,$y,$game_id]);
            } elseif ($player_id === $g['player2']) {
                $db->prepare("UPDATE games SET player2_x=?, player2_y=? WHERE game_id=?")->execute([$x,$y,$game_id]);
            } else {
                jerr(403, 'Invalid player');
            }
            ok(['success'=>true]);
            break;

        case 'collect':
            if (!$game_id || !$player_id) jerr(400, 'Missing params');
            $stmt = $db->prepare("SELECT * FROM games WHERE game_id=?");
            $stmt->execute([$game_id]);
            $g = $stmt->fetch();
            if (!$g) jerr(404, 'Game not found');
            $pp = json_decode($g['point_platform'], true);
            if (!$pp || empty($pp['active'])) ok(['success'=>false]);
            $points = (int)($pp['points'] ?? 10);
            if ($player_id === $g['player1']) {
                $db->prepare("UPDATE games SET player1_score=player1_score+? WHERE game_id=?")->execute([$points,$game_id]);
            } elseif ($player_id === $g['player2']) {
                $db->prepare("UPDATE games SET player2_score=player2_score+? WHERE game_id=?")->execute([$points,$game_id]);
            } else { jerr(403, 'Invalid player'); }
            $plats = json_decode($g['platforms'], true) ?: default_platforms();
            $ppNew = new_point_platform($plats);
            $db->prepare("UPDATE games SET point_platform=? WHERE game_id=?")->execute([json_encode($ppNew), $game_id]);
            $stmt = $db->prepare("SELECT player1_score, player2_score, player1, player2 FROM games WHERE game_id=?");
            $stmt->execute([$game_id]);
            $row = $stmt->fetch();
            $winner = null;
            if ((int)$row['player1_score'] >= 100) $winner = $row['player1'];
            if ((int)$row['player2_score'] >= 100) $winner = $row['player2'];
            if ($winner) $db->prepare("UPDATE games SET winner=? WHERE game_id=?")->execute([$winner,$game_id]);
            ok(['success'=>true, 'points'=>$points, 'winner'=>$winner]);
            break;

        default: jerr(400, 'Unknown action');
    }
} catch (Throwable $e) {
    logDebug("ERROR: " . $e->getMessage());
    jerr(500, 'Server error: ' . $e->getMessage());
}