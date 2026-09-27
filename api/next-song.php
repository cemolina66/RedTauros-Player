<?php
// C:\Users\desktop\Documents\Antigravity\RedTauros Player\api\next-song.php
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
        echo json_encode(null);
        exit;
    }

    // 2. Fetch song details
    $placeholders = implode(',', array_fill(0, count($voting_options), '?'));
    $songs_stmt = $db->prepare("
        SELECT id, file_path, title, artist, genre_id, play_count, last_played, duration 
        FROM canciones 
        WHERE id IN ($placeholders)
    ");
    $songs_stmt->execute($voting_options);
    $songs_raw = $songs_stmt->fetchAll(PDO::FETCH_ASSOC);

    $songs = [];
    foreach ($songs_raw as $s) {
        $songs[(int)$s['id']] = $s;
    }

    // Fill in placeholders if any song was deleted
    foreach ($voting_options as $sid) {
        if (!isset($songs[$sid])) {
            $songs[$sid] = [
                'id' => $sid,
                'file_path' => '',
                'title' => 'Canción No Disponible',
                'artist' => 'Desconocido',
                'genre_id' => null,
                'play_count' => 9999,
                'last_played' => '2000-01-01 00:00:00',
                'duration' => 180.0
            ];
        }
    }

    // 3. Fetch current round votes
    $votes_stmt = $db->query("SELECT song_id, user_hash, timestamp FROM votos");
    $votes = $votes_stmt->fetchAll(PDO::FETCH_ASSOC);
    $total_votes_count = count($votes);

    $winner_id = null;

    if ($total_votes_count > 0) {
        // Calculate weighted scores
        $scores = array_fill_keys($voting_options, 0.0);
        $raw_counts = array_fill_keys($voting_options, 0);

        $pref_stmt = $db->prepare("
            SELECT COUNT(*) FROM votos_historial 
            WHERE user_hash = ? AND genre_id = ? AND timestamp < ?
        ");

        $currentTime = time();

        foreach ($votes as $vote) {
            $sid = (int)$vote['song_id'];
            if (isset($scores[$sid])) {
                // Decay
                $vote_time = strtotime($vote['timestamp']);
                $hours = ($currentTime - $vote_time) / 3600.0;
                if ($hours < 0) $hours = 0;
                $decay_weight = 1.0 / (1.0 + $hours);

                // Preference
                $genre_id = $songs[$sid]['genre_id'];
                $pref_bonus = 0.0;
                if ($genre_id) {
                    $pref_stmt->execute([$vote['user_hash'], $genre_id, $vote['timestamp']]);
                    $prior_count = (int)$pref_stmt->fetchColumn();
                    $pref_bonus = min(0.5, $prior_count * 0.02);
                }

                $scores[$sid] += ($decay_weight + $pref_bonus);
                $raw_counts[$sid]++;
            }
        }

        // Find max
        $max_score = -1.0;
        foreach ($scores as $sid => $score) {
            if ($score > $max_score) {
                $max_score = $score;
            }
        }

        $candidates = [];
        foreach ($scores as $sid => $score) {
            if (abs($score - $max_score) < 0.0001) {
                $candidates[] = $sid;
            }
        }

        if (count($candidates) === 1) {
            $winner_id = $candidates[0];
        } else {
            // Tie breaker
            $best_time = null;
            $best_play_count = null;
            
            foreach ($candidates as $cid) {
                $s = $songs[$cid];
                $lp = $s['last_played'];
                $pc = (int)$s['play_count'];
                
                if (is_null($lp)) {
                    $winner_id = $cid;
                    break;
                }
                
                $lp_time = strtotime($lp);
                if (is_null($best_time) || $lp_time < $best_time) {
                    $best_time = $lp_time;
                    $best_play_count = $pc;
                    $winner_id = $cid;
                } else if ($lp_time === $best_time) {
                    if ($pc < $best_play_count) {
                        $best_play_count = $pc;
                        $winner_id = $cid;
                    }
                }
            }
        }
    } else {
        // No votes: select the one with the lowest play count / oldest played among the options
        // To be consistent with play-winner, we calculate the deterministic winner if random is not needed,
        // or we just return the first option, or the one with the least play count (which has highest probability).
        $best_pc = null;
        foreach ($voting_options as $sid) {
            $pc = (int)$songs[$sid]['play_count'];
            if (is_null($best_pc) || $pc < $best_pc) {
                $best_pc = $pc;
                $winner_id = $sid;
            }
        }
    }

    $winner_song = $songs[$winner_id];

    // Join with genre details
    $genre_info_stmt = $db->prepare("SELECT name, color, icon FROM generos WHERE id = ?");
    $genre_info_stmt->execute([$winner_song['genre_id']]);
    $genre_info = $genre_info_stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'id' => (int)$winner_song['id'],
        'title' => $winner_song['title'],
        'artist' => $winner_song['artist'],
        'duration' => (float)$winner_song['duration'],
        'play_count' => (int)$winner_song['play_count'],
        'genre_name' => $genre_info['name'] ?? 'Desconocido',
        'genre_color' => $genre_info['color'] ?? '#b2bec3',
        'genre_icon' => $genre_info['icon'] ?? '❓'
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to calculate next song: ' . $e->getMessage()]);
}
