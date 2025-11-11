<?php
function getDb(): PDO {
    // En Azure App Service, usar /home que es persistente
    $home = getenv('HOME') ?: '/home';
    
    // Intentar diferentes ubicaciones
    $possibleDirs = [
        $home . '/data',
        $home . '/site/data',
        '/tmp'  // No persistente pero funcional
    ];
    
    $dataDir = null;
    foreach ($possibleDirs as $dir) {
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        if (is_dir($dir) && is_writable($dir)) {
            $dataDir = $dir;
            break;
        }
    }
    
    if (!$dataDir) {
        throw new Exception("No writable directory found");
    }

    $dbPath = $dataDir . '/games.db';
    error_log("DB Path: $dbPath");

    // Copiar semilla si existe
    if (!file_exists($dbPath)) {
        foreach ([__DIR__ . '/private/games.db', __DIR__ . '/private/game.db'] as $seed) {
            if (file_exists($seed)) {
                @copy($seed, $dbPath);
                @chmod($dbPath, 0666);
                break;
            }
        }
    }

    // Crear archivo vacío
    if (!file_exists($dbPath)) {
        @file_put_contents($dbPath, '');
        @chmod($dbPath, 0666);
    }
    
    if (!file_exists($dbPath)) {
        throw new Exception("Cannot create DB file at $dbPath");
    }

    // Conectar
    try {
        $pdo = new PDO('sqlite:' . $dbPath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 30
        ]);
        
        $pdo->exec("PRAGMA journal_mode = WAL");
        $pdo->exec("PRAGMA synchronous = NORMAL");
        
    } catch (PDOException $e) {
        throw new Exception("SQLite connection error: " . $e->getMessage());
    }

    // Crear tabla
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
            )
        ");
    } catch (PDOException $e) {
        throw new Exception("Table creation error: " . $e->getMessage());
    }

    return $pdo;
}