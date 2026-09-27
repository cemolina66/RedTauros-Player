<?php
// C:\Users\desktop\Documents\Antigravity\RedTauros Player\api\results.php
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

try {
    // 1. Get current voting options
    $options_stmt = $db->query("SELECT value FROM sesion WHERE key = 'voting_options'");
    $options_json = $options_stmt->fetchColumn();
    $voting_options = json_decode($options_json ?: '[]', true);

    if (empty($voting_options)) {
        echo json_encode([]);
        exit;
    }

    // 2. Fetch song details for the options
    $placeholders = implode(',', array_fill(0, count($voting_options), '?'));
    $songs_stmt = $db->prepare("
        SELECT c.id, c.title, c.artist, c.genre_id, c.duration, c.play_count,
               g.name as genre_name, g.color as genre_color, g.icon as genre_icon
        FROM canciones c
        LEFT JOIN generos g ON c.genre_id = g.id
        WHERE c.id IN ($placeholders)
    ");
    $songs_stmt->execute($voting_options);
    $songs_raw = $songs_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Index songs by id for fast access
    $songs = [];
    foreach ($songs_raw as $s) {
        $sid = (int)$s['id'];
        $songs[$sid] = [
            'id' => $sid,
            'title' => $s['title'],
            'artist' => $s['artist'],
            'genre_id' => is_null($s['genre_id']) ? 0 : (int)$s['genre_id'],
            'genre_name' => $s['genre_name'] ?: 'Desconocido',
            'genre_color' => $s['genre_color'] ?: '#b2bec3',
            'genre_icon' => $s['genre_icon'] ?: '❓',
            'duration' => (float)$s['duration'],
            'play_count' => (int)$s['play_count'],
            'raw_votes' => 0,
            'weighted_score' => 0.0,
            'voters' => []
        ];
    }

    // Ensure all 4 options are represented even if they aren't returned by query (e.g. if a song was deleted)
    foreach ($voting_options as $sid) {
        if (!isset($songs[$sid])) {
            $songs[$sid] = [
                'id' => (int)$sid,
                'title' => 'Canción No Disponible',
                'artist' => 'Desconocido',
                'genre_id' => 0,
                'genre_name' => 'Desconocido',
                'genre_color' => '#b2bec3',
                'genre_icon' => '❓',
                'duration' => 180.0,
                'play_count' => 0,
                'raw_votes' => 0,
                'weighted_score' => 0.0,
                'voters' => []
            ];
        }
    }

    // 3. Fetch votes for the current round
    $votes_stmt = $db->query("SELECT song_id, user_hash, timestamp FROM votos");
    $votes = $votes_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Prepared statement for counting prior votes of a user in a genre (R3.4 / RN4)
    $pref_stmt = $db->prepare("
        SELECT COUNT(*) FROM votos_historial 
        WHERE user_hash = ? AND genre_id = ? AND timestamp < ?
    ");

    $currentTime = time();

    foreach ($votes as $vote) {
        $sid = (int)$vote['song_id'];
        
        // Skip if vote is for a song not in the current options
        if (!isset($songs[$sid])) {
            continue;
        }

        $user_hash = $vote['user_hash'];
        $vote_time = strtotime($vote['timestamp']);
        
        // Calculate temporal decay weight: weight = 1 / (1 + hours_since_vote) (R3.3 / RN3)
        $hours_since_vote = ($currentTime - $vote_time) / 3600.0;
        if ($hours_since_vote < 0) $hours_since_vote = 0; // Guard against minor clock sync issues
        $decay_weight = 1.0 / (1.0 + $hours_since_vote);

        // Calculate preference bonus: +0.02 per prior vote in same genre, max +0.5 (R3.4 / RN4)
        $genre_id = $songs[$sid]['genre_id'];
        $pref_bonus = 0.0;
        
        if ($genre_id > 0) {
            $pref_stmt->execute([$user_hash, $genre_id, $vote['timestamp']]);
            $prior_count = (int)$pref_stmt->fetchColumn();
            $pref_bonus = min(0.5, $prior_count * 0.02);
        }

        $vote_weighted_score = $decay_weight + $pref_bonus;

        // Accumulate
        $songs[$sid]['raw_votes']++;
        $songs[$sid]['weighted_score'] += $vote_weighted_score;
        $songs[$sid]['voters'][] = [
            'user_hash_short' => substr($user_hash, 0, 6),
            'decay' => round($decay_weight, 4),
            'bonus' => round($pref_bonus, 4),
            'total_weight' => round($vote_weighted_score, 4)
        ];
    }

    // Round total weighted scores
    foreach ($songs as &$song) {
        $song['weighted_score'] = round($song['weighted_score'], 4);
    }

    // Sort by weighted score descending, then raw votes descending
    uasort($songs, function($a, $b) {
        if ($a['weighted_score'] == $b['weighted_score']) {
            return $b['raw_votes'] <=> $a['raw_votes'];
        }
        return $b['weighted_score'] <=> $a['weighted_score'];
    });

    // Reset keys to return a standard JSON array
    echo json_encode(array_values($songs));

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to calculate results: ' . $e->getMessage()]);
}
