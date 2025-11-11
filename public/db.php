<?php
function getDb(): PDO {
  // Ruta persistente en App Service Linux
  $dataDir = (getenv('HOME') ?: '/home') . '/site/data';
  if (!is_dir($dataDir)) { mkdir($dataDir, 0777, true); }

  $dbPath = $dataDir . '/games.db';

  // Primera vez: si subiste una DB local en /private, la copiamos
  $old = __DIR__ . '/private/games.db';
  if (!file_exists($dbPath) && file_exists($old)) {
    @copy($old, $dbPath);
  }

  // Conectar con PDO y excepciones
  $pdo = new PDO('sqlite:' . $dbPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);

  // Crear tabla si no existe (ajústala si tu juego necesita más campos)
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS games (
      game_id TEXT PRIMARY KEY,
      player1 TEXT,
      player2 TEXT,
      platforms TEXT,              -- JSON/CSV de plataformas
      point_platform INTEGER,      -- plataforma con puntos
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
