<?php
// C:\Users\desktop\Documents\Antigravity\RedTauros Player\api\history.php
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

try {
    // 1. Fetch Playback History (R8.1, R8.2, R8.3)
    $history_stmt = $db->query("
        SELECT h.id, h.played_at, h.votes_received,
               c.title, c.artist, c.duration,
               g.name as genre_name, g.color as genre_color, g.icon as genre_icon
        FROM historial h
        LEFT JOIN canciones c ON h.song_id = c.id
        LEFT JOIN generos g ON c.genre_id = g.id
        ORDER BY h.played_at DESC
        LIMIT 50
    ");
    $history = $history_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format fields
    foreach ($history as &$item) {
        $item['id'] = (int)$item['id'];
        $item['votes_received'] = (int)$item['votes_received'];
        $item['duration'] = (float)$item['duration'];
        $item['genre_name'] = $item['genre_name'] ?: 'Desconocido';
        $item['genre_color'] = $item['genre_color'] ?: '#b2bec3';
        $item['genre_icon'] = $item['genre_icon'] ?: '❓';
    }

    // 2. Fetch system statistics (R6.9)
    $total_songs = (int)$db->query("SELECT COUNT(*) FROM canciones")->fetchColumn();
    $active_genres = (int)$db->query("SELECT COUNT(*) FROM generos WHERE is_active = 1")->fetchColumn();
    $total_duration = (float)$db->query("SELECT SUM(duration) FROM canciones")->fetchColumn();

    // 3. Detect audience preferences in the last 30 minutes (R2.3, R6.10)
    // We fetch votes in the last 30 minutes, grouped by genre
    $pref_stmt = $db->query("
        SELECT g.id, g.name, g.color, g.icon, COUNT(v.id) as vote_count
        FROM votos_historial v
        JOIN generos g ON v.genre_id = g.id
        WHERE v.timestamp >= datetime('now', '-30 minutes')
        GROUP BY g.id
        ORDER BY vote_count DESC
        LIMIT 5
    ");
    $preferences = $pref_stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($preferences as &$pref) {
        $pref['id'] = (int)$pref['id'];
        $pref['vote_count'] = (int)$pref['vote_count'];
    }

    echo json_encode([
        'history' => $history,
        'stats' => [
            'total_songs' => $total_songs,
            'active_genres' => $active_genres,
            'total_duration' => $total_duration,
            'audience_preferences' => $preferences
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to fetch history: ' . $e->getMessage()]);
}
