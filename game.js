// game.js - Lógica del juego

// Variables globales
let isAuthenticated = false;
let currentUserName = 'Convidat';
let currentUserId = null;
let currentGameMode = 'online'; // 'online' o 'local'
let currentRoomCode = null;

// Variables del juego
let idJoc, idJugador;
let esPrimerJugador = false;
let guanyador = null;
let gameLoopStarted = false;
let lastUpdateTime = Date.now();
let lastServerUpdate = 0;

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

// ===== AUTENTICACIÓN =====
async function checkAuthentication() {
    try {
        const response = await fetch('/game.php?action=join', { method: 'POST' });
        const data = await response.json();
        
        if (data.player_id && !data.player_id.startsWith('guest_')) {
            isAuthenticated = true;
            currentUserId = data.player_id;
            
            // Obtener nombre del usuario
            try {
                const statusResponse = await fetch(`/game.php?action=status&game_id=${data.game_id}`);
                const statusData = await statusResponse.json();
                
                if (statusData.player1 === data.player_id && statusData.player1_name) {
                    currentUserName = statusData.player1_name;
                } else if (statusData.player2 === data.player_id && statusData.player2_name) {
                    currentUserName = statusData.player2_name;
                }
            } catch (e) {
                console.log('No se pudo obtener nombre');
            }
            
            updateUserDisplay(currentUserName, true);
        } else {
            updateUserDisplay('Convidat', false);
        }
    } catch (error) {
        console.error('Error verificando autenticación:', error);
    }
}

function updateUserDisplay(userName, authenticated) {
    // Actualizar menú principal
    document.getElementById('menuUserName').textContent = userName;
    document.getElementById('menuUserStatus').textContent = authenticated ? 'Usuari registrat' : 'Mode invitat';
    document.getElementById('menuUserAvatar').textContent = userName.charAt(0).toUpperCase();
    
    if (authenticated) {
        document.getElementById('menuLoginBtn').style.display = 'none';
        document.getElementById('menuPerfilBtn').style.display = 'inline-block';
    } else {
        document.getElementById('menuLoginBtn').style.display = 'inline-block';
        document.getElementById('menuPerfilBtn').style.display = 'none';
    }
    
    // Actualizar header del juego
    document.getElementById('gameUserName').textContent = userName;
    document.getElementById('gameUserAvatar').textContent = userName.charAt(0).toUpperCase();
}

// ===== NAVEGACIÓN DE MENÚS =====
function showScreen(screenId) {
    const screens = ['mainMenu', 'modeMenu', 'gameContainer', 'resultScreen'];
    screens.forEach(id => {
        document.getElementById(id).style.display = 'none';
    });
    document.getElementById(screenId).style.display = screenId === 'gameContainer' ? 'flex' : 'block';
}

function showModeMenu() {
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

// ===== MODO LOCAL CON CÓDIGOS =====
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
    for (let i = 0; i < 6; i++) {
        code += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    return code;
}

async function createRoom() {
    currentGameMode = 'local';
    currentRoomCode = generateRoomCode();
    
    try {
        // Crear sala con código específico
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
        
        // Mostrar código
        document.getElementById('createdRoomCode').textContent = currentRoomCode;
        document.getElementById('roomCreatedPanel').style.display = 'block';
        document.getElementById('gameRoomInfo').textContent = `Sala: ${currentRoomCode}`;
        
        // Empezar a comprobar si alguien se une
        comprovarSalaLocal();
        
    } catch (error) {
        alert('Error creant la sala: ' + error.message);
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
        // Unirse a sala con código
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
        comprovarEstatDelJoc();
        
    } catch (error) {
        alert('Error unint-se a la sala: ' + error.message);
    }
}

async function comprovarSalaLocal() {
    if (!idJoc) return;
    
    try {
        const response = await fetch(`/game.php?action=status&game_id=${idJoc}`);
        const joc = await response.json();
        
        if (joc.player1 && joc.player2) {
            // ¡Alguien se unió!
            closeLocalModal();
            showScreen('gameContainer');
            comprovarEstatDelJoc();
        } else {
            // Seguir esperando
            setTimeout(comprovarSalaLocal, 1000);
        }
    } catch (error) {
        console.error('Error comprobando sala:', error);
        setTimeout(comprovarSalaLocal, 2000);
    }
}

// ===== LÓGICA DEL JUEGO =====
function unirseAlJoc() {
    const body = currentRoomCode ? JSON.stringify({ room_code: currentRoomCode }) : '{}';
    
    fetch('/game.php?action=join', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: body
    })
    .then(r => r.json())
    .then(data => {
        if (data.error) throw new Error(data.error);
        idJoc = data.game_id;
        idJugador = data.player_id;
        comprovarEstatDelJoc();
    })
    .catch(err => {
        console.error('Error:', err);
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

function comprovarEstatDelJoc() {
    const t0 = performance.now();
    
    fetch(`/game.php?action=status&game_id=${idJoc}`)
    .then(r => {
        const t1 = performance.now();
        actualizarPing(t1 - t0);
        return r.json();
    })
    .then(joc => {
        if (joc.error) {
            document.getElementById('estat').textContent = joc.error;
            return;
        }

        plataformasEstaticas = joc.platforms || [];
        plataformaPuntos = joc.point_platform || null;
        guanyador = joc.winner;
        esPrimerJugador = (joc.player1 === idJugador);
        
        if (esPrimerJugador) {
            jugador.color = '#FF6B6B';
            oponent.color = '#4ECDC4';
            oponent.x = joc.player2_x || 350;
            oponent.y = joc.player2_y || 550;
            oponent.score = joc.points[1] || 0;
            jugador.score = joc.points[0] || 0;
        } else {
            jugador.color = '#4ECDC4';
            oponent.color = '#FF6B6B';
            oponent.x = joc.player1_x || 50;
            oponent.y = joc.player1_y || 550;
            oponent.score = joc.points[0] || 0;
            jugador.score = joc.points[1] || 0;
        }

        const p1Name = joc.player1_name || 'Jugador 1';
        const p2Name = joc.player2_name || 'Jugador 2';
        document.getElementById('puntuacio').textContent = `${p1Name}: ${joc.points[0] || 0} | ${p2Name}: ${joc.points[1] || 0}`;

        if (guanyador) {
            const won = (guanyador === idJugador);
            showResultScreen(won, jugador.score, oponent.score);
            return;
        }

        if (joc.player1 && joc.player2) {
            document.getElementById('estat').textContent = 'Carrera en curs!';
            if (!gameLoopStarted) {
                gameLoopStarted = true;
                gameLoop();
            }
        } else if (esPrimerJugador) {
            document.getElementById('estat').textContent = `Esperant ${p2Name}...`;
        } else {
            document.getElementById('estat').textContent = `Començant...`;
        }

        if (!guanyador) setTimeout(comprovarEstatDelJoc, 100);
    })
    .catch(err => {
        console.error('Error:', err);
        setTimeout(comprovarEstatDelJoc, 500);
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

document.addEventListener('keyup', (e) => {
    teclesPremes[e.key] = false;
});

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
    if (teclesPremes['ArrowLeft'] || teclesPremes['a'] || teclesPremes['A']) {
        jugador.velocityX = -jugador.moveSpeed;
    } else if (teclesPremes['ArrowRight'] || teclesPremes['d'] || teclesPremes['D']) {
        jugador.velocityX = jugador.moveSpeed;
    } else {
        jugador.velocityX = 0;
    }

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
                        .catch(console.error);
                }
            }
        }
    }

    const now = Date.now();
    if (now - lastServerUpdate > 100) {
        lastServerUpdate = now;
        fetch(`/game.php?action=update&game_id=${idJoc}&x=${Math.round(jugador.x)}&y=${Math.round(jugador.y)}`)
            .catch(console.error);
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

    ctx.fillStyle = oponent.color;
    ctx.fillRect(oponent.x, oponent.y, jugador.width, jugador.height);
    ctx.strokeStyle = '#000';
    ctx.lineWidth = 2;
    ctx.strokeRect(oponent.x, oponent.y, jugador.width, jugador.height);
    ctx.fillStyle = '#000';
    ctx.fillRect(oponent.x + 8, oponent.y + 10, 4, 4);
    ctx.fillRect(oponent.x + 18, oponent.y + 10, 4, 4);

    ctx.fillStyle = jugador.color;
    ctx.fillRect(jugador.x, jugador.y, jugador.width, jugador.height);
    ctx.strokeStyle = '#000';
    ctx.lineWidth = 2;
    ctx.strokeRect(jugador.x, jugador.y, jugador.width, jugador.height);
    ctx.fillStyle = '#000';
    ctx.fillRect(jugador.x + 8, jugador.y + 10, 4, 4);
    ctx.fillRect(jugador.x + 18, jugador.y + 10, 4, 4);

    ctx.fillStyle = '#000';
    ctx.font = 'bold 20px Arial';
    ctx.textAlign = 'left';
    ctx.fillText(`Punts: ${jugador.score}`, 10, 30);
}

// ===== RESULTADO =====
function showResultScreen(won, myScore, opponentScore) {
    document.getElementById('resultIcon').textContent = won ? '🎉' : '😢';
    document.getElementById('resultTitle').textContent = won ? 'Has Guanyat!' : 'Has Perdut!';
    document.getElementById('finalScoreSelf').textContent = myScore;
    document.getElementById('finalScoreOpponent').textContent = opponentScore;
    
    if (isAuthenticated) {
        document.getElementById('viewProfileBtn').style.display = 'block';
    } else {
        document.getElementById('viewProfileBtn').style.display = 'none';
    }
    
    showScreen('resultScreen');
}

function playAgain() {
    resetGame();
    showModeMenu();
}

function leaveGame() {
    if (confirm('Segur que vols sortir de la partida?')) {
        resetGame();
        backToMainMenu();
    }
}

function resetGame() {
    guanyador = null;
    gameLoopStarted = false;
    idJoc = null;
    idJugador = null;
    jugador.score = 0;
    oponent.score = 0;
    jugador.x = 50;
    jugador.y = 550;
    currentRoomCode = null;
}

// ===== INICIALIZACIÓN =====
checkAuthentication();