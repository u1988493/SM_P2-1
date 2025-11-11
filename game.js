// game.js - Lógica del juego (actualizado)

// ===== ESTADO GLOBAL =====
let isAuthenticated = false;
let currentUserName = 'Convidat';
let currentUserId = null;

let currentGameMode = null;     // 'online' | 'local' (solo se fija al pulsar)
let currentRoomCode = null;

let idJoc = null, idJugador = null;
let esPrimerJugador = false;
let guanyador = null;

let gameLoopStarted = false;
let gameWasRunning = false;     // Para detectar si ya había 2 jugadores y uno se fue
let lastUpdateTime = Date.now();
let lastServerUpdate = 0;
let statusPollTimer = null;

const canvas = document.getElementById('canvas');
const ctx = canvas.getContext('2d');

let jugador = {
  x: 50, y: 550, width: 30, height: 30,
  velocityY: 0, velocityX: 0,
  gravity: 0.6, jumpPower: -15, moveSpeed: 6,
  score: 0, color: '#FF6B6B', onGround: false
};

let oponent = { x: 350, y: 550, score: 0, color: '#4ECDC4' };
let plataformasEstaticas = [];
let plataformaPuntos = null;
let teclesPremes = {};
let lastJumpTime = 0;

let pingMs = 0, pingHistory = [];
const maxPingHistory = 10;

// ===== AUTENTICACIÓN (sin crear partida) =====
// Intento ligero de “quién soy” sin tocar /game.php?action=join
async function checkAuthentication() {
  try {
    // Si tienes un whoami real, úsalo:
    // const r = await fetch('/game.php?action=whoami');
    // const d = await r.json();

    // Fallback: intentamos leer el perfil en modo JSON si lo tienes.
    const r = await fetch('/perfil.php?whoami=1', { credentials: 'include' });
    if (r.ok) {
      const d = await r.json().catch(() => ({}));
      if (d && d.user_id) {
        isAuthenticated = true;
        currentUserId = d.user_id;
        currentUserName = d.user_name || 'Usuari';
      }
    }
  } catch (_) { /* guest */ }

  updateUserDisplay(currentUserName, isAuthenticated);
}

function updateUserDisplay(userName, authenticated) {
  document.getElementById('menuUserName').textContent = userName;
  document.getElementById('menuUserStatus').textContent = authenticated ? 'Usuari registrat' : 'Mode invitat';
  document.getElementById('menuUserAvatar').textContent = userName.charAt(0).toUpperCase();

  document.getElementById('menuLoginBtn').style.display = authenticated ? 'none' : 'inline-block';
  document.getElementById('menuPerfilBtn').style.display = authenticated ? 'inline-block' : 'none';

  document.getElementById('gameUserName').textContent = userName;
  document.getElementById('gameUserAvatar').textContent = userName.charAt(0).toUpperCase();
}

// ===== NAVEGACIÓN =====
function showScreen(screenId) {
  const screens = ['mainMenu', 'modeMenu', 'gameContainer', 'resultScreen'];
  screens.forEach(id => { document.getElementById(id).style.display = 'none'; });
  document.getElementById(screenId).style.display = (screenId === 'gameContainer') ? 'flex' : 'block';
}

function showModeMenu() {
  currentGameMode = null;         // aún no conectamos
  currentRoomCode = null;
  showScreen('modeMenu');
}

function backToMainMenu() {
  showScreen('mainMenu');
  closeLocalModal();
  resetGame();
}

// ===== MODO ONLINE =====
function startOnlineGame() {
  currentGameMode = 'online';
  currentRoomCode = null;
  document.getElementById('gameRoomInfo').textContent = 'Mode Online';
  showScreen('gameContainer');
  unirseAlJoc();
}

// ===== MODO LOCAL (códigos) =====
function showLocalModal() {
  document.getElementById('localModal').style.display = 'flex';
  document.getElementById('roomCodeInput').value = '';
  document.getElementById('roomCreatedPanel').style.display = 'none';
}
function closeLocalModal() {
  document.getElementById('localModal').style.display = 'none';
}

function generateRoomCode() {
  const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
  let code = '';
  for (let i = 0; i < 6; i++) code += chars.charAt(Math.floor(Math.random() * chars.length));
  return code;
}

async function createRoom() {
  currentGameMode = 'local';
  currentRoomCode = generateRoomCode();
  try {
    const response = await fetch('/game.php?action=join', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({ room_code: currentRoomCode })
    });
    const data = await response.json();
    if (data.error) { alert('Error creant la sala: ' + data.error); return; }

    idJoc = data.game_id;
    idJugador = data.player_id;

    document.getElementById('createdRoomCode').textContent = currentRoomCode;
    document.getElementById('roomCreatedPanel').style.display = 'block';
    document.getElementById('gameRoomInfo').textContent = `Sala: ${currentRoomCode}`;

    comprovarSalaLocal();
  } catch (e) { alert('Error creant la sala: ' + e.message); }
}

async function joinRoom() {
  const code = document.getElementById('roomCodeInput').value.trim().toUpperCase();
  if (!code || code.length !== 6) { alert('Si us plau, introdueix un codi de 6 caràcters'); return; }

  currentGameMode = 'local';
  currentRoomCode = code;

  try {
    const response = await fetch('/game.php?action=join', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({ room_code: code })
    });
    const data = await response.json();
    if (data.error) { alert('Error unint-se a la sala: ' + data.error); return; }

    idJoc = data.game_id;
    idJugador = data.player_id;

    document.getElementById('gameRoomInfo').textContent = `Sala: ${code}`;
    closeLocalModal();
    showScreen('gameContainer');
    comprovarEstatDelJoc();
  } catch (e) { alert('Error unint-se a la sala: ' + e.message); }
}

async function comprovarSalaLocal() {
  if (!idJoc) return;
  try {
    const response = await fetch(`/game.php?action=status&game_id=${idJoc}`);
    const joc = await response.json();
    if (joc.player1 && joc.player2) {
      closeLocalModal();
      showScreen('gameContainer');
      comprovarEstatDelJoc();
    } else {
      setTimeout(comprovarSalaLocal, 1000);
    }
  } catch {
    setTimeout(comprovarSalaLocal, 2000);
  }
}

// ===== CONEXIÓN / ESTADO DE PARTIDA =====
function unirseAlJoc() {
  const body = currentRoomCode ? JSON.stringify({ room_code: currentRoomCode }) : '{}';

  fetch('/game.php?action=join', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body
  })
  .then(r => r.json())
  .then(data => {
    if (data.error) throw new Error(data.error);
    idJoc = data.game_id;
    idJugador = data.player_id;
    // Intento de nombre
    try {
      fetch(`/game.php?action=status&game_id=${data.game_id}`)
        .then(r => r.json())
        .then(s => {
          if (s.player1 === data.player_id && s.player1_name) currentUserName = s.player1_name;
          else if (s.player2 === data.player_id && s.player2_name) currentUserName = s.player2_name;
          updateUserDisplay(currentUserName, true);
        });
    } catch {}
    comprovarEstatDelJoc();
  })
  .catch(err => {
    document.getElementById('estat').textContent = 'Error: ' + err.message;
    setTimeout(unirseAlJoc, 2000);
  });
}

function actualizarPing(latencia) {
  pingHistory.push(latencia);
  if (pingHistory.length > maxPingHistory) pingHistory.shift();
  pingMs = Math.round(pingHistory.reduce((a, b) => a + b, 0) / pingHistory.length);
  const pingEl = document.getElementById('ping');
  pingEl.textContent = `Ping: ${pingMs} ms`;
  pingEl.className = '';
  pingEl.classList.add(pingMs < 50 ? 'ping-good' : pingMs < 100 ? 'ping-medium' : 'ping-bad');
}

function clearStatusPoll() {
  if (statusPollTimer) { clearTimeout(statusPollTimer); statusPollTimer = null; }
}

function comprovarEstatDelJoc() {
  if (!idJoc) return;

  const t0 = performance.now();
  fetch(`/game.php?action=status&game_id=${idJoc}`)
    .then(r => { const t1 = performance.now(); actualizarPing(t1 - t0); return r.json(); })
    .then(joc => {
      if (joc.error) { document.getElementById('estat').textContent = joc.error; return; }

      plataformasEstaticas = joc.platforms || [];
      plataformaPuntos = joc.point_platform || null;
      guanyador = joc.winner || null;
      esPrimerJugador = (joc.player1 === idJugador);

      // puntuaciones/posiciones
      if (esPrimerJugador) {
        jugador.color = '#FF6B6B'; oponent.color = '#4ECDC4';
        oponent.x = joc.player2_x ?? 350; oponent.y = joc.player2_y ?? 550;
        oponent.score = (joc.points?.[1]) || 0; jugador.score = (joc.points?.[0]) || 0;
      } else {
        jugador.color = '#4ECDC4'; oponent.color = '#FF6B6B';
        oponent.x = joc.player1_x ?? 50; oponent.y = joc.player1_y ?? 550;
        oponent.score = (joc.points?.[0]) || 0; jugador.score = (joc.points?.[1]) || 0;
      }

      const p1Name = joc.player1_name || 'Jugador 1';
      const p2Name = joc.player2_name || 'Jugador 2';
      document.getElementById('puntuacio').textContent = `${p1Name}: ${joc.points?.[0] || 0} | ${p2Name}: ${joc.points?.[1] || 0}`;

      const bothThere = Boolean(joc.player1 && joc.player2);

      // Si el rival se fue después de haber empezado: cerramos y victoria para ti
      if (!guanyador && gameWasRunning && !bothThere) {
        // Si tu backend soporta abandonos, márcalo como ganador actual
        try { fetch(`/game.php?action=leave&game_id=${idJoc}`, { method: 'POST' }); } catch {}
        showResultScreen(true, jugador.score, oponent.score, { autoBack: true, reason: 'opponent_left' });
        return;
      }

      if (guanyador) {
        const won = (guanyador === idJugador);
        showResultScreen(won, jugador.score, oponent.score, { autoBack: true, reason: 'finished' });
        return;
      }

      if (bothThere) {
        document.getElementById('estat').textContent = 'Carrera en curs!';
        gameWasRunning = true;
        if (!gameLoopStarted) { gameLoopStarted = true; gameLoop(); }
      } else if (esPrimerJugador) {
        document.getElementById('estat').textContent = `Esperant ${p2Name}...`;
      } else {
        document.getElementById('estat').textContent = 'Començant...';
      }

      clearStatusPoll();
      statusPollTimer = setTimeout(comprovarEstatDelJoc, 100);
    })
    .catch(() => {
      clearStatusPoll();
      statusPollTimer = setTimeout(comprovarEstatDelJoc, 500);
    });
}

// ===== CONTROLES =====
document.addEventListener('keydown', (e) => {
  teclesPremes[e.key] = true;
  if ((e.key === ' ' || e.key === 'ArrowUp') && jugador.onGround) {
    const now = Date.now();
    if (now - lastJumpTime > 200) {
      jugador.velocityY = jugador.jumpPower;
      jugador.onGround = false;
      lastJumpTime = now;
    }
  }
});
document.addEventListener('keyup', (e) => { teclesPremes[e.key] = false; });

// ===== GAME LOOP =====
function gameLoop() {
  if (guanyador) return;
  const now = Date.now();
  const deltaTime = (now - lastUpdateTime) / 16.67;
  lastUpdateTime = now;
  update(deltaTime);
  render();
  requestAnimationFrame(gameLoop);
}

function update(deltaTime) {
  if (teclesPremes['ArrowLeft'] || teclesPremes['a'] || teclesPremes['A']) jugador.velocityX = -jugador.moveSpeed;
  else if (teclesPremes['ArrowRight'] || teclesPremes['d'] || teclesPremes['D']) jugador.velocityX = jugador.moveSpeed;
  else jugador.velocityX = 0;

  jugador.x += jugador.velocityX * deltaTime;
  if (jugador.x < 0) jugador.x = 0;
  if (jugador.x + jugador.width > canvas.width) jugador.x = canvas.width - jugador.width;

  jugador.velocityY += jugador.gravity * deltaTime;
  jugador.y += jugador.velocityY * deltaTime;
  jugador.onGround = false;

  if (jugador.y + jugador.height >= canvas.height - 10) {
    jugador.y = canvas.height - 10 - jugador.height;
    jugador.velocityY = 0;
    jugador.onGround = true;
  }

  if (jugador.velocityY > 0) {
    for (let plat of plataformasEstaticas) {
      if (jugador.y + jugador.height >= plat.y &&
          jugador.y + jugador.height <= plat.y + 15 &&
          jugador.x + jugador.width > plat.x &&
          jugador.x < plat.x + plat.width) {
        jugador.y = plat.y - jugador.height;
        jugador.velocityY = 0;
        jugador.onGround = true;
        break;
      }
    }

    if (plataformaPuntos && plataformaPuntos.active) {
      if (jugador.y + jugador.height >= plataformaPuntos.y &&
          jugador.y + jugador.height <= plataformaPuntos.y + 15 &&
          jugador.x + jugador.width > plataformaPuntos.x &&
          jugador.x < plataformaPuntos.x + plataformaPuntos.width) {
        jugador.y = plataformaPuntos.y - jugador.height;
        jugador.velocityY = 0;
        jugador.onGround = true;

        if (plataformaPuntos.active) {
          plataformaPuntos.active = false;
          fetch(`/game.php?action=collect&game_id=${idJoc}`)
            .then(r => r.json())
            .then(d => { if (d.success) jugador.score += d.points; })
            .catch(() => {});
        }
      }
    }
  }

  const now = Date.now();
  if (now - lastServerUpdate > 100 && idJoc) {
    lastServerUpdate = now;
    fetch(`/game.php?action=update&game_id=${idJoc}&x=${Math.round(jugador.x)}&y=${Math.round(jugador.y)}`).catch(() => {});
  }
}

function render() {
  ctx.fillStyle = '#87CEEB';
  ctx.fillRect(0, 0, canvas.width, canvas.height);

  ctx.fillStyle = '#654321';
  ctx.fillRect(0, canvas.height - 10, canvas.width, 10);
  ctx.strokeStyle = '#000';
  ctx.lineWidth = 2;
  ctx.strokeRect(0, canvas.height - 10, canvas.width, 10);

  plataformasEstaticas.forEach(plat => {
    ctx.fillStyle = '#8B4513';
    ctx.fillRect(plat.x, plat.y, plat.width, 10);
    ctx.strokeStyle = '#654321';
    ctx.lineWidth = 2;
    ctx.strokeRect(plat.x, plat.y, plat.width, 10);
  });

  if (plataformaPuntos && plataformaPuntos.active) {
    ctx.shadowColor = 'rgba(255, 215, 0, 0.5)';
    ctx.shadowBlur = 10;
    ctx.fillStyle = '#FFD700';
    ctx.fillRect(plataformaPuntos.x, plataformaPuntos.y, plataformaPuntos.width, 10);
    ctx.strokeStyle = '#FFA500';
    ctx.lineWidth = 2;
    ctx.strokeRect(plataformaPuntos.x, plataformaPuntos.y, plataformaPuntos.width, 10);
    ctx.shadowBlur = 0;
    ctx.fillStyle = '#FF6347';
    ctx.font = 'bold 16px Arial';
    ctx.textAlign = 'center';
    ctx.strokeStyle = '#FFF';
    ctx.lineWidth = 3;
    ctx.strokeText(`+${plataformaPuntos.points}`, plataformaPuntos.x + plataformaPuntos.width / 2, plataformaPuntos.y - 5);
    ctx.fillText(`+${plataformaPuntos.points}`, plataformaPuntos.x + plataformaPuntos.width / 2, plataformaPuntos.y - 5);
  }

  // Oponente
  ctx.fillStyle = oponent.color;
  ctx.fillRect(oponent.x, oponent.y, jugador.width, jugador.height);
  ctx.strokeStyle = '#000';
  ctx.lineWidth = 2;
  ctx.strokeRect(oponent.x, oponent.y, jugador.width, jugador.height);
  ctx.fillStyle = '#000';
  ctx.fillRect(oponent.x + 8, oponent.y + 10, 4, 4);
  ctx.fillRect(oponent.x + 18, oponent.y + 10, 4, 4);

  // Jugador
  ctx.fillStyle = jugador.color;
  ctx.fillRect(jugador.x, jugador.y, jugador.width, jugador.height);
  ctx.strokeStyle = '#000';
  ctx.lineWidth = 2;
  ctx.strokeRect(jugador.x, jugador.y, jugador.width, jugador.height);
  ctx.fillStyle = '#000';
  ctx.fillRect(jugador.x + 8, jugador.y + 10, 4, 4);
  ctx.fillRect(jugador.x + 18, jugador.y + 10, 4, 4);

  // HUD
  ctx.fillStyle = '#000';
  ctx.font = 'bold 20px Arial';
  ctx.textAlign = 'left';
  ctx.fillText(`Punts: ${jugador.score}`, 10, 30);
}

// ===== RESULTADO + SALIDA LIMPIA =====
function showResultScreen(won, myScore, opponentScore, options = {}) {
  document.getElementById('resultIcon').textContent = won ? '🎉' : '😢';
  document.getElementById('resultTitle').textContent = won ? 'Has Guanyat!' : 'Has Perdut!';
  document.getElementById('finalScoreSelf').textContent = myScore;
  document.getElementById('finalScoreOpponent').textContent = opponentScore;
  document.getElementById('viewProfileBtn').style.display = isAuthenticated ? 'block' : 'none';
  showScreen('resultScreen');

  // Cerrar sesión de partida y volver al menú automáticamente si procede
  if (options.autoBack) {
    safeLeave(); // avisa al servidor si existe la acción
    setTimeout(() => { backToMainMenu(); }, 2000);
  }
}

function playAgain() {
  safeLeave();
  resetGame();
  showModeMenu();
}

function leaveGame() {
  if (confirm('Segur que vols sortir de la partida?')) {
    safeLeave();
    resetGame();
    backToMainMenu();
  }
}

// Notifica abandono al servidor (si el endpoint existe)
function safeLeave() {
  clearStatusPoll();
  if (idJoc) {
    try { navigator.sendBeacon && navigator.sendBeacon(`/game.php?action=leave&game_id=${idJoc}`); } catch {}
    try { fetch(`/game.php?action=leave&game_id=${idJoc}`, { method: 'POST' }).catch(() => {}); } catch {}
  }
}

function resetGame() {
  guanyador = null;
  gameLoopStarted = false;
  gameWasRunning = false;
  idJoc = null;
  idJugador = null;
  plataformasEstaticas = [];
  plataformaPuntos = null;
  jugador.score = 0;
  oponent.score = 0;
  jugador.x = 50; jugador.y = 550;
  currentRoomCode = null;
  clearStatusPoll();
}

// Al cerrar pestaña/recargar: abandono limpio
window.addEventListener('beforeunload', () => {
  try { if (idJoc) navigator.sendBeacon(`/game.php?action=leave&game_id=${idJoc}`); } catch {}
});

// ===== INICIALIZACIÓN (NO conecta a partida) =====
checkAuthentication();
