<?php
require_once __DIR__ . '/db.php';

// Agafar info d'autenticació
$authInfo = getAzureAuthInfo();

if (!$authInfo['isAuthenticated']) {
    // Redirigir a login si no està autenticat
    header('Location: /.auth/login/aad?post_login_redirect_uri=/perfil.php');
    exit;
}

// Agafar dades de l'usuari
$db = getDb();
$user = getOrCreateUser($db, $authInfo['userId'], $authInfo['userName'], $authInfo['userEmail']);

// Agafar historial de partides
$stmt = $db->prepare('
    SELECT gh.*, g.player1_id, g.player2_id, g.winner_id, g.finished_at
    FROM game_history gh
    JOIN games g ON gh.game_id = g.game_id
    WHERE gh.player_id = ?
    ORDER BY gh.played_at DESC
    LIMIT 20
');
$stmt->execute([$authInfo['userId']]);
$history = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ca">
<head>
  <meta charset="UTF-8">
  <title>El Meu Perfil</title>
  <style>
    body {
      margin: 0;
      padding: 20px;
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      min-height: 100vh;
    }
    .container {
      max-width: 1000px;
      margin: 0 auto;
    }
    .header {
      background: white;
      padding: 20px;
      border-radius: 15px;
      margin-bottom: 20px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    }
    .user-profile {
      display: flex;
      align-items: center;
      gap: 20px;
    }
    .user-avatar-large {
      width: 80px;
      height: 80px;
      border-radius: 50%;
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      color: white;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: bold;
      font-size: 32px;
    }
    .user-details h1 {
      margin: 0 0 5px 0;
      color: #333;
    }
    .user-details p {
      margin: 0;
      color: #666;
      font-size: 14px;
    }
    .nav-buttons {
      display: flex;
      gap: 10px;
    }
    .btn {
      padding: 10px 20px;
      border: none;
      border-radius: 8px;
      cursor: pointer;
      text-decoration: none;
      font-size: 14px;
      transition: all 0.3s;
      display: inline-block;
    }
    .btn-primary {
      background: #0078d4;
      color: white;
    }
    .btn-primary:hover {
      background: #106ebe;
    }
    .btn-secondary {
      background: #f0f0f0;
      color: #333;
    }
    .btn-secondary:hover {
      background: #e0e0e0;
    }
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 20px;
      margin-bottom: 20px;
    }
    .stat-card {
      background: white;
      padding: 20px;
      border-radius: 15px;
      box-shadow: 0 4px 6px rgba(0,0,0,0.1);
      text-align: center;
    }
    .stat-value {
      font-size: 36px;
      font-weight: bold;
      color: #667eea;
      margin: 10px 0;
    }
    .stat-label {
      color: #666;
      font-size: 14px;
      text-transform: uppercase;
      letter-spacing: 1px;
    }
    .history-section {
      background: white;
      padding: 25px;
      border-radius: 15px;
      box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    }
    .history-section h2 {
      margin: 0 0 20px 0;
      color: #333;
    }
    .history-table {
      width: 100%;
      border-collapse: collapse;
    }
    .history-table th {
      background: #f5f5f5;
      padding: 12px;
      text-align: left;
      font-weight: 600;
      color: #555;
    }
    .history-table td {
      padding: 12px;
      border-bottom: 1px solid #eee;
    }
    .history-table tr:hover {
      background: #f9f9f9;
    }
    .badge-win {
      background: #28a745;
      color: white;
      padding: 4px 12px;
      border-radius: 12px;
      font-size: 12px;
      font-weight: bold;
    }
    .badge-loss {
      background: #dc3545;
      color: white;
      padding: 4px 12px;
      border-radius: 12px;
      font-size: 12px;
      font-weight: bold;
    }
    .score-display {
      font-weight: bold;
      color: #667eea;
    }
    .no-history {
      text-align: center;
      padding: 40px;
      color: #999;
    }
    .winrate {
      font-size: 24px;
      font-weight: bold;
    }
  </style>
</head>
<body>
  <div class="container">
    <div class="header">
      <div class="user-profile">
        <div class="user-avatar-large"><?php echo strtoupper(substr($user['username'], 0, 1)); ?></div>
        <div class="user-details">
          <h1><?php echo htmlspecialchars($user['username']); ?></h1>
          <p>Membre des de <?php echo date('d/m/Y', strtotime($user['created_at'])); ?></p>
          <p>Última connexió: <?php echo date('d/m/Y H:i', strtotime($user['last_login'])); ?></p>
        </div>
      </div>
      <div class="nav-buttons">
        <a href="/index.html" class="btn btn-primary">🎮 Jugar</a>
        <a href="/.auth/logout?post_logout_redirect_uri=/login.php" class="btn btn-secondary">Tancar Sessió</a>
      </div>
    </div>

    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-label">Partides Jugades</div>
        <div class="stat-value"><?php echo $user['games_played']; ?></div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Victòries</div>
        <div class="stat-value"><?php echo $user['games_won']; ?></div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Winrate</div>
        <div class="stat-value winrate">
          <?php echo $user['games_played'] > 0 ? round(($user['games_won'] / $user['games_played']) * 100) : 0; ?>%
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Puntuació Total</div>
        <div class="stat-value"><?php echo $user['total_score']; ?></div>
      </div>
    </div>

    <div class="history-section">
      <h2>📜 Historial de Partides</h2>
      
      <?php if (count($history) === 0): ?>
        <div class="no-history">
          <p>Encara no has jugat cap partida.</p>
          <a href="/index.html" class="btn btn-primary" style="margin-top: 20px;">Jugar Ara</a>
        </div>
      <?php else: ?>
        <table class="history-table">
          <thead>
            <tr>
              <th>Data</th>
              <th>Resultat</th>
              <th>Puntuació</th>
              <th>Oponent</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($history as $game): ?>
              <tr>
                <td><?php echo date('d/m/Y H:i', strtotime($game['played_at'])); ?></td>
                <td>
                  <?php if ($game['won']): ?>
                    <span class="badge-win">VICTÒRIA</span>
                  <?php else: ?>
                    <span class="badge-loss">DERROTA</span>
                  <?php endif; ?>
                </td>
                <td class="score-display"><?php echo $game['score']; ?> punts</td>
                <td>
                  <?php 
                    if ($game['player1_id'] !== $user['user_id'] && $game['player1_id']) {
                      echo "Jugador 1";
                    } elseif ($game['player2_id'] !== $user['user_id'] && $game['player2_id']) {
                      echo "Jugador 2";
                    } else {
                      echo "-";
                    }
                  ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>