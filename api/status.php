<?php
// C:\Users\desktop\Documents\Antigravity\RedTauros Player\api\status.php
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Set time limit for long polling (max 30 seconds)
set_time_limit(35);

$client_version = $_GET['version'] ?? '';

// Helper function to fetch the current system state and compute its version hash
function getSystemState($db) {
    // 1. Fetch all session variables
    $stmt = $db->query("SELECT key, value FROM sesion");
    $session = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $session[$row['key']] = $row['value'];
    }

    // Calculate current progress without database-write overhead
    $current_progress = 0;
    if (($session['status'] ?? '') === 'playing' && isset($session['started_at'])) {
        $current_progress = time() - (int)$session['started_at'];
    }

    // Determine if this user has already voted in the current round
    $user_hash = md5($_SERVER['REMOTE_ADDR'] . ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $user_vote_stmt = $db->prepare("SELECT song_id FROM votos WHERE user_hash = ?");
    $user_vote_stmt->execute([$user_hash]);
    $user_voted_song_id = $user_vote_stmt->fetchColumn();
    $user_voted_song_id = $user_voted_song_id ? (int)$user_voted_song_id : null;

    // 2. Fetch current song details if playing
    $current_song = null;
    $current_song_id = (int)($session['current_song_id'] ?? 0);
    if ($current_song_id > 0) {
        $song_stmt = $db->prepare("
            SELECT c.id, c.file_path, c.title, c.artist, c.album, c.duration,
                   g.name as genre_name, g.color as genre_color, g.icon as genre_icon
            FROM canciones c
            LEFT JOIN generos g ON c.genre_id = g.id
            WHERE c.id = ?
        ");
        $song_stmt->execute([$current_song_id]);
        $current_song = $song_stmt->fetch(PDO::FETCH_ASSOC);
        if ($current_song) {
            $current_song['id'] = (int)$current_song['id'];
            $current_song['duration'] = (float)$current_song['duration'];
            $current_song['genre_name'] = $current_song['genre_name'] ?: 'Desconocido';
            $current_song['genre_color'] = $current_song['genre_color'] ?: '#b2bec3';
            $current_song['genre_icon'] = $current_song['genre_icon'] ?: '❓';
        }
    }

    // 3. Fetch active votes hash (to detect new votes in real-time)
    $votes_stmt = $db->query("SELECT user_hash, song_id, timestamp FROM votos ORDER BY user_hash ASC");
    $votes_data = '';
    $raw_votes_count = 0;
    while ($vote = $votes_stmt->fetch(PDO::FETCH_ASSOC)) {
        $votes_data .= $vote['user_hash'] . ':' . $vote['song_id'] . '|';
        $raw_votes_count++;
    }
    $votes_hash = md5($votes_data);

    // 4. Compute unique state version hash
    $state_data = ($session['status'] ?? '') . '|' . 
                  $current_song_id . '|' . 
                  ($session['voting_options'] ?? '') . '|' . 
                  ($session['fade_out'] ?? '') . '|' . 
                  ($session['fade_in'] ?? '') . '|' . 
                  ($session['silence'] ?? '') . '|' . 
                  ($session['anti_repeat'] ?? '') . '|' . 
                  $votes_hash;
    $version_hash = md5($state_data);

    return [
        'state' => [
            'status' => $session['status'] ?? 'idle',
            'current_song_id' => $current_song_id,
            'current_song' => $current_song,
            'current_progress' => $current_progress,
            'user_voted_song_id' => $user_voted_song_id,
            'voting_options_ids' => json_decode($session['voting_options'] ?? '[]', true),
            'config' => [
                'fade_out' => (float)($session['fade_out'] ?? 2.0),
                'fade_in' => (float)($session['fade_in'] ?? 1.5),
                'silence' => (float)($session['silence'] ?? 0.5),
                'anti_repeat' => (int)($session['anti_repeat'] ?? 10)
            ],
            'votes_count' => $raw_votes_count,
            'version' => $version_hash
        ],
        'version' => $version_hash
    ];
}

$timeout_seconds = 20;
$sleep_microseconds = 500000; // 0.5 seconds
$elapsed = 0;

$state_package = getSystemState($db);

// If client doesn't provide a version or it's already different, return immediately
if (empty($client_version) || $client_version !== $state_package['version']) {
    echo json_encode($state_package['state']);
    exit;
}

// Long-polling loop
while ($elapsed < $timeout_seconds) {
    usleep($sleep_microseconds);
    $elapsed += 0.5;

    // Refresh DB connection to avoid lock issues in long-running CLI threads
    // In standard FPM, the connection is clean, but let's check
    $state_package = getSystemState($db);
    
    if ($state_package['version'] !== $client_version) {
        break;
    }
}

echo json_encode($state_package['state']);
