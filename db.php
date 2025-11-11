<?php
function getDb(): PDO {
    // Carpeta persistente en Azure App Service Linux
    $home = getenv('HOME') ?: '/home';
    $dataDir = $home . '/site/data';

    // Crear /site/data si no existe
    if (!is_dir($dataDir)) {
        @mkdir($dataDir, 0777, true);
    }
    
    // Si no se puede crear o escribir en /site/data, usar /home/LogFiles
    if (!is_dir($dataDir) || !is_writable($dataDir)) {
        $dataDir = $home . '/LogFiles';
        if (!is_dir($dataDir)) { 
            @mkdir($dataDir, 0777, true); 
        }
    }

    $dbPath = $dataDir . '/games.db';

    // Si existe una BD semilla en /private, copiarla solo la primera vez
    $seedPaths = [
        __DIR__ . '/private/games.db',
        __DIR__ . '/private/game.db'
    ];
    
    foreach ($seedPaths as $seed) {
        if (!file_exists($dbPath) && file_exists($seed)) {
            @copy($seed, $dbPath);
            @chmod($dbPath, 0666);
            break;
        }
    }

    // Crear archivo vacío si no existe
    if (!file_exists($dbPath)) {
        @file_put_contents($dbPath, '');
        @chmod($dbPath, 0666);
    }

    // Conectar con PDO (modo excepciones)
    try {
        $pdo = new PDO('sqlite:' . $dbPath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 30
        ]);
    } catch (PDOException $e) {
        throw new Exception("No se pudo conectar a la base de datos en $dbPath: " . $e->getMessage());
    }

    // Crear tabla si no existe
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS games (
                game_id TEXT PRIMARY KEY,
                player1 TEXT,
                player2 TEXT,
                platforms TEXT,
                point_platform TEXT,
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
    } catch (PDOException $e) {
        throw new Exception("No se pudo crear la tabla: " . $e->getMessage());
    }

    return $pdo;
}