<?php
function getDb(): PDO {
  // Carpeta persistente en Azure App Service Linux
  $home = getenv('HOME') ?: '/home';
  $dataDir = $home . '/site/data';

  // Crear /site/data si no existe y comprobar permisos
  if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0777, true);
  }
  if (!is_dir($dataDir) || !is_writable($dataDir)) {
    // Fallback: /home/LogFiles también es persistente y escribible
    $dataDir = $home . '/LogFiles';
    if (!is_dir($dataDir)) { @mkdir($dataDir, 0777, true); }
  }

  $dbPath = $dataDir . '/games.db';

  // Si subiste una BD semilla en /private, la copiamos solo la primera vez
  foreach ([
    __DIR__ . '/private/games.db',
    __DIR__ . '/private/game.db'
  ] as $seed) {
    if (!file_exists($dbPath) && file_exists($seed)) {
      @copy($seed, $dbPath);
      @chmod($dbPath, 0666);
      break;
    }
  }

  // Crear archivo vacío si no existe (para forzar permisos correctos)
  if (!file_exists($dbPath)) {
    @file_put_contents($dbPath, '');
    @chmod($dbPath, 0666);
  }

  // Conectar con PDO (modo excepciones)
  $pdo = new PDO('sqlite:' . $dbPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);

  // Crear tabla si no existe (ajusta columnas a tu juego)
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS games (
      game_id TEXT PRIMARY KEY,
      player1 TEXT,
      player2 TEXT,
      platforms TEXT,
      point_platform INTEGER,
      player1_x INTEGER DEFAULT 50,
      player1_y INTEGER DEFAULT 550,
      player2_x INTEGER DEFAULT 350,
      player2_y INTEGER DEFAULT 550,
      player1_score INTEGER DEFAULT 0,
      player2_score INTEGER DEFAULT 0,
      winner TEXT,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
  ");

  return $pdo;
}
