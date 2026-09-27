<?php
// C:\Users\desktop\Documents\Antigravity\RedTauros Player\api\songs.php
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

try {
    // Join with genres to fetch names/colors/icons
    $query = "
        SELECT c.id, c.file_path, c.title, c.artist, c.album, c.duration, c.play_count,
               g.id as genre_id, g.name as genre_name, g.color as genre_color, g.icon as genre_icon
        FROM canciones c
        LEFT JOIN generos g ON c.genre_id = g.id
        ORDER BY c.title ASC
    ";
    
    $stmt = $db->query($query);
    $songs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format songs and add "Desconocido" defaults if genre is null
    foreach ($songs as &$song) {
        $song['id'] = (int)$song['id'];
        $song['duration'] = (float)$song['duration'];
        $song['play_count'] = (int)$song['play_count'];
        
        if (is_null($song['genre_id'])) {
            $song['genre_id'] = 0;
            $song['genre_name'] = 'Desconocido';
            $song['genre_color'] = '#b2bec3';
            $song['genre_icon'] = '❓';
        } else {
            $song['genre_id'] = (int)$song['genre_id'];
        }
    }

    echo json_encode($songs);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to fetch songs: ' . $e->getMessage()]);
}
