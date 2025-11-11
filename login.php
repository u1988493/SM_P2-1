<!DOCTYPE html>
<html lang="ca">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Carrera de Plataformes - Login</title>
  <style>
    body {
      margin: 0;
      padding: 0;
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      display: flex;
      justify-content: center;
      align-items: center;
      min-height: 100vh;
    }
    .login-container {
      background: white;
      padding: 40px;
      border-radius: 15px;
      box-shadow: 0 10px 40px rgba(0,0,0,0.2);
      text-align: center;
      max-width: 400px;
      width: 90%;
    }
    h1 {
      color: #333;
      margin-bottom: 10px;
    }
    p {
      color: #666;
      margin-bottom: 30px;
    }
    .btn-login {
      background: #0078d4;
      color: white;
      border: none;
      padding: 15px 40px;
      font-size: 16px;
      border-radius: 5px;
      cursor: pointer;
      text-decoration: none;
      display: inline-block;
      transition: background 0.3s;
      margin: 5px;
    }
    .btn-login:hover {
      background: #106ebe;
    }
    .btn-guest {
      background: #6c757d;
      color: white;
      border: none;
      padding: 15px 40px;
      font-size: 16px;
      border-radius: 5px;
      cursor: pointer;
      text-decoration: none;
      display: inline-block;
      transition: background 0.3s;
      margin: 5px;
    }
    .btn-guest:hover {
      background: #5a6268;
    }
    .game-preview {
      margin: 30px 0;
      padding: 20px;
      background: #f5f5f5;
      border-radius: 10px;
    }
    .features {
      text-align: left;
      margin: 20px 0;
    }
    .features li {
      margin: 10px 0;
      color: #555;
    }
    .divider {
      margin: 20px 0;
      color: #999;
      font-size: 14px;
    }
  </style>
</head>
<body>
  <div class="login-container">
    <h1>🎮 Carrera de Plataformes</h1>
    <p>Joc multijugador en temps real</p>
    
    <div class="game-preview">
      <h3>Característiques:</h3>
      <ul class="features">
        <li>🏃 Partides 1v1 en temps real</li>
        <li>⭐ Recull plataformes daurades</li>
        <li>🏆 Primer a 100 punts guanya</li>
        <li>📊 Estadístiques i historial (usuaris registrats)</li>
        <li>🔒 Autenticació segura amb Microsoft</li>
      </ul>
    </div>
    
    <a href="/.auth/login/aad?post_login_redirect_uri=/index.html" class="btn-login">
      Iniciar Sessió amb Microsoft
    </a>
    
    <div class="divider">--- o bé ---</div>
    
    <a href="/index.html" class="btn-guest">
      Jugar com a Convidat
    </a>
    
    <p style="margin-top: 30px; font-size: 12px; color: #999;">
      Registra't per guardar les teves estadístiques
    </p>
  </div>
</body>
</html>