<?php
// game.php – API sencilla para 1v1
header('Content-Type: application/json; charset=utf-8');
session_start();

require __DIR__ . '/db.php';
$db = getDb();

function jerr($code, $msg) {
  http_response_code($code);
  echo json_encode(['error' => $msg]);
  exit;
}
function ok($arr = []) {
  echo json_encode($arr);
  exit;
}

$action    = $_GET['action']  ?? $_POST['action'] ?? null;
$game_id   = $_GET['game_id'] ?? $_POST['game_id'] ?? null;
$player_id = $_GET['player_id'] ?? $_POST['player_id'] ?? null;

if (!$action) jerr(400, 'Falta action');

// Genera plataformas por defecto
function default_platforms() {
  return [
    ['x'=>20,  'y'=>520, 'width'=>120],
    ['x'=>240, 'y'=>520, 'width'=>120],
    ['x'=>60,  'y'=>450, 'width'=>120],
    ['x'=>210, 'y'=>380, 'width'=>120],
    ['x'=>40,  'y'=>310, 'width'=>120],
    ['x'=>250, 'y'=>240, 'width'=>120],
    ['x'=>100, 'y'=>170, 'width'=>120],
    ['x'=>220, 'y'=>100, 'width'=>120]
  ];
}
function new_point_platform($plats) {
  $p = $plats[array_rand($plats)];
  return ['x'=>$p['x'], 'y'=>$p['y']-15, 'width'=>$p['width'], 'points'=>10, 'active'=>true];
}

try {
  switch ($action) {
    // Unirse a un juego: POST recomendado
    case 'join': {
      if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        jerr(405, 'join debe ser POST');
      }
      $player = bin2hex(random_bytes(8));

      // Buscar partida esperando jugador2
      $stmt = $db->query("SELECT * FROM games WHERE player2 IS NULL LIMIT 1");
      $game = $stmt->fetch();

      if (!$game) {
        // Crear partida
        $gid = bin2hex(random_bytes(8));
        $plats = default_platforms();
        $pp = new_point_platform($plats);
        $db->prepare("INSERT INTO games
          (game_id, player1, player2, platforms, point_platform,
           player1_x, player1_y, player2_x, player2_y, player1_score, player2_score, winner)
          VALUES (?, ?, NULL, ?, ?, 50, 550, 350, 550, 0, 0, NULL)")
          ->execute([$gid, $player, json_encode($plats), json_encode($pp)]);
        ok(['game_id'=>$gid, 'player_id'=>$player, 'role'=>'player1']);
      } else {
        // Entrar como jugador2
        $gid = $game['game_id'];
        $db->prepare("UPDATE games SET player2=? WHERE game_id=?")
           ->execute([$player, $gid]);
        ok(['game_id'=>$gid, 'player_id'=>$player, 'role'=>'player2']);
      }
    }

    // Estado del juego
    case 'status': {
      if (!$game_id) jerr(400, 'Falta game_id');
      $stmt = $db->prepare("SELECT * FROM games WHERE game_id=?");
      $stmt->execute([$game_id]);
      $g = $stmt->fetch();
      if (!$g) jerr(404, 'Partida no trobada');

      $plats = $g['platforms'] ? json_decode($g['platforms'], true) : default_platforms();
      $pp    = $g['point_platform'] ? json_decode($g['point_platform'], true) : null;

      ok([
        'game_id' => $g['game_id'],
        'player1' => $g['player1'],
        'player2' => $g['player2'],
        'player1_x' => (int)$g['player1_x'],
        'player1_y' => (int)$g['player1_y'],
        'player2_x' => (int)$g['player2_x'],
        'player2_y' => (int)$g['player2_y'],
        'points' => [ (int)$g['player1_score'], (int)$g['player2_score'] ],
        'winner' => $g['winner'],
        'platforms' => $plats,
        'point_platform' => $pp
      ]);
    }

    // Actualizar posición (se llama muy a menudo)
    case 'update': {
      if (!$game_id || !$player_id) jerr(400, 'Falta game_id o player_id');
      $x = isset($_GET['x']) ? (int)$_GET['x'] : (int)($_POST['x'] ?? 0);
      $y = isset($_GET['y']) ? (int)$_GET['y'] : (int)($_POST['y'] ?? 0);

      $stmt = $db->prepare("SELECT player1, player2 FROM games WHERE game_id=?");
      $stmt->execute([$game_id]);
      $g = $stmt->fetch();
      if (!$g) jerr(404, 'Partida no trobada');

      if ($player_id === $g['player1']) {
        $db->prepare("UPDATE games SET player1_x=?, player1_y=? WHERE game_id=?")->execute([$x,$y,$game_id]);
      } elseif ($player_id === $g['player2']) {
        $db->prepare("UPDATE games SET player2_x=?, player2_y=? WHERE game_id=?")->execute([$x,$y,$game_id]);
      } else {
        jerr(403, 'player_id no pertany al joc');
      }
      ok(['success'=>true]);
    }

    // Recoger puntos
    case 'collect': {
      if (!$game_id || !$player_id) jerr(400, 'Falta game_id o player_id');
      $stmt = $db->prepare("SELECT * FROM games WHERE game_id=?");
      $stmt->execute([$game_id]);
      $g = $stmt->fetch();
      if (!$g) jerr(404, 'Partida no trobada');

      $pp = $g['point_platform'] ? json_decode($g['point_platform'], true) : null;
      if (!$pp || empty($pp['active'])) ok(['success'=>false, 'msg'=>'sense punts']);

      $points = (int)($pp['points'] ?? 10);
      if ($player_id === $g['player1']) {
        $db->prepare("UPDATE games SET player1_score = player1_score + ? WHERE game_id=?")->execute([$points,$game_id]);
      } elseif ($player_id === $g['player2']) {
        $db->prepare("UPDATE games SET player2_score = player2_score + ? WHERE game_id=?")->execute([$points,$game_id]);
      } else {
        jerr(403, 'player_id no pertany al joc');
      }

      // Desactivar plataforma y crear una nova
      $plats = $g['platforms'] ? json_decode($g['platforms'], true) : default_platforms();
      $ppNew = new_point_platform($plats);
      $db->prepare("UPDATE games SET point_platform=? WHERE game_id=?")->execute([json_encode($ppNew), $game_id]);

      // Ganador a 100
      $stmt = $db->prepare("SELECT player1_score, player2_score FROM games WHERE game_id=?");
      $stmt->execute([$game_id]);
      $sc = $stmt->fetch();
      $winner = null;
      if ((int)$sc['player1_score'] >= 100) $winner = $g['player1'];
      if ((int)$sc['player2_score'] >= 100) $winner = $g['player2'];
      if ($winner) $db->prepare("UPDATE games SET winner=? WHERE game_id=?")->execute([$winner,$game_id]);

      ok(['success'=>true, 'points'=>$points]);
    }

    default:
      jerr(400, 'Acció desconeguda');
  }
} catch (Throwable $e) {
  jerr(500, 'Error del servidor: '.$e->getMessage());
}
