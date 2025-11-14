<?php
function getDb(): PDO {
    // A Azure App Service, usar /home que és persistent
    $home = getenv('HOME') ?: '/home';
    
    // Intentar diferents ubicacions
    $possibleDirs = [
        $home . '/data',
        $home . '/site/data',
        '/tmp'  // No persistent però funcional
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
        throw new Exception("Cap directori escribible trobat");
    }

    $dbPath = $dataDir . '/games.db';
    error_log("Ruta BD: $dbPath");

    // Copiar llavor si existeix
    if (!file_exists($dbPath)) {
        foreach ([__DIR__ . '/private/games.db', __DIR__ . '/private/game.db'] as $seed) {
            if (file_exists($seed)) {
                @copy($seed, $dbPath);
                @chmod($dbPath, 0666);
                break;
            }
        }
    }

    // Crear arxiu buit
    if (!file_exists($dbPath)) {
        @file_put_contents($dbPath, '');
        @chmod($dbPath, 0666);
    }
    
    if (!file_exists($dbPath)) {
        throw new Exception("No es pot crear el fitxer BD a $dbPath");
    }

    // Connectar
    try {
        $pdo = new PDO('sqlite:' . $dbPath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 30
        ]);
        
        $pdo->exec("PRAGMA journal_mode = WAL");
        $pdo->exec("PRAGMA synchronous = NORMAL");
        
    } catch (PDOException $e) {
        throw new Exception("Error de connexió SQLite: " . $e->getMessage());
    }

    // Crear taules
    try {
        // Taula d'usuaris
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                user_id TEXT PRIMARY KEY,
                username TEXT NOT NULL,
                email TEXT,
                games_played INTEGER DEFAULT 0,
                games_won INTEGER DEFAULT 0,
                total_score INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                last_login DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        // Taula de jocs
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS games (
                game_id TEXT PRIMARY KEY,
                player1_id TEXT,
                player2_id TEXT,
                platforms TEXT,
                point_platform TEXT,
                player1_x INTEGER DEFAULT 50,
                player1_y INTEGER DEFAULT 550,
                player2_x INTEGER DEFAULT 350,
                player2_y INTEGER DEFAULT 550,
                player1_score INTEGER DEFAULT 0,
                player2_score INTEGER DEFAULT 0,
                winner_id TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                finished_at DATETIME,
                FOREIGN KEY (player1_id) REFERENCES users(user_id),
                FOREIGN KEY (player2_id) REFERENCES users(user_id),
                FOREIGN KEY (winner_id) REFERENCES users(user_id)
            )
        ");
        
        // Taula d'historial
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS game_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                game_id TEXT NOT NULL,
                player_id TEXT NOT NULL,
                score INTEGER NOT NULL,
                won BOOLEAN NOT NULL,
                played_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (game_id) REFERENCES games(game_id),
                FOREIGN KEY (player_id) REFERENCES users(user_id)
            )
        ");
        
    } catch (PDOException $e) {
        throw new Exception("Error crear taules: " . $e->getMessage());
    }

    return $pdo;
}

// Funció per agafar o crear usuari des d'Azure AD
function getOrCreateUser($pdo, $userId, $userName, $userEmail) {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE user_id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        // Crear nou usuari
        $stmt = $pdo->prepare('INSERT INTO users (user_id, username, email) VALUES (?, ?, ?)');
        $stmt->execute([$userId, $userName, $userEmail]);
        
        $stmt = $pdo->prepare('SELECT * FROM users WHERE user_id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
    } else {
        // Actualitzar última connexió
        $stmt = $pdo->prepare('UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE user_id = ?');
        $stmt->execute([$userId]);
    }
    
    return $user;
}

// Funció per agafar info d'autenticació d'Azure
function getAzureAuthInfo() {
    $userId = $_SERVER['HTTP_X_MS_CLIENT_PRINCIPAL_ID'] ?? 
              $_SERVER['X_MS_CLIENT_PRINCIPAL_ID'] ?? null;
    
    $userName = $_SERVER['HTTP_X_MS_CLIENT_PRINCIPAL_NAME'] ?? 
                $_SERVER['X_MS_CLIENT_PRINCIPAL_NAME'] ?? 
                'Usuari';
    
    $userEmail = $_SERVER['HTTP_X_MS_CLIENT_PRINCIPAL_EMAIL'] ?? 
                 $_SERVER['X_MS_CLIENT_PRINCIPAL_EMAIL'] ?? null;
    
    return [
        'isAuthenticated' => !empty($userId),
        'userId' => $userId,
        'userName' => $userName,
        'userEmail' => $userEmail
    ];
}