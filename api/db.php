<?php
// C:\Users\desktop\Documents\Antigravity\RedTauros Player\api\db.php

$data_dir = __DIR__ . '/../data';
if (!file_exists($data_dir)) {
    mkdir($data_dir, 0777, true);
}

$db_path = $data_dir . '/fiesta_musical.db';

try {
    $db = new PDO('sqlite:' . $db_path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA foreign_keys = ON;');
    $db->exec('PRAGMA journal_mode = WAL;');
} catch (PDOException $e) {
    header('Content-Type: application/json', true, 500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

// Create tables if they don't exist
$db->exec("
CREATE TABLE IF NOT EXISTS carpetas (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    path TEXT UNIQUE NOT NULL,
    is_active INTEGER DEFAULT 1,
    last_scan DATETIME
);

CREATE TABLE IF NOT EXISTS generos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT UNIQUE NOT NULL,
    color TEXT NOT NULL,
    icon TEXT NOT NULL,
    is_active INTEGER DEFAULT 1
);

CREATE TABLE IF NOT EXISTS canciones (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    file_path TEXT UNIQUE NOT NULL,
    title TEXT NOT NULL,
    artist TEXT NOT NULL,
    album TEXT,
    genre_id INTEGER REFERENCES generos(id) ON DELETE SET NULL,
    duration REAL NOT NULL,
    play_count INTEGER DEFAULT 0,
    last_played DATETIME
);

CREATE TABLE IF NOT EXISTS votos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    song_id INTEGER REFERENCES canciones(id) ON DELETE CASCADE,
    user_hash TEXT UNIQUE NOT NULL,
    weight REAL NOT NULL,
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS historial (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    song_id INTEGER REFERENCES canciones(id) ON DELETE SET NULL,
    played_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    votes_received INTEGER DEFAULT 0
);

CREATE TABLE IF NOT EXISTS sesion (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS votos_historial (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_hash TEXT NOT NULL,
    song_id INTEGER REFERENCES canciones(id) ON DELETE CASCADE,
    genre_id INTEGER REFERENCES generos(id) ON DELETE CASCADE,
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
);
");

// Initialize predefined genres if empty
$stmt = $db->query("SELECT COUNT(*) FROM generos");
if ($stmt->fetchColumn() == 0) {
    $predefined_genres = [
        ['Salsa', '#e74c3c', '💃'],
        ['Merengue', '#f1c40f', '🕺'],
        ['Bachata', '#9b59b6', '🌹'],
        ['Rock Español', '#e67e22', '🎸'],
        ['Rock Inglés', '#34495e', '⚡'],
        ['Vallenato', '#2ecc71', '🪗'],
        ['Pop', '#e84393', '🎤'],
        ['Reggaeton', '#d63031', '🔥'],
        ['Balada', '#74b9ff', '🍷'],
        ['Jazz', '#ffeaa7', '🎷'],
        ['Electrónica', '#00cec9', '🎧'],
        ['Cumbia', '#00b894', '🥁'],
        ['Champeta', '#fdcb6e', '🌴'],
        ['Boleros', '#6c5ce7', '🎻'],
        ['Tropical', '#ff7675', '🍹'],
        ['Cantina', '#a29bfe', '🥃'],
        ['Rancheras', '#b2bec3', '🤠']
    ];

    $insert = $db->prepare("INSERT INTO generos (name, color, icon) VALUES (?, ?, ?)");
    foreach ($predefined_genres as $genre) {
        $insert->execute($genre);
    }
}

// Simple helper function to strip port
function host_without_port($host) {
    $parts = explode(':', $host);
    return $parts[0];
}

// Windows encoding conversion helpers for local filesystem paths (Windows-1252 <-> UTF-8)
function winPathToUtf8($path) {
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        if (function_exists('mb_check_encoding') && !mb_check_encoding($path, 'UTF-8')) {
            return mb_convert_encoding($path, 'UTF-8', 'Windows-1252');
        } elseif (function_exists('iconv')) {
            $converted = @iconv('Windows-1252', 'UTF-8//IGNORE', $path);
            if ($converted !== false) return $converted;
        }
    }
    return $path;
}

function winPathToAnsi($path) {
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        if (function_exists('mb_check_encoding') && mb_check_encoding($path, 'UTF-8')) {
            return mb_convert_encoding($path, 'Windows-1252', 'UTF-8');
        } elseif (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'Windows-1252//IGNORE', $path);
            if ($converted !== false) return $converted;
        }
    }
    return $path;
}

// Initialize session keys if not present
$session_keys = [
    'status' => 'idle', // idle, playing, transitional
    'current_song_id' => '',
    'voting_options' => '[]', // JSON array of 4 song IDs
    'fade_out' => '2.0',
    'fade_in' => '1.5',
    'silence' => '0.5',
    'anti_repeat' => '10',
    'started_at' => '0'
];

$check_session = $db->prepare("SELECT COUNT(*) FROM sesion WHERE key = ?");
$insert_session = $db->prepare("INSERT INTO sesion (key, value) VALUES (?, ?)");

foreach ($session_keys as $key => $val) {
    $check_session->execute([$key]);
    if ($check_session->fetchColumn() == 0) {
        $insert_session->execute([$key, $val]);
    }
}
