<?php
session_start();

// Connectar a la base de dades SQLite
try {
    $db = new PDO('sqlite:../private/games.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['error' => 'Connexió amb la base de dades fallida: ' . $e->getMessage()]);
    exit();
}

$accio = isset($_GET['action']) ? $_GET['action'] : '';

// Función para generar plataformas estáticas (fijas en el mapa)
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

// Función para generar una plataforma de puntos aleatoria
function generarPlataformaPuntos() {
    $ancho_juego = 400;
    $alto_juego = 600;
    $puntos = rand(0, 1) == 0 ? 10 : 20; // Aleatoriamente +10 o +20
    
    // Plataformas estáticas para verificar distancia
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
    $distancia_minima = 40; // Distancia mínima en píxeles
    
    do {
        $nueva_x = rand(20, $ancho_juego - 60);
        $nueva_y = rand(100, $alto_juego - 150);
        $valida = true;
        
        // Verificar que no esté muy cerca de ninguna plataforma estática
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
        'width' => 40, // Más pequeña que las estáticas
        'points' => $puntos,
        'active' => true
    ];
}

switch ($accio) {
    case 'join':
        if (!isset($_SESSION['player_id'])) {
            $_SESSION['player_id'] = uniqid();
        }

        $player_id = $_SESSION['player_id'];
        $game_id = null;

        // Intentar unir-se a un joc existent on player2 sigui null
        $stmt = $db->prepare('SELECT game_id FROM games WHERE player2 IS NULL LIMIT 1');
        $stmt->execute();
        $joc_existent = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($joc_existent) {
            // Unir-se al joc existent com a player2
            $game_id = $joc_existent['game_id'];
            $stmt = $db->prepare('UPDATE games SET player2 = :player_id WHERE game_id = :game_id');
            $stmt->bindValue(':player_id', $player_id);
            $stmt->bindValue(':game_id', $game_id);
            $stmt->execute();
        } else {
            // Crear un nou joc com a player1
            $game_id = uniqid();
            $platforms_estaticas = generarPlataformasEstaticas();
            $platform_puntos = json_encode(generarPlataformaPuntos());
            
            // Verificar si las columnas existen
            try {
                $stmt = $db->prepare('INSERT INTO games (game_id, player1, platforms, point_platform, player1_x, player1_y, player2_x, player2_y, player1_score, player2_score) VALUES (:game_id, :player_id, :platforms, :point_platform, 50, 550, 350, 550, 0, 0)');
                $stmt->bindValue(':game_id', $game_id);
                $stmt->bindValue(':player_id', $player_id);
                $stmt->bindValue(':platforms', $platforms_estaticas);
                $stmt->bindValue(':point_platform', $platform_puntos);
                $stmt->execute();
            } catch (PDOException $e) {
                // Si falla, intentar sin las columnas nuevas
                $stmt = $db->prepare('INSERT INTO games (game_id, player1) VALUES (:game_id, :player_id)');
                $stmt->bindValue(':game_id', $game_id);
                $stmt->bindValue(':player_id', $player_id);
                $stmt->execute();
                
                // Actualizar con las columnas nuevas
                $stmt = $db->prepare('UPDATE games SET platforms = :platforms, point_platform = :point_platform, player1_x = 50, player1_y = 550, player2_x = 350, player2_y = 550, player1_score = 0, player2_score = 0 WHERE game_id = :game_id');
                $stmt->bindValue(':platforms', $platforms_estaticas);
                $stmt->bindValue(':point_platform', $platform_puntos);
                $stmt->bindValue(':game_id', $game_id);
                $stmt->execute();
            }
        }

        echo json_encode(['game_id' => $game_id, 'player_id' => $player_id]);
        break;

    case 'status':
        $game_id = $_GET['game_id'];
        $stmt = $db->prepare('SELECT * FROM games WHERE game_id = :game_id');
        $stmt->bindValue(':game_id', $game_id);
        $stmt->execute();
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

            echo json_encode([
                'player1' => $joc['player1'],
                'player2' => $joc['player2'],
                'player1_x' => isset($joc['player1_x']) ? floatval($joc['player1_x']) : 50,
                'player1_y' => isset($joc['player1_y']) ? floatval($joc['player1_y']) : 550,
                'player2_x' => isset($joc['player2_x']) ? floatval($joc['player2_x']) : 350,
                'player2_y' => isset($joc['player2_y']) ? floatval($joc['player2_y']) : 550,
                'points' => [
                    isset($joc['player1_score']) ? $joc['player1_score'] : 0,
                    isset($joc['player2_score']) ? $joc['player2_score'] : 0
                ],
                'winner' => $joc['winner'],
                'platforms' => $plataformas,
                'point_platform' => $point_platform
            ]);
        }
        break;

    case 'update':
        $game_id = $_GET['game_id'];
        $player_id = $_SESSION['player_id'];
        $player_x = floatval($_GET['x']);
        $player_y = floatval($_GET['y']);

        $stmt = $db->prepare('SELECT * FROM games WHERE game_id = :game_id');
        $stmt->bindValue(':game_id', $game_id);
        $stmt->execute();
        $joc = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$joc || $joc['winner']) {
            echo json_encode(['error' => 'Joc finalitzat o no trobat']);
            break;
        }

        // Determinar quin jugador ha fet l'update
        if ($joc['player1'] === $player_id) {
            $stmt = $db->prepare('UPDATE games SET player1_x = :x, player1_y = :y WHERE game_id = :game_id');
            $stmt->bindValue(':x', $player_x);
            $stmt->bindValue(':y', $player_y);
            $stmt->bindValue(':game_id', $game_id);
            $stmt->execute();
        } elseif ($joc['player2'] === $player_id) {
            $stmt = $db->prepare('UPDATE games SET player2_x = :x, player2_y = :y WHERE game_id = :game_id');
            $stmt->bindValue(':x', $player_x);
            $stmt->bindValue(':y', $player_y);
            $stmt->bindValue(':game_id', $game_id);
            $stmt->execute();
        }

        echo json_encode(['success' => true]);
        break;

    case 'collect':
        $game_id = $_GET['game_id'];
        $player_id = $_SESSION['player_id'];

        // Iniciar transacción para evitar race conditions
        $db->beginTransaction();

        try {
            $stmt = $db->prepare('SELECT * FROM games WHERE game_id = :game_id');
            $stmt->bindValue(':game_id', $game_id);
            $stmt->execute();
            $joc = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$joc || $joc['winner']) {
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

            // Desactivar la plataforma INMEDIATAMENTE para evitar que otro jugador la recoja
            $point_platform['active'] = false;
            $stmt = $db->prepare('UPDATE games SET point_platform = :point_platform WHERE game_id = :game_id');
            $stmt->bindValue(':point_platform', json_encode($point_platform));
            $stmt->bindValue(':game_id', $game_id);
            $stmt->execute();

            // Determinar quin jugador ha recollit la plataforma
            $puntos_ganados = $point_platform['points'];
            
            if ($joc['player1'] === $player_id) {
                $nuevo_score = $joc['player1_score'] + $puntos_ganados;
                $stmt = $db->prepare('UPDATE games SET player1_score = :score WHERE game_id = :game_id');
                $stmt->bindValue(':score', $nuevo_score);
                $stmt->bindValue(':game_id', $game_id);
                $stmt->execute();
                
                // Comprovar si hi ha un guanyador
                if ($nuevo_score >= 100) {
                    $stmt = $db->prepare('UPDATE games SET winner = :player_id WHERE game_id = :game_id');
                    $stmt->bindValue(':player_id', $player_id);
                    $stmt->bindValue(':game_id', $game_id);
                    $stmt->execute();
                }
            } elseif ($joc['player2'] === $player_id) {
                $nuevo_score = $joc['player2_score'] + $puntos_ganados;
                $stmt = $db->prepare('UPDATE games SET player2_score = :score WHERE game_id = :game_id');
                $stmt->bindValue(':score', $nuevo_score);
                $stmt->bindValue(':game_id', $game_id);
                $stmt->execute();
                
                // Comprovar si hi ha un guanyador
                if ($nuevo_score >= 100) {
                    $stmt = $db->prepare('UPDATE games SET winner = :player_id WHERE game_id = :game_id');
                    $stmt->bindValue(':player_id', $player_id);
                    $stmt->bindValue(':game_id', $game_id);
                    $stmt->execute();
                }
            }

            // Generar nueva plataforma de puntos después de un pequeño delay
            // Esperar 1 segundo antes de generar la nueva
            $nueva_plataforma = generarPlataformaPuntos();
            $stmt = $db->prepare('UPDATE games SET point_platform = :point_platform WHERE game_id = :game_id');
            $stmt->bindValue(':point_platform', json_encode($nueva_plataforma));
            $stmt->bindValue(':game_id', $game_id);
            $stmt->execute();

            $db->commit();
            echo json_encode(['success' => true, 'points' => $puntos_ganados]);
        } catch (Exception $e) {
            $db->rollBack();
            echo json_encode(['error' => 'Error al recoger plataforma']);
        }
        break;
}