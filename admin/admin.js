/* C:\Users\desktop\Documents\Antigravity\RedTauros Player\admin\admin.js */

// Audio Elements
let audioA = document.getElementById('audio-a');
let audioB = document.getElementById('audio-b');

let activeAudio = audioA;
let inactiveAudio = audioB;

// System Configurations (Cached locally, synced with server)
let config = {
    fade_out: 2.0,
    fade_in: 1.5,
    silence: 0.5,
    anti_repeat: 10
};

// Player Playback State
let playerState = {
    status: 'idle', // idle, playing, transitional
    currentSong: null,
    isTransitioning: false,
    version: '',
    duration: 0
};

// DOM Elements
const systemStatusEl = document.getElementById('system-status');
const statusDotEl = document.getElementById('status-dot');
const statusTextEl = document.getElementById('status-text');

const vinylEl = document.getElementById('vinyl');
const songTitleEl = document.getElementById('song-title');
const songArtistEl = document.getElementById('song-artist');
const songGenreEl = document.getElementById('song-genre');

const progressTrackEl = document.getElementById('progress-track');
const progressFillEl = document.getElementById('progress-fill');
const timeCurrentEl = document.getElementById('time-current');
const timeTotalEl = document.getElementById('time-total');

const btnPlayPauseEl = document.getElementById('btn-play-pause');
const btnSkipEl = document.getElementById('btn-skip');
const btnStopEl = document.getElementById('btn-stop');

const paramFadeOutEl = document.getElementById('param-fade-out');
const paramFadeInEl = document.getElementById('param-fade-in');
const paramSilenceEl = document.getElementById('param-silence');
const paramAntiRepeatEl = document.getElementById('param-anti-repeat');

const valFadeOutEl = document.getElementById('val-fade-out');
const valFadeInEl = document.getElementById('val-fade-in');
const valSilenceEl = document.getElementById('val-silence');
const valAntiRepeatEl = document.getElementById('val-anti-repeat');

const btnSaveConfigEl = document.getElementById('btn-save-config');

const inputFolderPathEl = document.getElementById('input-folder-path');
const btnAddFolderEl = document.getElementById('btn-add-folder');
const folderListEl = document.getElementById('folder-list');

const btnScanEl = document.getElementById('btn-scan');
const btnForceScanEl = document.getElementById('btn-force-scan');

const genresGridEl = document.getElementById('genres-grid');
const votingResultsEl = document.getElementById('voting-results-container');

const qrCodeEl = document.getElementById('qr-code');
const qrUrlEl = document.getElementById('qr-url');

const statTotalSongsEl = document.getElementById('stat-total-songs');
const statActiveGenresEl = document.getElementById('stat-active-genres');
const statTotalDurationEl = document.getElementById('stat-total-duration');
const statPreferencesEl = document.getElementById('stat-preferences');

const historyListEl = document.getElementById('history-list');
const logTerminalEl = document.getElementById('log-terminal');

// Initialize Console Logs
function logToTerminal(message, type = 'info') {
    const timestamp = new Date().toLocaleTimeString();
    const entry = document.createElement('div');
    entry.className = 'log-entry';
    entry.innerHTML = `<span class="log-time">[${timestamp}]</span> <span class="log-${type}">${message}</span>`;
    logTerminalEl.appendChild(entry);
    logTerminalEl.scrollTop = logTerminalEl.scrollHeight;
}

// Format Seconds to MM:SS
function formatTime(seconds) {
    if (isNaN(seconds) || seconds === null) return '0:00';
    const m = Math.floor(seconds / 60);
    const s = Math.floor(seconds % 60).toString().padStart(2, '0');
    return `${m}:${s}`;
}

// Check and trigger scrolling marquee if text overflows its container
function updateMarquee(element) {
    if (!element) return;
    
    element.classList.remove('marquee-active');
    element.style.removeProperty('--scroll-distance');
    
    const container = element.parentElement;
    if (!container) return;
    
    // Execute after browser layout calculations
    setTimeout(() => {
        const textWidth = element.scrollWidth;
        const containerWidth = container.clientWidth;
        
        if (textWidth > containerWidth) {
            const scrollDistance = containerWidth - textWidth - 12; // Extra padding
            element.style.setProperty('--scroll-distance', `${scrollDistance}px`);
            element.classList.add('marquee-active');
        }
    }, 100);
}

// -------------------------------------------------------------
// SYSTEM CONFIGURATION AND SETUP
// -------------------------------------------------------------

async function loadConfig() {
    try {
        const response = await fetch('../api/config.php');
        const data = await response.json();
        
        if (data && data.error) {
            logToTerminal('Error al cargar configuración: ' + data.error, 'error');
            return;
        }
        
        config.fade_out = data.fade_out;
        config.fade_in = data.fade_in;
        config.silence = data.silence;
        config.anti_repeat = data.anti_repeat;

        // Update Slider inputs and text values
        paramFadeOutEl.value = config.fade_out;
        valFadeOutEl.textContent = `${config.fade_out.toFixed(1)}s`;

        paramFadeInEl.value = config.fade_in;
        valFadeInEl.textContent = `${config.fade_in.toFixed(1)}s`;

        paramSilenceEl.value = config.silence;
        valSilenceEl.textContent = `${config.silence.toFixed(1)}s`;

        paramAntiRepeatEl.max = data.max_anti_repeat;
        paramAntiRepeatEl.value = Math.min(config.anti_repeat, data.max_anti_repeat);
        valAntiRepeatEl.textContent = `${paramAntiRepeatEl.value} canciones`;

        // Update Folders
        folderListEl.innerHTML = '';
        data.folders.forEach(folder => {
            addFolderToUI(folder.id, folder.path);
        });

        // Set PWA URL and QR Code
        qrUrlEl.textContent = data.pwa_url;
        qrCodeEl.src = `https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=${encodeURIComponent(data.pwa_url)}`;

        logToTerminal('Configuración de sesión cargada.', 'info');
    } catch (e) {
        logToTerminal('Error al cargar configuración: ' + e.message, 'error');
    }
}

async function saveConfig() {
    try {
        const payload = {
            fade_out: parseFloat(paramFadeOutEl.value),
            fade_in: parseFloat(paramFadeInEl.value),
            silence: parseFloat(paramSilenceEl.value),
            anti_repeat: parseInt(paramAntiRepeatEl.value)
        };

        const response = await fetch('../api/config.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const result = await response.json();

        if (result.success) {
            config.fade_out = payload.fade_out;
            config.fade_in = payload.fade_in;
            config.silence = payload.silence;
            config.anti_repeat = payload.anti_repeat;
            logToTerminal('Configuración de sesión guardada.', 'success');
        } else {
            logToTerminal('Error al guardar configuración.', 'error');
        }
    } catch (e) {
        logToTerminal('Error al guardar configuración: ' + e.message, 'error');
    }
}

function addFolderToUI(id, path) {
    const item = document.createElement('div');
    item.className = 'folder-list-item';
    item.id = `folder-item-${id}`;
    
    const span = document.createElement('span');
    span.textContent = path;
    item.appendChild(span);
    
    const btn = document.createElement('button');
    btn.className = 'btn btn-danger';
    btn.style.padding = '4px 8px';
    btn.style.fontSize = '0.75rem';
    btn.textContent = 'Eliminar';
    btn.addEventListener('click', () => removeFolder(id, path));
    item.appendChild(btn);
    
    folderListEl.appendChild(item);
}

async function addFolder() {
    const path = inputFolderPathEl.value.trim();
    if (!path) return;

    try {
        // Collect current folders
        const folderSpans = folderListEl.querySelectorAll('.folder-list-item span');
        const folders = Array.from(folderSpans).map(span => span.textContent);
        folders.push(path);

        const response = await fetch('../api/config.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ folders })
        });
        const result = await response.json();

        if (result.success) {
            inputFolderPathEl.value = '';
            await loadConfig();
            logToTerminal(`Carpeta añadida: ${path}`, 'success');
        }
    } catch (e) {
        logToTerminal('Error al agregar carpeta: ' + e.message, 'error');
    }
}

async function removeFolder(id, path) {
    try {
        const folderSpans = folderListEl.querySelectorAll('.folder-list-item span');
        const folders = Array.from(folderSpans)
            .map(span => span.textContent)
            .filter(p => p !== path);

        const response = await fetch('../api/config.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ folders })
        });
        const result = await response.json();

        if (result.success) {
            await loadConfig();
            logToTerminal(`Carpeta eliminada: ${path}`, 'warning');
        }
    } catch (e) {
        logToTerminal('Error al eliminar carpeta: ' + e.message, 'error');
    }
}

async function loadGenres() {
    try {
        const response = await fetch('../api/genres.php');
        const genres = await response.json();

        genresGridEl.innerHTML = '';
        genres.forEach(genre => {
            const label = document.createElement('label');
            label.className = `genre-checkbox-label ${genre.is_active ? 'active' : ''}`;
            label.style.setProperty('--border-active', genre.color);
            label.style.setProperty('--glow-active', genre.color + '33');
            
            label.innerHTML = `
                <input type="checkbox" data-id="${genre.id}" ${genre.is_active ? 'checked' : ''}>
                <span>${genre.icon} ${genre.name}</span>
            `;

            const checkbox = label.querySelector('input');
            checkbox.addEventListener('change', async () => {
                const is_active = checkbox.checked ? 1 : 0;
                try {
                    const res = await fetch('../api/genres.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ id: genre.id, is_active })
                    });
                    const r = await res.json();
                    if (r.success) {
                        label.classList.toggle('active', checkbox.checked);
                        logToTerminal(`Género ${genre.name} ${checkbox.checked ? 'activado' : 'desactivado'}.`, 'info');
                    } else {
                        // Revert check
                        checkbox.checked = !checkbox.checked;
                        logToTerminal(`Error: ${r.error}`, 'error');
                    }
                } catch (err) {
                    checkbox.checked = !checkbox.checked;
                    logToTerminal('Error al guardar género: ' + err.message, 'error');
                }
            });

            genresGridEl.appendChild(label);
        });
    } catch (e) {
        logToTerminal('Error al cargar géneros: ' + e.message, 'error');
    }
}

async function runScan(force = false) {
    logToTerminal(force ? 'Iniciando escaneo completo...' : 'Iniciando escaneo rápido...', 'info');
    btnScanEl.disabled = true;
    btnForceScanEl.disabled = true;

    try {
        const response = await fetch('../api/scan.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ force: force ? 1 : 0 })
        });
        const result = await response.json();
        
        if (result.success) {
            logToTerminal(result.message, 'success');
            await loadHistory();
            await loadConfig(); // Reload dynamic max anti-repeat
        } else {
            logToTerminal(result.error, 'error');
        }
    } catch (e) {
        logToTerminal('Error durante el escaneo: ' + e.message, 'error');
    } finally {
        btnScanEl.disabled = false;
        btnForceScanEl.disabled = false;
    }
}

// -------------------------------------------------------------
// CORE PLAYBACK CONTROLLER (CROSSFADER)
// -------------------------------------------------------------

// Attach TimeUpdate listener to the active player
function initAudioListeners(audio) {
    audio.ontimeupdate = () => {
        if (audio !== activeAudio) return;

        const current = audio.currentTime;
        const total = audio.duration;
        
        // Progress display
        if (total > 0) {
            const pct = (current / total) * 100;
            progressFillEl.style.width = `${pct}%`;
            timeCurrentEl.textContent = formatTime(current);
            timeTotalEl.textContent = formatTime(total);

            // Trigger Crossfade transition near the end (R5.1, R5.5)
            // Trigger threshold is activeAudio.duration - config.fade_out
            if (!playerState.isTransitioning && current >= total - config.fade_out) {
                triggerCrossfade();
            }
        }
    };

    audio.onended = () => {
        // If a transition didn't complete or trigger properly, force cleanup
        if (audio === activeAudio && !playerState.isTransitioning) {
            logToTerminal('Canción terminada de forma prematura. Buscando siguiente...', 'warning');
            triggerCrossfade(true);
        }
    };

    audio.onerror = (e) => {
        // Ignore errors if the source was cleared or the player is stopped/idle
        if (playerState.status === 'idle' || !audio.src || audio.src === window.location.href || playerState.isTransitioning) {
            return;
        }

        logToTerminal(`Error de reproducción de audio en reproductor ${audio.id}: ` + (audio.error ? audio.error.message : 'Error desconocido'), 'error');
        if (audio === activeAudio) {
            // Try to recover by skipping to next
            setTimeout(() => triggerCrossfade(true), 3000);
        }
    };
}

initAudioListeners(audioA);
initAudioListeners(audioB);

// Execute Crossfade playback transition
async function triggerCrossfade(force = false) {
    if (playerState.isTransitioning) return;
    playerState.isTransitioning = true;
    logToTerminal('Finalizando ronda de votos y calculando ganadora...', 'info');

    try {
        // 1. Finalize voting round on server and get winner (R4.1, R4.2, R4.3, R4.4)
        const response = await fetch('../api/play-winner.php', { method: 'POST' });
        const result = await response.json();

        if (!result.success || !result.winner) {
            throw new Error(result.error || 'No se pudo obtener la canción ganadora.');
        }

        const winner = result.winner;
        logToTerminal(`Ganadora: "${winner.title}" de ${winner.artist} (${winner.votes_received} votos).`, 'success');

        // 2. Load winning song in inactive audio element
        inactiveAudio.src = `../api/stream.php?id=${winner.id}`;
        inactiveAudio.load();
        inactiveAudio.volume = 0.0;

        // Update active status in state
        playerState.currentSong = winner;
        
        // Update UI info
        songTitleEl.textContent = winner.title;
        songArtistEl.textContent = winner.artist;
        updateMarquee(songTitleEl);
        updateMarquee(songArtistEl);
        
        // Fetch genre details for tag
        fetch(`../api/songs.php`)
            .then(res => res.json())
            .then(songs => {
                const s = songs.find(x => x.id === winner.id);
                if (s) {
                    songGenreEl.textContent = `${s.genre_icon} ${s.genre_name}`;
                    songGenreEl.style.backgroundColor = s.genre_color + '22';
                    songGenreEl.style.color = s.genre_color;
                    songGenreEl.style.border = `1px solid ${s.genre_color}44`;
                }
            });

        // 3. Play next song
        await inactiveAudio.play();
        logToTerminal(`Iniciando reproducción: "${winner.title}"`, 'info');

        // 4. Perform fading (R5.2, R5.3, R5.4)
        const playerToFadeOut = activeAudio;
        const playerToFadeIn = inactiveAudio;

        const fadeOutTime = config.fade_out * 1000;
        const fadeInTime = config.fade_in * 1000;
        const silenceTime = config.silence * 1000;
        
        // Fade out current song
        const fadeOutSteps = 20;
        const fadeOutInterval = fadeOutTime / fadeOutSteps;
        let fadeOutVol = playerToFadeOut.volume;
        const fadeOutTimer = setInterval(() => {
            fadeOutVol -= (1.0 / fadeOutSteps);
            if (fadeOutVol <= 0) {
                clearInterval(fadeOutTimer);
                playerToFadeOut.volume = 0;
                playerToFadeOut.pause();
                playerToFadeOut.src = '';
            } else {
                playerToFadeOut.volume = Math.max(0, fadeOutVol);
            }
        }, fadeOutInterval);

        // Fade in new song after silence delay (R5.4)
        setTimeout(() => {
            const fadeInSteps = 20;
            const fadeInInterval = fadeInTime / fadeInSteps;
            let fadeInVol = 0.0;
            const fadeInTimer = setInterval(() => {
                fadeInVol += (1.0 / fadeInSteps);
                if (fadeInVol >= 1.0) {
                    clearInterval(fadeInTimer);
                    playerToFadeIn.volume = 1.0;
                } else {
                    playerToFadeIn.volume = Math.min(1.0, fadeInVol);
                }
            }, fadeInInterval);
        }, silenceTime);

        // 5. Swap active players
        const temp = activeAudio;
        activeAudio = inactiveAudio;
        inactiveAudio = temp;

        vinylEl.classList.add('playing');
        btnPlayPauseEl.textContent = '⏸ Pausar Fiesta';
        btnPlayPauseEl.className = 'btn';

        playerState.isTransitioning = false;
        logToTerminal('Transición completada. Nueva ronda de votación iniciada.', 'info');
        
        // Refresh history logs
        await loadHistory();

    } catch (e) {
        logToTerminal('Error durante la transición: ' + e.message, 'error');
        playerState.isTransitioning = false;
        
        // Fallback: If player failed completely, reset interface to stop
        stopFiesta();
    }
}

// Start playback from scratch
async function startFiesta() {
    logToTerminal('Iniciando Fiesta Musical autónoma...', 'info');
    
    try {
        // 1. Reset state
        playerState.isTransitioning = true;
        
        // 2. Play winner endpoint selects first random song (R7.1 / System startup)
        const response = await fetch('../api/play-winner.php', { method: 'POST' });
        const result = await response.json();

        if (!result.success || !result.winner) {
            throw new Error(result.error || 'No hay canciones en la biblioteca.');
        }

        const winner = result.winner;
        
        activeAudio.src = `../api/stream.php?id=${winner.id}`;
        activeAudio.volume = 1.0;
        await activeAudio.play();
        
        playerState.currentSong = winner;
        playerState.status = 'playing';

        // Update UI
        songTitleEl.textContent = winner.title;
        songArtistEl.textContent = winner.artist;
        updateMarquee(songTitleEl);
        updateMarquee(songArtistEl);
        
        // Fetch genre color
        fetch(`../api/songs.php`)
            .then(res => res.json())
            .then(songs => {
                const s = songs.find(x => x.id === winner.id);
                if (s) {
                    songGenreEl.textContent = `${s.genre_icon} ${s.genre_name}`;
                    songGenreEl.style.backgroundColor = s.genre_color + '22';
                    songGenreEl.style.color = s.genre_color;
                    songGenreEl.style.border = `1px solid ${s.genre_color}44`;
                }
            });

        vinylEl.classList.add('playing');
        btnPlayPauseEl.textContent = '⏸ Pausar Fiesta';
        btnPlayPauseEl.className = 'btn';
        btnStopEl.style.display = 'inline-flex';

        playerState.isTransitioning = false;
        logToTerminal(`Reproduciendo primera canción: "${winner.title}" de ${winner.artist}.`, 'success');

        await loadHistory();

    } catch (e) {
        logToTerminal('No se pudo iniciar la fiesta: ' + e.message, 'error');
        playerState.isTransitioning = false;
        stopFiesta();
    }
}

function pauseFiesta() {
    activeAudio.pause();
    vinylEl.classList.remove('playing');
    btnPlayPauseEl.textContent = '▶ Reanudar Fiesta';
    btnPlayPauseEl.className = 'btn btn-primary';
    logToTerminal('Fiesta pausada temporalmente.', 'warning');
}

async function resumeFiesta() {
    try {
        await activeAudio.play();
        vinylEl.classList.add('playing');
        btnPlayPauseEl.textContent = '⏸ Pausar Fiesta';
        btnPlayPauseEl.className = 'btn';
        logToTerminal('Fiesta reanudada.', 'info');
    } catch (e) {
        logToTerminal('Error al reanudar: ' + e.message, 'error');
    }
}

function stopFiesta() {
    activeAudio.pause();
    activeAudio.src = '';
    inactiveAudio.pause();
    inactiveAudio.src = '';
    
    playerState.currentSong = null;
    playerState.status = 'idle';
    playerState.isTransitioning = false;

    vinylEl.classList.remove('playing');
    songTitleEl.textContent = 'Sin reproducción';
    songArtistEl.textContent = 'Selecciona iniciar fiesta';
    updateMarquee(songTitleEl);
    updateMarquee(songArtistEl);
    songGenreEl.textContent = '❓ Desconocido';
    songGenreEl.style.backgroundColor = 'var(--border-color)';
    songGenreEl.style.color = 'var(--text-secondary)';
    songGenreEl.style.border = 'none';
    
    progressFillEl.style.width = '0%';
    timeCurrentEl.textContent = '0:00';
    timeTotalEl.textContent = '0:00';

    btnPlayPauseEl.textContent = '▶ Iniciar Fiesta';
    btnPlayPauseEl.className = 'btn btn-primary';
    btnStopEl.style.display = 'none';
    
    logToTerminal('Fiesta detenida.', 'warning');
}

// -------------------------------------------------------------
// STATUS AND VOTES POLLING (REAL-TIME UPDATES)
// -------------------------------------------------------------

let votesCountCached = 0;

async function pollStatus() {
    const url = `../api/status.php?version=${playerState.version}`;
    
    try {
        const response = await fetch(url);
        if (!response.ok) {
            throw new Error(`HTTP error ${response.status}`);
        }
        
        const data = await response.json();
        
        // Update local version hash for next poll
        playerState.version = data.version;

        // Check status change
        updateStatusUI(data.status);

        // Sync local settings if changed on server
        config.fade_out = data.config.fade_out;
        config.fade_in = data.config.fade_in;
        config.silence = data.config.silence;
        config.anti_repeat = data.config.anti_repeat;

        // Log vote detection (if votes_count increases!)
        if (data.votes_count > votesCountCached) {
            const diff = data.votes_count - votesCountCached;
            logToTerminal(`${diff} nuevo(s) voto(s) registrado(s) en la ronda.`, 'success');
        }
        votesCountCached = data.votes_count;

        // Update Real-Time Results panel
        await updateVotingResults();

        // Recursively trigger next status poll (2-second interval)
        setTimeout(pollStatus, 2000);
    } catch (e) {
        // On connection errors, display offline and retry after 3 seconds
        statusDotEl.className = 'status-dot offline';
        statusTextEl.textContent = 'Error de conexión';
        
        setTimeout(pollStatus, 3000);
    }
}

function updateStatusUI(status) {
    if (status === 'playing') {
        statusDotEl.className = 'status-dot';
        statusTextEl.textContent = 'Reproducción autónoma activa';
    } else if (status === 'idle') {
        statusDotEl.className = 'status-dot idle';
        statusTextEl.textContent = 'Listo - Fiesta detenida';
    } else {
        statusDotEl.className = 'status-dot idle';
        statusTextEl.textContent = 'Transición gradual';
    }
}

async function updateVotingResults() {
    try {
        const response = await fetch('../api/results.php');
        const results = await response.json();

        if (results.length === 0) {
            votingResultsEl.innerHTML = `
                <div style="color: var(--text-secondary); text-align: center; padding: 20px;">
                    Sin ronda de votación activa. Presiona "Iniciar Fiesta".
                </div>
            `;
            return;
        }

        // Calculate total score of all options to establish percentage bars
        let totalScore = results.reduce((acc, curr) => acc + curr.weighted_score, 0.0);
        
        votingResultsEl.innerHTML = '';
        results.forEach(song => {
            const pct = totalScore > 0 ? (song.weighted_score / totalScore) * 100 : 0;
            
            const row = document.createElement('div');
            row.className = 'vote-option-row';
            row.innerHTML = `
                <div class="vote-option-details">
                    <div class="vote-option-song-info">
                        <div class="vote-option-title">${song.title}</div>
                        <div class="vote-option-artist">${song.artist}</div>
                    </div>
                    <div class="vote-option-meta" style="color: ${song.genre_color}">
                        <span style="font-size:1.1rem">${song.genre_icon}</span>
                        <span>${song.weighted_score.toFixed(2)} pts</span>
                        <span style="font-size:0.75rem; color:var(--text-muted)">(${song.raw_votes} votos)</span>
                    </div>
                </div>
                <div class="vote-option-progress-track">
                    <div class="vote-option-progress-fill" style="width: ${pct}%; background-color: ${song.genre_color}"></div>
                </div>
                <div class="vote-option-bg-glow" style="width: ${pct}%; background-color: ${song.genre_color}"></div>
            `;
            votingResultsEl.appendChild(row);
        });

    } catch (e) {
        console.error('Error al actualizar resultados de votos:', e);
    }
}

async function loadHistory() {
    try {
        const response = await fetch('../api/history.php');
        const data = await response.json();

        // 1. Populate stats cards
        statTotalSongsEl.textContent = data.stats.total_songs;
        statActiveGenresEl.textContent = data.stats.active_genres;
        
        const mins = Math.round(data.stats.total_duration / 60);
        statTotalDurationEl.textContent = `${mins}m`;

        // Preferences list
        statPreferencesEl.innerHTML = '';
        if (data.stats.audience_preferences.length > 0) {
            data.stats.audience_preferences.forEach(pref => {
                const badge = document.createElement('span');
                badge.style = `
                    background: ${pref.color}15; 
                    color: ${pref.color}; 
                    border: 1px solid ${pref.color}33; 
                    padding: 4px 10px; 
                    border-radius: 12px;
                    font-weight: 500;
                `;
                badge.textContent = `${pref.icon} ${pref.name} (${pref.vote_count})`;
                statPreferencesEl.appendChild(badge);
            });
        } else {
            statPreferencesEl.innerHTML = '<span style="color: var(--text-muted)">Sin preferencias registradas (esperando votos)</span>';
        }

        // 2. Populate Play History list
        historyListEl.innerHTML = '';
        if (data.history.length > 0) {
            data.history.forEach(item => {
                const row = document.createElement('div');
                row.className = 'history-item';
                row.innerHTML = `
                    <div class="history-song-details">
                        <div class="history-song-title">${item.title || 'Canción Desconocida'}</div>
                        <div class="history-song-meta">${item.artist || 'Desconocido'} • <span style="color:${item.genre_color}">${item.genre_icon} ${item.genre_name}</span></div>
                    </div>
                    <div class="history-song-votes">${item.votes_received} votos</div>
                `;
                historyListEl.appendChild(row);
            });
        } else {
            historyListEl.innerHTML = '<div style="color:var(--text-muted); padding:10px; font-size:0.85rem;">Historial vacío</div>';
        }

    } catch (e) {
        console.error('Error al cargar historial y estadísticas:', e);
    }
}

// -------------------------------------------------------------
// EVENT LISTENERS & INITS
// -------------------------------------------------------------

btnPlayPauseEl.addEventListener('click', () => {
    if (playerState.currentSong) {
        if (activeAudio.paused) {
            resumeFiesta();
        } else {
            pauseFiesta();
        }
    } else {
        startFiesta();
    }
});

btnStopEl.addEventListener('click', () => {
    stopFiesta();
});

btnSkipEl.addEventListener('click', () => {
    if (playerState.currentSong) {
        logToTerminal('Salto de canción forzado manualmente por el Administrador.', 'warning');
        triggerCrossfade(true);
    } else {
        logToTerminal('No hay canción en reproducción para saltar.', 'error');
    }
});

// Settings inputs listeners
paramFadeOutEl.addEventListener('input', () => {
    valFadeOutEl.textContent = `${parseFloat(paramFadeOutEl.value).toFixed(1)}s`;
});

paramFadeInEl.addEventListener('input', () => {
    valFadeInEl.textContent = `${parseFloat(paramFadeInEl.value).toFixed(1)}s`;
});

paramSilenceEl.addEventListener('input', () => {
    valSilenceEl.textContent = `${parseFloat(paramSilenceEl.value).toFixed(1)}s`;
});

paramAntiRepeatEl.addEventListener('input', () => {
    valAntiRepeatEl.textContent = `${paramAntiRepeatEl.value} canciones`;
});

btnSaveConfigEl.addEventListener('click', saveConfig);

btnAddFolderEl.addEventListener('click', addFolder);
inputFolderPathEl.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') addFolder();
});

btnScanEl.addEventListener('click', () => runScan(false));
btnForceScanEl.addEventListener('click', () => runScan(true));

// Progress Bar Scrubbing
progressTrackEl.addEventListener('click', (e) => {
    if (!playerState.currentSong || playerState.isTransitioning) return;
    const rect = progressTrackEl.getBoundingClientRect();
    const clickX = e.clientX - rect.left;
    const width = rect.width;
    const pct = clickX / width;
    
    activeAudio.currentTime = pct * activeAudio.duration;
    logToTerminal(`Posición de reproducción cambiada a ${formatTime(activeAudio.currentTime)}.`, 'info');
});

// App Launch
document.addEventListener('DOMContentLoaded', async () => {
    logToTerminal('Consola de Fiesta Musical iniciada.', 'info');
    await loadConfig();
    await loadGenres();
    await loadHistory();
    
    // Start status long-polling loop
    pollStatus();
});
