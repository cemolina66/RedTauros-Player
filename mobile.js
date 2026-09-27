/* C:\Users\desktop\Documents\Antigravity\RedTauros Player\mobile.js */

// Global error handler to display JS crashes directly on the screen
window.onerror = function (message, source, lineno, colno, error) {
    const grid = document.getElementById('voting-grid');
    if (grid) {
        grid.innerHTML = `
            <div style="color: #ef4444; background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.2); padding: 16px; border-radius: 12px; font-size: 0.85rem; word-break: break-all; margin: 10px;">
                <strong>Error de JavaScript Detectado:</strong><br>
                <span style="color: #fff">${message}</span><br><br>
                <small style="color: #a4a0c5;">Archivo: ${source.split('/').pop()}<br>Línea: ${lineno} • Columna: ${colno}</small>
            </div>
        `;
    }
    return false;
};

let localState = {
    status: 'idle',
    currentSong: null,
    votingOptions: [],
    votedSongId: null,
    version: '',
    // Progress interpolation variables
    lastUpdateTime: 0,
    currentProgressSeconds: 0,
    durationSeconds: 0
};

let lastSongTitle = '';
let lastSongArtist = '';

// DOM Elements
const songTitleEl = document.getElementById('song-title');
const songArtistEl = document.getElementById('song-artist');
const albumArtEl = document.getElementById('album-art');
const progressFillEl = document.getElementById('progress-fill');
const timeCurrentEl = document.getElementById('time-current');
const timeTotalEl = document.getElementById('time-total');
const votingGridEl = document.getElementById('voting-grid');
const offlineBannerEl = document.getElementById('offline-banner');
const installBannerEl = document.getElementById('install-banner');
const btnInstallEl = document.getElementById('btn-install');

// Format Seconds to MM:SS
function formatTime(seconds) {
    if (isNaN(seconds) || seconds === null) return '0:00';
    const m = Math.floor(seconds / 60);
    const s = Math.floor(seconds % 60).toString().padStart(2, '0');
    return `${m}:${s}`;
}

// Trigger smooth scrolling marquee if text overflows its container
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
// PROGRESS BAR SMOOTH INTERPOLATION
// -------------------------------------------------------------

setInterval(() => {
    if (localState.status === 'playing' && localState.durationSeconds > 0) {
        const now = Date.now();
        const delta = (now - localState.lastUpdateTime) / 1000;
        
        let current = localState.currentProgressSeconds + delta;
        if (current > localState.durationSeconds) {
            current = localState.durationSeconds; // Cap it
        }

        const pct = (current / localState.durationSeconds) * 100;
        progressFillEl.style.width = `${pct}%`;
        timeCurrentEl.textContent = formatTime(current);
    }
}, 1000);

// -------------------------------------------------------------
// STATUS LONG POLLING
// -------------------------------------------------------------

// Caching songs list to avoid querying the DB every 100ms
let cachedSongs = [];

async function ensureSongsCached() {
    if (Array.isArray(cachedSongs) && cachedSongs.length > 0) return;
    try {
        const response = await fetch('api/songs.php');
        const data = await response.json();
        if (Array.isArray(data)) {
            cachedSongs = data;
        } else {
            console.error('songs.php returned non-array:', data);
            cachedSongs = [];
        }
    } catch (e) {
        console.error('Error caching songs:', e);
        cachedSongs = [];
    }
}

async function pollStatus() {
    const url = `api/status.php?version=${localState.version}`;
    
    try {
        const response = await fetch(url);
        if (!response.ok) throw new Error('Network error');
        
        const data = await response.json();
        
        // Check if round reset/changed (if version or options list changed)
        const oldOptions = JSON.stringify(localState.votingOptions);
        const newOptions = JSON.stringify(data.voting_options_ids);
        
        if (oldOptions !== newOptions) {
            // New voting round started! Clear our local vote selection
            localState.votedSongId = null;
        }

        // Sync State
        localState.status = data.status;
        localState.version = data.version;
        localState.votingOptions = data.voting_options_ids;
        if (data.user_voted_song_id !== undefined) {
            localState.votedSongId = data.user_voted_song_id;
        }
        
        // Progress interpolation variables
        localState.lastUpdateTime = Date.now();
        localState.durationSeconds = data.current_song ? data.current_song.duration : 0;
        
        let progress = 0;
        if (data.current_song) {
            progress = data.current_progress || 0;
        }
        localState.currentProgressSeconds = progress;
        
        // Update Now Playing Info
        if (data.current_song) {
            songTitleEl.textContent = data.current_song.title;
            songArtistEl.textContent = data.current_song.artist;
            albumArtEl.textContent = data.current_song.genre_icon || '🎵';
            albumArtEl.style.background = `linear-gradient(135deg, ${data.current_song.genre_color || '#a855f7'}, #d946ef)`;
            timeTotalEl.textContent = formatTime(localState.durationSeconds);
        } else {
            songTitleEl.textContent = 'Fiesta Pausada';
            songArtistEl.textContent = 'Esperando al administrador';
            albumArtEl.textContent = '💤';
            albumArtEl.style.background = 'var(--border-color)';
            progressFillEl.style.width = '0%';
            timeCurrentEl.textContent = '0:00';
            timeTotalEl.textContent = '0:00';
        }

        // Sync marquee scrolling only when text changes
        if (songTitleEl.textContent !== lastSongTitle) {
            lastSongTitle = songTitleEl.textContent;
            updateMarquee(songTitleEl);
        }
        if (songArtistEl.textContent !== lastSongArtist) {
            lastSongArtist = songArtistEl.textContent;
            updateMarquee(songArtistEl);
        }

        // Render Voting Grid
        await renderVotingGrid();

        // Continue Polling at 2-second interval
        setTimeout(pollStatus, 2000);
    } catch (e) {
        // Retry on connection errors
        setTimeout(pollStatus, 4000);
    }
}

// Render Voting options (R7.2, R7.3, R7.4, R7.5)
async function renderVotingGrid() {
    if (localState.votingOptions.length === 0) {
        votingGridEl.innerHTML = `
            <div style="text-align: center; color: var(--text-secondary); padding: 20px; font-size: 0.9rem;">
                Sin opciones de votación activas en esta ronda.
            </div>
        `;
        return;
    }

    try {
        await ensureSongsCached();
        
        const optionsSongs = localState.votingOptions.map(id => {
            return (Array.isArray(cachedSongs) ? cachedSongs.find(s => Number(s.id) === Number(id)) : null) || {
                id: id,
                title: 'Canción No Disponible',
                artist: 'Desconocido',
                genre_color: '#b2bec3',
                genre_icon: '❓',
                genre_name: 'Desconocido'
            };
        });

        votingGridEl.innerHTML = '';
        optionsSongs.forEach(song => {
            const isVoted = Number(localState.votedSongId) === Number(song.id);
            
            const btn = document.createElement('button');
            btn.className = `vote-btn ${isVoted ? 'voted' : ''}`;
            btn.style.setProperty('--voted-border-color', song.genre_color);
            btn.style.setProperty('--voted-glow-color', song.genre_color + '33');
            
            btn.innerHTML = `
                <div class="vote-btn-info">
                    <div class="vote-btn-title">${song.title}</div>
                    <div class="vote-btn-artist">${song.artist}</div>
                    <div class="vote-btn-genre" style="background: ${song.genre_color}15; color: ${song.genre_color}; border: 1px solid ${song.genre_color}33;">
                        ${song.genre_icon} ${song.genre_name}
                    </div>
                </div>
                <div class="vote-btn-icon-container">
                    ${song.genre_icon}
                </div>
            `;

            btn.addEventListener('click', () => castVote(song.id));
            votingGridEl.appendChild(btn);
        });

    } catch (e) {
        console.error('Error rendering voting options:', e);
    }
}

// Cast Vote (R7.3, R7.4, R7.5)
async function castVote(songId) {
    if (localState.votedSongId === songId) return; // Already voted for this one
    
    // Optimistic Update for instant visual feedback
    const oldVote = localState.votedSongId;
    localState.votedSongId = songId;
    renderVotingGrid();

    try {
        const response = await fetch('api/vote.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ song_id: songId })
        });
        const result = await response.json();

        if (result.success) {
            localState.votedSongId = result.voted_song_id;
            // Play a subtle vibration if supported
            if (navigator.vibrate) {
                navigator.vibrate(30);
            }
        } else {
            // Revert on error
            localState.votedSongId = oldVote;
            alert(result.error || 'Error al registrar el voto.');
        }
    } catch (e) {
        localState.votedSongId = oldVote;
        alert('Error de conexión con el servidor de votación.');
    } finally {
        renderVotingGrid();
    }
}

// -------------------------------------------------------------
// OFFLINE CAPABILITIES (R7.6)
// -------------------------------------------------------------

window.addEventListener('online', () => {
    offlineBannerEl.style.display = 'none';
});

window.addEventListener('offline', () => {
    offlineBannerEl.style.display = 'block';
});

if (!navigator.onLine) {
    offlineBannerEl.style.display = 'block';
}

// -------------------------------------------------------------
// PWA INSTALL BANNER
// -------------------------------------------------------------

let deferredPrompt;
window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferredPrompt = e;
    installBannerEl.style.display = 'flex';
});

btnInstallEl.addEventListener('click', async () => {
    if (deferredPrompt) {
        deferredPrompt.prompt();
        const { outcome } = await deferredPrompt.userChoice;
        if (outcome === 'accepted') {
            installBannerEl.style.display = 'none';
        }
        deferredPrompt = null;
    }
});

// Register Service Worker
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('sw.js')
            .then(reg => console.log('Service Worker registered.'))
            .catch(err => console.warn('Service Worker registration failed:', err));
    });
}

// Initialize Application
document.addEventListener('DOMContentLoaded', () => {
    pollStatus();
});
