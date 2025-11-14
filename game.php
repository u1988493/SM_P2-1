<?php
// game.php - VersiÃ³n con autenticaciÃ³n Azure
error_reporting(E_ALL);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

session_start();

// Conectar a la base de datos
require_once __DIR__ . '/db.php';

try {
    $db = getDb();
} catch (Exception $e) {
    echo json_encode(['error' => 'ConnexiÃ³ amb la base de dades fallida: ' . $e->getMessage()]);
    exit();
}

// Obtener informaciÃ³n de autenticaciÃ³n
$authInfo = getAzureAuthInfo();

// Si estÃ¡ autenticado con Azure, usar ese ID
if ($authInfo['isAuthenticated']) {
    $player_id = $authInfo['userId'];
    $_SESSION['player_id'] = $player_id;
    
    // Crear o actualizar usuario
    $user = getOrCreateUser($db, $player_id, $authInfo['userName'], $authInfo['userEmail']);
} else {
    // Fallback: usar cookie para mantener ID consistente entre requests
    if (!isset($_COOKIE['guest_player_id'])) {
        $player_id = 'guest_' . uniqid() . '_' . mt_rand(10000, 99999);
        setcookie('guest_player_id', $player_id, time() + 3600 * 24, '/'); // 24 horas
        $_COOKIE['guest_player_id'] = $player_id;
    } else {
        $player_id = $_COOKIE['guest_player_id'];
    }
}

$accio = isset($_GET['action']) ? $_GET['action'] : '';

// FunciÃ³n para generar plataformas estÃ¡ticas (diseÃ±o original)
function generarPlataformasEstaticas() {
    $plataformas = [
        ['x' => 50, 'y' => 480, 'width' => 80],
        ['x' => 270, 'y' => 480, 'width' => 80],
        ['x' => 160, 'y' => 400, 'width' => 80],
        ['x' => 30, 'y' => 320, 'width' => 80],
        ['x' => 290, 'y' => 320, 'width' => 80],
        ['x' => 160, 'y' => 240, 'width' => 80],
        ['x' => 80, 'y' => 160, 'width' => 80],
        ['x' => 240, 'y' => 160, 'width' => 80],
        ['x' => 160, 'y' => 80, 'width' => 80]
    ];
    
    return json_encode($plataformas);
}

// FunciÃ³n para generar una plataforma de puntos aleatoria
function generarPlataformaPuntos() {
    $ancho_juego = 400;
    $alto_juego = 600;
    $puntos = rand(0, 1) == 0 ? 10 : 20;
    
    $plataformas_estaticas = [
        ['x' => 50, 'y' => 480, 'width' => 80],
        ['x' => 270, 'y' => 480, 'width' => 80],
        ['x' => 160, 'y' => 400, 'width' => 80],
        ['x' => 30, 'y' => 320, 'width' => 80],
        ['x' => 290, 'y' => 320, 'width' => 80],
        ['x' => 160, 'y' => 240, 'width' => 80],
        ['x' => 80, 'y' => 160, 'width' => 80],
        ['x' => 240, 'y' => 160, 'width' => 80],
        ['x' => 160, 'y' => 80, 'width' => 80]
    ];
    
    $intentos = 0;
    $max_intentos = 50;
    $distancia_minima = 40;
    
    do {
        $nueva_x = rand(20, $ancho_juego - 60);
        $nueva_y = rand(100, $alto_juego - 150);
        $valida = true;
        
        foreach ($plataformas_estaticas as $plat) {
            $distancia_x = abs(($nueva_x + 20) - ($plat['x'] + $plat['width'] / 2));
            $distancia_y = abs($nueva_y - $plat['y']);
            
            if ($distancia_x < $distancia_minima && $distancia_y < $distancia_minima) {
                $valida = false;
                break;
            }
        }
        
        $intentos++;
    } while (!$valida && $intentos < $max_intentos);
    
    return [
        'x' => $nueva_x,
        'y' => $nueva_y,
        'width' => 40,
        'points' => $puntos,
        'active' => true
    ];
}

function finalizarPartida($db, $gameId, $winnerId, $player1Id, $player2Id, $score1, $score2) {
    $stmt = $db->prepare('UPDATE games SET winner_id = ?, finished_at = CURRENT_TIMESTAMP WHERE game_id = ?');
    $stmt->execute([$winnerId, $gameId]);
    
    // Actualizar estadÃ­sticas del jugador 1
    if ($player1Id && strpos($player1Id, 'guest_') !== 0) {
        $won1 = ($player1Id === $winnerId) ? 1 : 0;
        $stmt = $db->prepare('UPDATE users SET games_played = games_played + 1, games_won = games_won + ?, total_score = total_score + ? WHERE user_id = ?');
        $stmt->execute([$won1, $score1, $player1Id]);
        
        $stmt = $db->prepare('INSERT INTO game_history (game_id, player_id, score, won) VALUES (?, ?, ?, ?)');
        $stmt->execute([$gameId, $player1Id, $score1, $won1]);
    }
    
    // Actualizar estadÃ­sticas del jugador 2
    if ($player2Id && strpos($player2Id, 'guest_') !== 0) {
        $won2 = ($player2Id === $winnerId) ? 1 : 0;
        $stmt = $db->prepare('UPDATE users SET games_played = games_played + 1, games_won = games_won + ?, total_score = total_score + ? WHERE user_id = ?');
        $stmt->execute([$won2, $score2, $player2Id]);
        
        $stmt = $db->prepare('INSERT INTO game_history (game_id, player_id, score, won) VALUES (?, ?, ?, ?)');
        $stmt->execute([$gameId, $player2Id, $score2, $won2]);
    }
}

switch ($accio) {
    case 'join':
        $game_id = null;
        
        // Obtener datos del POST (puede incluir room_code)
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $room_code = isset($input['room_code']) ? strtoupper(trim($input['room_code'])) : null;

        if ($room_code) {
            // MODO LOCAL: Buscar o crear sala con cÃ³digo
            $stmt = $db->prepare('SELECT game_id, player1_id, player2_id FROM games WHERE game_id = ? AND winner_id IS NULL');
            $stmt->execute([$room_code]);
            $joc_existent = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($joc_existent) {
                // La sala existe, unirse como player2
                if ($joc_existent['player2_id']) {
                    // Sala llena
                    echo json_encode(['error' => 'Sala plena']);
                    break;
                }
                
                $game_id = $joc_existent['game_id'];
                $stmt = $db->prepare('UPDATE games SET player2_id = ? WHERE game_id = ?');
                $stmt->execute([$player_id, $game_id]);
            } else {
                // Crear nueva sala con el cÃ³digo como game_id
                $game_id = $room_code;
                $platforms_estaticas = generarPlataformasEstaticas();
                $platform_puntos = json_encode(generarPlataformaPuntos());
                
                $stmt = $db->prepare('INSERT INTO games (game_id, player1_id, platforms, point_platform, player1_x, player1_y, player2_x, player2_y, player1_score, player2_score) VALUES (?, ?, ?, ?, 50, 550, 350, 550, 0, 0)');
                $stmt->execute([$game_id, $player_id, $platforms_estaticas, $platform_puntos]);
            }
        } else {
            // MODO ONLINE: Matchmaking automÃ¡tico
            // Intentar unirse a un juego existente donde player2 sea null y NO tenga room code
            $stmt = $db->prepare('SELECT game_id FROM games WHERE player2_id IS NULL AND winner_id IS NULL AND LENGTH(game_id) > 10 LIMIT 1');
            $stmt->execute();
            $joc_existent = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($joc_existent) {
                // Unirse al juego existente como player2
                $game_id = $joc_existent['game_id'];
                $stmt = $db->prepare('UPDATE games SET player2_id = ? WHERE game_id = ?');
                $stmt->execute([$player_id, $game_id]);
            } else {
                // Crear un nuevo juego como player1
                $game_id = uniqid() . uniqid(); // ID largo para diferenciar de cÃ³digos de sala
                $platforms_estaticas = generarPlataformasEstaticas();
                $platform_puntos = json_encode(generarPlataformaPuntos());
                
                $stmt = $db->prepare('INSERT INTO games (game_id, player1_id, platforms, point_platform, player1_x, player1_y, player2_x, player2_y, player1_score, player2_score) VALUES (?, ?, ?, ?, 50, 550, 350, 550, 0, 0)');
                $stmt->execute([$game_id, $player_id, $platforms_estaticas, $platform_puntos]);
            }
        }

        echo json_encode(['game_id' => $game_id, 'player_id' => $player_id, 'room_code' => $room_code]);
        break;

    case 'status':
        $game_id = $_GET['game_id'];
        $stmt = $db->prepare('SELECT * FROM games WHERE game_id = ?');
        $stmt->execute([$game_id]);
        $joc = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$joc) {
            echo json_encode(['error' => 'Joc no trobat']);
        } else {
            $plataformas = json_decode($joc['platforms'], true);
            if (!$plataformas) {
                $plataformas = json_decode(generarPlataformasEstaticas(), true);
            }
            
            $point_platform = isset($joc['point_platform']) ? json_decode($joc['point_platform'], true) : null;
            if (!$point_platform) {
                $point_platform = generarPlataformaPuntos();
            }
            
            // Obtener nombres de usuarios
            $player1_name = 'Jugador 1';
            $player2_name = 'Esperant...';
            
            if ($joc['player1_id']) {
                $stmt = $db->prepare('SELECT username FROM users WHERE user_id = ?');
                $stmt->execute([$joc['player1_id']]);
                $p1 = $stmt->fetch();
                if ($p1) $player1_name = $p1['username'];
            }
            
            if ($joc['player2_id']) {
                $stmt = $db->prepare('SELECT username FROM users WHERE user_id = ?');
                $stmt->execute([$joc['player2_id']]);
                $p2 = $stmt->fetch();
                if ($p2) $player2_name = $p2['username'];
            }

            echo json_encode([
                'player1' => $joc['player1_id'],
                'player2' => $joc['player2_id'],
                'player1_name' => $player1_name,
                'player2_name' => $player2_name,
                'player1_x' => isset($joc['player1_x']) ? floatval($joc['player1_x']) : 50,
                'player1_y' => isset($joc['player1_y']) ? floatval($joc['player1_y']) : 550,
                'player2_x' => isset($joc['player2_x']) ? floatval($joc['player2_x']) : 350,
                'player2_y' => isset($joc['player2_y']) ? floatval($joc['player2_y']) : 550,
                'points' => [
                    isset($joc['player1_score']) ? $joc['player1_score'] : 0,
                    isset($joc['player2_score']) ? $joc['player2_score'] : 0
                ],
                'winner' => $joc['winner_id'],
                'platforms' => $plataformas,
                'point_platform' => $point_platform
            ]);
        }
        break;

    case 'update':
        $game_id = $_GET['game_id'];
        $player_x = floatval($_GET['x']);
        $player_y = floatval($_GET['y']);

        $stmt = $db->prepare('SELECT * FROM games WHERE game_id = ?');
        $stmt->execute([$game_id]);
        $joc = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$joc || $joc['winner_id']) {
            echo json_encode(['error' => 'Joc finalitzat o no trobat']);
            break;
        }

        // Determinar quÃ© jugador hizo el update
        if ($joc['player1_id'] === $player_id) {
            $stmt = $db->prepare('UPDATE games SET player1_x = ?, player1_y = ? WHERE game_id = ?');
            $stmt->execute([$player_x, $player_y, $game_id]);
        } elseif ($joc['player2_id'] === $player_id) {
            $stmt = $db->prepare('UPDATE games SET player2_x = ?, player2_y = ? WHERE game_id = ?');
            $stmt->execute([$player_x, $player_y, $game_id]);
        }

        echo json_encode(['success' => true]);
        break;

    case 'collect':
        $game_id = $_GET['game_id'];

        // Iniciar transacciÃ³n
        $db->beginTransaction();

        try {
            $stmt = $db->prepare('SELECT * FROM games WHERE game_id = ?');
            $stmt->execute([$game_id]);
            $joc = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$joc || $joc['winner_id']) {
                $db->rollBack();
                echo json_encode(['error' => 'Joc finalitzat o no trobat']);
                break;
            }

            $point_platform = json_decode($joc['point_platform'], true);
            
            if (!$point_platform || !$point_platform['active']) {
                $db->rollBack();
                echo json_encode(['error' => 'Plataforma no disponible']);
                break;
            }

            // Desactivar la plataforma INMEDIATAMENTE
            $point_platform['active'] = false;
            $stmt = $db->prepare('UPDATE games SET point_platform = ? WHERE game_id = ?');
            $stmt->execute([json_encode($point_platform), $game_id]);

            // Determinar quÃ© jugador recogiÃ³ la plataforma
            $puntos_ganados = $point_platform['points'];
            
            if ($joc['player1_id'] === $player_id) {
                $nuevo_score = $joc['player1_score'] + $puntos_ganados;
                $stmt = $db->prepare('UPDATE games SET player1_score = ? WHERE game_id = ?');
                $stmt->execute([$nuevo_score, $game_id]);
                
                // Comprobar ganador
                if ($nuevo_score >= 100) {
                    finalizarPartida($db, $game_id, $player_id, $joc['player1_id'], $joc['player2_id'], $nuevo_score, $joc['player2_score']);
                }
            } elseif ($joc['player2_id'] === $player_id) {
                $nuevo_score = $joc['player2_score'] + $puntos_ganados;
                $stmt = $db->prepare('UPDATE games SET player2_score = ? WHERE game_id = ?');
                $stmt->execute([$nuevo_score, $game_id]);
                
                // Comprobar ganador
                if ($nuevo_score >= 100) {
                    finalizarPartida($db, $game_id, $player_id, $joc['player1_id'], $joc['player2_id'], $joc['player1_score'], $nuevo_score);
                }
            }

            // Generar nueva plataforma de puntos
            $nueva_plataforma = generarPlataformaPuntos();
            $stmt = $db->prepare('UPDATE games SET point_platform = ? WHERE game_id = ?');
            $stmt->execute([json_encode($nueva_plataforma), $game_id]);

            $db->commit();
            echo json_encode(['success' => true, 'points' => $puntos_ganados]);
        } catch (Exception $e) {
            $db->rollBack();
            echo json_encode(['error' => 'Error al recoger plataforma']);
        }
        break;
        
    default:
        echo json_encode(['error' => 'Acció no reconeguda']);
        break;
}