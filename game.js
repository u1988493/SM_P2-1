

// ===== ESTAT GLOBAL =====
let isAuthenticated = false;
let currentUserName = 'Convidat';
let currentUserId = null;

let currentGameMode = null;     // 'online' | 'local'
let currentRoomCode = null;

let idJoc = null, idJugador = null;
let esPrimerJugador = false;
let guanyador = null;

let gameLoopStarted = false;    // només true amb 2 jugadors
let gameWasRunning = false;     // detecta si ja va haver 2 jugadors
let lastUpdateTime = Date.now();
let lastServerUpdate = 0;
let statusPollTimer = null;
let gameRAF = null;             // id de requestAnimationFrame

const canvas = document.getElementById('canvas');
const ctx = canvas.getContext('2d');

// El canvas estarà ocult mentre no hagi rival
canvas.style.visibility = 'hidden';

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

// ===== AUTENTICACIÓ LLEUGERA =====
async function checkAuthentication() {
  try {
    const r = await fetch('/auth-handler.php', { credentials: 'include' });
    if (r.ok) {
      const d = await r.json().catch(() => ({}));
      if (d && d.isAuthenticated) {
        isAuthenticated = true;
        currentUserId = d.userId;
        currentUserName = d.userName || 'Usuari';
      } else {
        isAuthenticated = false;
        currentUserName = 'Convidat';
      }
    }
  } catch (_) {
    console.error('Error verificant autenticació:', _);
  }
  updateUserDisplay(currentUserName, isAuthenticated);
}

function updateUserDisplay(userName, authenticated) {
  document.getElementById('menuUserName').textContent = userName;
  document.getElementById('menuUserStatus').textContent = authenticated ? 'Usuari registrat' : 'Mode convidats';
  document.getElementById('menuUserAvatar').textContent = userName.charAt(0).toUpperCase();

  document.getElementById('menuLoginBtn').style.display = authenticated ? 'none' : 'inline-block';
  document.getElementById('menuPerfilBtn').style.display = authenticated ? 'inline-block' : 'none';

  document.getElementById('gameUserName').textContent = userName;
  document.getElementById('gameUserAvatar').textContent = userName.charAt(0).toUpperCase();
}

// ===== NAVEGACIÓ =====
function showScreen(screenId) {
  const screens = ['mainMenu', 'modeMenu', 'gameContainer', 'resultScreen'];
  screens.forEach(id => { document.getElementById(id).style.display = 'none'; });
  document.getElementById(screenId).style.display = (screenId === 'gameContainer') ? 'flex' : 'block';
}

function showModeMenu() {
  // Verificar autenticació PRIMER
  if (!isAuthenticated) {
    alert("Has d'iniciar sessió per poder jugar.");
    window.location.href = '/.auth/login/aad?post_login_redirect_uri=/index.html';
    return;
  }
  
  currentGameMode = null;
  currentRoomCode = null;
  showScreen('modeMenu');
}

function backToMainMenu() {
  showScreen('mainMenu');
  closeLocalModal();
  resetGame(true);
}

// ===== MODE ONLINE =====
function startOnlineGame() {
  currentGameMode = 'online';
  currentRoomCode = null;
  document.getElementById('gameRoomInfo').textContent = 'Mode Online';
  showScreen('gameContainer');
  canvas.style.visibility = 'hidden';
  document.getElementById('estat').textContent = '⏳ Buscant oponent...';
  unirseAlJoc();
}

// ===== MODE LOCAL (codi de sala) =====
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
    
    if (data.error) { 
      alert('Error creant la sala: ' + data.error); 
      return; 
    }

    idJoc = data.game_id;
    idJugador = data.player_id;

    document.getElementById('createdRoomCode').textContent = currentRoomCode;
    document.getElementById('roomCreatedPanel').style.display = 'block';
    document.getElementById('gameRoomInfo').textContent = `Sala: ${currentRoomCode}`;

    showScreen('gameContainer');
    canvas.style.visibility = 'hidden';
    
    comprovarSalaLocal();
  } catch (e) { 
    alert('Error creant la sala: ' + e.message); 
  }
}

async function joinRoom() {
  const code = document.getElementById('roomCodeInput').value.trim().toUpperCase();
  if (!code || code.length !== 6) { 
    alert('Si us plau, introdueix un codi de 6 caràcters'); 
    return; 
  }

  currentGameMode = 'local';
  currentRoomCode = code;

  try {
    const response = await fetch('/game.php?action=join', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({ room_code: code })
    });
    const data = await response.json();
    
    if (data.error) { 
      alert('Error unint-se a la sala: ' + data.error); 
      return; 
    }

    idJoc = data.game_id;
    idJugador = data.player_id;

    document.getElementById('gameRoomInfo').textContent = `Sala: ${code}`;
    closeLocalModal();
    showScreen('gameContainer');
    canvas.style.visibility = 'hidden';
    document.getElementById('estat').textContent = '⏳ Esperant al host...';
    
    comprovarEstatDelJoc();
  } catch (e) { 
    alert('Error unint-se a la sala: ' + e.message); 
  }
}

async function comprovarSalaLocal() {
  if (!idJoc || !currentRoomCode) return;
  
  try {
    const response = await fetch(`/game.php?action=status&game_id=${idJoc}`);
    const joc = await response.json();
    
    if (joc.error) {
      document.getElementById('estat').textContent = '❌ Error: ' + joc.error;
      setTimeout(comprovarSalaLocal, 2000);
      return;
    }

    // Comprobar que hi han 2 jugadors DIFERENTS
    if (joc.player1 && joc.player2 && joc.player1 !== joc.player2) {
      // Dos jugadors diferents - engegar partida
      document.getElementById('localModal').style.display = 'none';
      closeLocalModal();
      comprovarEstatDelJoc();
    } else {
      // Mostrar que esteim esperant
      document.getElementById('estat').textContent = '⏳ Esperant que es connecti un oponent...\nCodi: ' + currentRoomCode;
      setTimeout(comprovarSalaLocal, 1500);
    }
  } catch (e) {
    console.error('Error en comprovarSalaLocal:', e);
    setTimeout(comprovarSalaLocal, 2000);
  }
}

// ===== CONNEXIÓ / ESTAT DE PARTIDA =====
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

    // Actualitzar nom si el backend ho mana en status
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
    document.getElementById('estat').textContent = '❌ Error: ' + err.message;
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

function stopGameLoop() {
  gameLoopStarted = false;
  if (gameRAF) { cancelAnimationFrame(gameRAF); gameRAF = null; }
}

function startGameLoop() {
  if (gameLoopStarted) return;
  gameLoopStarted = true;
  lastUpdateTime = Date.now();
  const loop = () => {
    if (!gameLoopStarted) return;
    gameRAF = requestAnimationFrame(loop);
    gameLoop();
  };
  gameRAF = requestAnimationFrame(loop);
}

function setStartPositions() {
  // Col·loca posicions inicials NOVES al arrancar partida
  jugador.x = esPrimerJugador ? 50 : 350;
  jugador.y = 550;
  jugador.velocityX = 0;
  jugador.velocityY = 0;
  jugador.onGround = false;

  oponent.x = esPrimerJugador ? 350 : 50;
  oponent.y = 550;
}

function resetTransientState() {
  // Limpia inputs i temps per evitar "arrastres"
  teclesPremes = {};
  lastServerUpdate = 0;
  lastUpdateTime = Date.now();
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

      // Puntuacions/posicions
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

      // Verificar que hi ha 2 jugadors DIFERENTS
      const bothThere = Boolean(joc.player1 && joc.player2 && joc.player1 !== joc.player2);

      // Si el rival se'n va després d'haber engegat: resultat + sortida neta
      if (!guanyador && gameWasRunning && !bothThere) {
        try { fetch(`/game.php?action=leave&game_id=${idJoc}`, { method: 'POST' }); } catch {}
        showResultScreen(true, jugador.score, oponent.score, { reason: 'opponent_left' });
        return;
      }

      if (guanyador) {
        const won = (guanyador === idJugador);
        showResultScreen(won, jugador.score, oponent.score, { reason: 'finished' });
        return;
      }

      if (bothThere) {
        // Mostrar canvas ara
        canvas.style.visibility = 'visible';
        document.getElementById('estat').textContent = '🏃 Carrera en curs!';
        if (!gameWasRunning) {
          // La partida REAL comença ara: posicions netes
          setStartPositions();
          resetTransientState();
        }
        gameWasRunning = true;
        startGameLoop();
      } else {
        // Encara esperant: ocultar canvas i parar loop
        if (joc.player1 && !joc.player2) {
          document.getElementById('estat').textContent = `⏳ Ets Jugador 1\nEsperant Jugador 2...`;
        } else if (!joc.player1 && joc.player2) {
          document.getElementById('estat').textContent = `⏳ Conectat com a Jugador 2\nEsperant al host...`;
        } else {
          document.getElementById('estat').textContent = `⏳ Esperant jugador...`;
        }
        canvas.style.visibility = 'hidden';
        stopGameLoop();
        resetTransientState();
      }

      clearStatusPoll();
      statusPollTimer = setTimeout(comprovarEstatDelJoc, 120);
    })
    .catch(() => {
      clearStatusPoll();
      statusPollTimer = setTimeout(comprovarEstatDelJoc, 500);
    });
}

// ===== CONTROLS =====
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
  if (guanyador || !gameLoopStarted) return;

  const now = Date.now();
  const deltaTime = (now - lastUpdateTime) / 16.67;
  lastUpdateTime = now;

  update(deltaTime);
  render();
}

function update(deltaTime) {
  // Moviment
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

  // Col·lisions simples
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

  // Enviar estat al servidor (només si hi ha partida)
  const now2 = Date.now();
  if (now2 - lastServerUpdate > 100 && idJoc) {
    lastServerUpdate = now2;
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

  // Oponent
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

// ===== RESULTAT + SORTIDA NETA =====
function showResultScreen(won, myScore, opponentScore, options = {}) {
  document.getElementById('resultIcon').textContent = won ? '🏆' : '☹️';
  document.getElementById('resultTitle').textContent = won ? 'Has Guanyat!' : 'Has Perdut!';
  document.getElementById('finalScoreSelf').textContent = myScore;
  document.getElementById('finalScoreOpponent').textContent = opponentScore;
  document.getElementById('viewProfileBtn').style.display = isAuthenticated ? 'block' : 'none';
  showScreen('resultScreen');

  // Limpia loop i canvas
  stopGameLoop();
  canvas.style.visibility = 'hidden';

  safeLeave();
}

function playAgain() {
  safeLeave();
  resetGame(true);
  showModeMenu();
}

function leaveGame() {
  if (confirm('Segur que vols sortir de la partida?')) {
    safeLeave();
    resetGame(true);
    backToMainMenu();
  }
}

// Notifica abandonament al servidor
function safeLeave() {
  clearStatusPoll();
  stopGameLoop();
  if (idJoc) {
    try { navigator.sendBeacon && navigator.sendBeacon(`/game.php?action=leave&game_id=${idJoc}`); } catch {}
    try { fetch(`/game.php?action=leave&game_id=${idJoc}`, { method: 'POST' }).catch(() => {}); } catch {}
  }
}

// Reseteg complet de partida
function resetGame(hideCanvas = false) {
  guanyador = null;
  stopGameLoop();
  gameWasRunning = false;

  idJoc = null;
  idJugador = null;

  plataformasEstaticas = [];
  plataformaPuntos = null;

  jugador.score = 0;
  oponent.score = 0;

  jugador.velocityX = 0;
  jugador.velocityY = 0;
  jugador.onGround = false;

  jugador.x = 50; jugador.y = 550;
  oponent.x = 350; oponent.y = 550;

  teclesPremes = {};
  lastJumpTime = 0;

  pingHistory = [];
  pingMs = 0;
  lastUpdateTime = Date.now();
  lastServerUpdate = 0;

  clearStatusPoll();

  if (hideCanvas) canvas.style.visibility = 'hidden';
}

// Al tancar pestanya/recarregar: abandonament net
window.addEventListener('beforeunload', () => {
  try { if (idJoc) navigator.sendBeacon(`/game.php?action=leave&game_id=${idJoc}`); } catch {}
});

// ===== INICIALITZACIÓ =====
checkAuthentication();