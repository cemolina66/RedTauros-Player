<?php
// C:\Users\desktop\Documents\Antigravity\RedTauros Player\api\start-vote.php
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use POST.']);
    exit;
}

try {
    // 1. Get session variables
    $stmt = $db->query("SELECT key, value FROM sesion");
    $session = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $session[$row['key']] = $row['value'];
    }

    $current_song_id = (int)($session['current_song_id'] ?? 0);
    $anti_repeat_N = (int)($session['anti_repeat'] ?? 10);

    // 2. Determine songs to exclude (current song + previous voting options + last N played songs)
    $previous_options = json_decode($session['voting_options'] ?? '[]', true);
    $excluded_ids = array_merge([$current_song_id], $previous_options);

    if ($anti_repeat_N > 0) {
        $hist_stmt = $db->prepare("
            SELECT song_id FROM historial 
            ORDER BY played_at DESC LIMIT ?
        ");
        $hist_stmt->bindValue(1, $anti_repeat_N, PDO::PARAM_INT);
        $hist_stmt->execute();
        while ($song_id = $hist_stmt->fetchColumn()) {
            if ($song_id) {
                $excluded_ids[] = (int)$song_id;
            }
        }
    }
    $excluded_ids = array_unique($excluded_ids);

    // 3. Fetch all active genres
    $active_genres_stmt = $db->query("SELECT id FROM generos WHERE is_active = 1");
    $active_genre_ids = $active_genres_stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($active_genre_ids)) {
        http_response_code(400);
        echo json_encode(['error' => 'No hay géneros activos configurados.']);
        exit;
    }

    // 4. Fetch all eligible songs (belonging to active genres)
    $active_genres_list = implode(',', $active_genre_ids);
    $songs_stmt = $db->query("
        SELECT id, genre_id FROM canciones 
        WHERE genre_id IN ($active_genres_list) OR (genre_id IS NULL AND 0 IN ($active_genres_list))
    ");
    $all_eligible_songs = $songs_stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($all_eligible_songs) < 4) {
        // If we have fewer than 4 songs in active genres, let's relax exclusions
        $excluded_ids = [];
        if ($current_song_id > 0) {
            $excluded_ids[] = $current_song_id; // Still exclude current if possible
        }
        
        $songs_stmt = $db->query("
            SELECT id, genre_id FROM canciones 
            WHERE genre_id IN ($active_genres_list)
        ");
        $all_eligible_songs = $songs_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($all_eligible_songs) < 4) {
            // Still not enough? Try including all songs regardless of active genres
            $songs_stmt = $db->query("SELECT id, genre_id FROM canciones");
            $all_eligible_songs = $songs_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (count($all_eligible_songs) < 4) {
                http_response_code(400);
                echo json_encode([
                    'error' => 'La biblioteca musical debe tener al menos 4 canciones para iniciar la votación.',
                    'current_count' => count($all_eligible_songs)
                ]);
                exit;
            }
        }
    }

    // Filter out excluded songs
    $eligible_songs = [];
    foreach ($all_eligible_songs as $s) {
        $sid = (int)$s['id'];
        if (!in_array($sid, $excluded_ids)) {
            $eligible_songs[$sid] = is_null($s['genre_id']) ? 0 : (int)$s['genre_id'];
        }
    }

    // If exclusions left us with fewer than 4 options, relax anti-repeat
    if (count($eligible_songs) < 4) {
        $eligible_songs = [];
        foreach ($all_eligible_songs as $s) {
            $sid = (int)$s['id'];
            if ($sid !== $current_song_id) { // Only force-exclude current song
                $eligible_songs[$sid] = is_null($s['genre_id']) ? 0 : (int)$s['genre_id'];
            }
        }
    }

    // 5. Calculate historical vote scores (with decay) for eligible songs
    $hist_votes_stmt = $db->query("SELECT song_id, timestamp FROM votos_historial");
    $hist_votes = $hist_votes_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $currentTime = time();
    $song_scores = array_fill_keys(array_keys($eligible_songs), 0.0);

    foreach ($hist_votes as $vote) {
        $sid = (int)$vote['song_id'];
        if (isset($eligible_songs[$sid])) {
            $hours = ($currentTime - strtotime($vote['timestamp'])) / 3600.0;
            if ($hours < 0) $hours = 0;
            $weight = 1.0 / (1.0 + $hours);
            $song_scores[$sid] += $weight;
        }
    }

    // Filter out songs with score > 0 (historically voted)
    $active_scores = array_filter($song_scores, function($v) { return $v > 0.0; });
    
    $selected_ids = [];
    if (count($active_scores) >= 2) {
        // Weighted random selection of 2 different songs
        for ($i = 0; $i < 2; $i++) {
            $total = array_sum($active_scores);
            if ($total <= 0) {
                $key = array_rand($active_scores);
                $selected_ids[] = $key;
                unset($active_scores[$key]);
            } else {
                $rand = mt_rand() / mt_getrandmax() * $total;
                $sum = 0.0;
                foreach ($active_scores as $key => $weight) {
                    $sum += $weight;
                    if ($rand <= $sum) {
                        $selected_ids[] = $key;
                        unset($active_scores[$key]);
                        break;
                    }
                }
            }
        }
    } else if (count($active_scores) === 1) {
        // Only 1 song has votes, pick it and 1 random fallback from remaining
        $keys = array_keys($active_scores);
        $selected_ids[] = $keys[0];
        
        $remaining_keys = array_diff(array_keys($song_scores), $selected_ids);
        if (!empty($remaining_keys)) {
            $rand_key = $remaining_keys[array_rand($remaining_keys)];
            $selected_ids[] = $rand_key;
        }
    } else {
        // No songs have votes in history yet, pick 2 random fallback songs
        $keys = array_keys($song_scores);
        if (!empty($keys)) {
            shuffle($keys);
            if (count($keys) >= 1) $selected_ids[] = $keys[0];
            if (count($keys) >= 2) $selected_ids[] = $keys[1];
        }
    }

    // 6. Select 2 random songs from different genres
    $remaining_eligible = [];
    foreach ($eligible_songs as $sid => $gid) {
        if (!in_array($sid, $selected_ids)) {
            $remaining_eligible[$gid][] = $sid;
        }
    }

    // Find genres available in remaining songs
    $available_genres = array_keys($remaining_eligible);

    // Pick 2 random songs
    $random_selections = [];
    if (count($available_genres) >= 2) {
        // We have at least 2 different genres, select 2 distinct genres
        $random_genres = array_rand(array_flip($available_genres), 2);
        
        $random_selections[] = $remaining_eligible[$random_genres[0]][array_rand($remaining_eligible[$random_genres[0]])];
        $random_selections[] = $remaining_eligible[$random_genres[1]][array_rand($remaining_eligible[$random_genres[1]])];
    } else if (count($available_genres) === 1) {
        // Only 1 genre available (or only 1 active genre)
        $gid = $available_genres[0];
        $songs_list = $remaining_eligible[$gid];
        if (count($songs_list) >= 2) {
            $rand_keys = array_rand($songs_list, 2);
            $random_selections[] = $songs_list[$rand_keys[0]];
            $random_selections[] = $songs_list[$rand_keys[1]];
        } else if (count($songs_list) === 1) {
            $random_selections[] = $songs_list[0];
        }
    }

    // Merge selections
    $final_options = array_merge($selected_ids, $random_selections);

    // If we still don't have 4 songs, fill it up from any eligible songs
    if (count($final_options) < 4) {
        foreach (array_keys($eligible_songs) as $sid) {
            if (!in_array($sid, $final_options)) {
                $final_options[] = $sid;
                if (count($final_options) === 4) break;
            }
        }
    }

    // 7. Update database transaction
    $db->beginTransaction();

    // Reset current round votes
    $db->exec("DELETE FROM votos");

    // Save voting options
    $update_options = $db->prepare("UPDATE sesion SET value = ? WHERE key = 'voting_options'");
    $update_options->execute([json_encode($final_options)]);

    $db->commit();

    // Fetch full info of selected songs
    $placeholders = implode(',', array_fill(0, count($final_options), '?'));
    $songs_info_stmt = $db->prepare("
        SELECT c.id, c.title, c.artist, g.name as genre_name, g.color as genre_color, g.icon as genre_icon
        FROM canciones c
        LEFT JOIN generos g ON c.genre_id = g.id
        WHERE c.id IN ($placeholders)
    ");
    $songs_info_stmt->execute($final_options);
    $songs_info = $songs_info_stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'voting_options' => $final_options,
        'songs' => $songs_info
    ]);

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => 'Failed to start voting: ' . $e->getMessage()]);
}
