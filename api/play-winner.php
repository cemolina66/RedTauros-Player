<?php
// C:\Users\desktop\Documents\Antigravity\RedTauros Player\api\play-winner.php
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
    // 1. Get current voting options and session settings
    $stmt = $db->query("SELECT key, value FROM sesion");
    $session = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $session[$row['key']] = $row['value'];
    }

    $voting_options = json_decode($session['voting_options'] ?? '[]', true);
    $current_song_id = (int)($session['current_song_id'] ?? 0);

    $winner_id = null;
    $total_votes_count = 0;
    $songs = [];

    if (empty($voting_options)) {
        // First startup flow: no song is playing and no voting round is active
        if ($current_song_id === 0) {
            // Fetch active genres
            $active_genres_stmt = $db->query("SELECT id FROM generos WHERE is_active = 1");
            $active_genre_ids = $active_genres_stmt->fetchAll(PDO::FETCH_COLUMN);

            if (empty($active_genre_ids)) {
                http_response_code(400);
                echo json_encode(['error' => 'No hay géneros activos configurados.']);
                exit;
            }

            $active_genres_list = implode(',', $active_genre_ids);
            $first_song_stmt = $db->query("
                SELECT id, file_path, title, artist, genre_id, play_count, last_played, duration 
                FROM canciones 
                WHERE genre_id IN ($active_genres_list) OR (genre_id IS NULL AND 0 IN ($active_genres_list))
                ORDER BY RANDOM() LIMIT 1
            ");
            $first_song = $first_song_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$first_song) {
                // Fallback: pick any song from library
                $first_song = $db->query("SELECT id, file_path, title, artist, genre_id, play_count, last_played, duration FROM canciones ORDER BY RANDOM() LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            }

            if (!$first_song) {
                http_response_code(400);
                echo json_encode(['error' => 'La biblioteca está vacía. Por favor configura carpetas y escanea música primero.']);
                exit;
            }

            $winner_id = (int)$first_song['id'];
            $songs[$winner_id] = $first_song;
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'No hay una ronda de votación activa en este momento.']);
            exit;
        }
    } else {
        // Normal voting round winner calculation
        $placeholders = implode(',', array_fill(0, count($voting_options), '?'));
        $songs_stmt = $db->prepare("
            SELECT id, file_path, title, artist, genre_id, play_count, last_played, duration 
            FROM canciones 
            WHERE id IN ($placeholders)
        ");
        $songs_stmt->execute($voting_options);
        $songs_raw = $songs_stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($songs_raw as $s) {
            $songs[(int)$s['id']] = $s;
        }

        // Ensure all options exist in the array (even if missing from database)
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
        // Calculate weighted scores (R4.2)
        $scores = array_fill_keys($voting_options, 0.0);
        $raw_counts = array_fill_keys($voting_options, 0);

        // Prep statement for user preference bonus
        $pref_stmt = $db->prepare("
            SELECT COUNT(*) FROM votos_historial 
            WHERE user_hash = ? AND genre_id = ? AND timestamp < ?
        ");

        $currentTime = time();

        foreach ($votes as $vote) {
            $sid = (int)$vote['song_id'];
            if (isset($scores[$sid])) {
                // Temporal decay
                $vote_time = strtotime($vote['timestamp']);
                $hours = ($currentTime - $vote_time) / 3600.0;
                if ($hours < 0) $hours = 0;
                $decay_weight = 1.0 / (1.0 + $hours);

                // Preference bonus
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

        // Find highest score
        $max_score = -1.0;
        foreach ($scores as $sid => $score) {
            if ($score > $max_score) {
                $max_score = $score;
            }
        }

        // Find all candidates with the max score (to detect ties)
        $candidates = [];
        foreach ($scores as $sid => $score) {
            // Use minor tolerance for float comparison
            if (abs($score - $max_score) < 0.0001) {
                $candidates[] = $sid;
            }
        }

        if (count($candidates) === 1) {
            $winner_id = $candidates[0];
        } else {
            // Tie breaker: least recently played song (R4.3 / RN7)
            $best_time = null;
            $best_play_count = null;
            
            foreach ($candidates as $cid) {
                $s = $songs[$cid];
                $lp = $s['last_played'];
                $pc = (int)$s['play_count'];
                
                // Prioritize never played (last_played is null)
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
                    // Tie-breaker on last played time: select least play count
                    if ($pc < $best_play_count) {
                        $best_play_count = $pc;
                        $winner_id = $cid;
                    }
                }
            }
        }
    } else {
        // No votes: Weighted random by least played (R4.4 / RN6)
        // Inverse probability calculation: weight = 1 / (play_count + 1)
        $weights = [];
        $total_weight = 0.0;

        foreach ($voting_options as $sid) {
            $pc = (int)$songs[$sid]['play_count'];
            $w = 1.0 / ($pc + 1);
            $weights[$sid] = $w;
            $total_weight += $w;
        }

        // Weighted random selection
        $rand = mt_rand() / mt_getrandmax() * $total_weight;
        $current_sum = 0.0;
        foreach ($weights as $sid => $w) {
            $current_sum += $w;
            if ($rand <= $current_sum) {
                $winner_id = $sid;
                break;
            }
        }

        // Fallback in case of rounding errors
        if (is_null($winner_id)) {
            $winner_id = $voting_options[0];
        }
    }
    } // Closes the normal voting else block (line 75)

    $winner_song = $songs[$winner_id];

    // 4. Update Database in a transaction
    $db->beginTransaction();

    // Log in play history
    $hist_stmt = $db->prepare("INSERT INTO historial (song_id, votes_received) VALUES (?, ?)");
    $hist_stmt->execute([$winner_id, $total_votes_count]);

    // Update play count and last played timestamp
    $up_stmt = $db->prepare("UPDATE canciones SET play_count = play_count + 1, last_played = CURRENT_TIMESTAMP WHERE id = ?");
    $up_stmt->execute([$winner_id]);

    // Update session state
    $sess_song_stmt = $db->prepare("UPDATE sesion SET value = ? WHERE key = 'current_song_id'");
    $sess_song_stmt->execute([$winner_id]);

    $sess_status_stmt = $db->prepare("UPDATE sesion SET value = ? WHERE key = 'status'");
    $sess_status_stmt->execute(['playing']);

    $sess_start_stmt = $db->prepare("UPDATE sesion SET value = ? WHERE key = 'started_at'");
    $sess_start_stmt->execute([time()]);

    // Clear current round votes
    $db->exec("DELETE FROM votos");

    $db->commit();

    // 5. Automatically trigger generation of next 4 options for the new round
    // We run the start-vote selection algorithm internally or call start-vote.
    // Calling start-vote internals is easy. Let's do it:
    $ch = curl_init();
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $uri = dirname($_SERVER['PHP_SELF']) . '/start-vote.php';
    
    // Fallback: if curl is not active or we are running in a CLI test context, we can just run the logic.
    // However, it's safer and cleaner to just execute the logic of start-vote.php directly by including it,
    // but start-vote.php outputs JSON and exits. To make it reusable, let's write a helper or just execute curl.
    // Actually, we can run the selection logic here or just do the curl.
    // Wait, let's just write the selection logic in a shared file or duplicate it briefly. It's very simple.
    // Let's duplicate it briefly or just run a helper. Since start-vote.php is in the same folder:
    // Let's run a curl post request to start-vote.php to trigger it.
    // Wait, PHP-FPM running on local network can curl itself if the webserver is running!
    // But what if the user doesn't have curl extension enabled, or no internet?
    // Let's write the start-vote logic inline to be 100% self-contained and bulletproof without any HTTP loopbacks!
    // This is extremely safe and doesn't depend on network setup or PHP curl extension.
    
    // --- START INLINE SELECT NEXT ROUND ---
    $anti_repeat_N = (int)($session['anti_repeat'] ?? 10);
    $excluded_ids = array_merge([$winner_id], $voting_options); // Exclude the new winner and all options from the round that just ended

    if ($anti_repeat_N > 0) {
        // Query last N played songs
        $hist_ex_stmt = $db->prepare("SELECT song_id FROM historial ORDER BY played_at DESC LIMIT ?");
        $hist_ex_stmt->bindValue(1, $anti_repeat_N, PDO::PARAM_INT);
        $hist_ex_stmt->execute();
        while ($song_id = $hist_ex_stmt->fetchColumn()) {
            if ($song_id) $excluded_ids[] = (int)$song_id;
        }
    }
    $excluded_ids = array_unique($excluded_ids);

    // Fetch active genres
    $active_genres_stmt = $db->query("SELECT id FROM generos WHERE is_active = 1");
    $active_genre_ids = $active_genres_stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($active_genre_ids)) {
        $active_genres_list = implode(',', $active_genre_ids);
        $eligible_stmt = $db->query("
            SELECT id, genre_id FROM canciones 
            WHERE genre_id IN ($active_genres_list) OR (genre_id IS NULL AND 0 IN ($active_genres_list))
        ");
        $all_eligible_songs = $eligible_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Relax if library size is small
        if (count($all_eligible_songs) < 4) {
            $excluded_ids = [$winner_id];
            $eligible_stmt = $db->query("SELECT id, genre_id FROM canciones WHERE genre_id IN ($active_genres_list)");
            $all_eligible_songs = $eligible_stmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($all_eligible_songs) < 4) {
                $eligible_stmt = $db->query("SELECT id, genre_id FROM canciones");
                $all_eligible_songs = $eligible_stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }

        $eligible_songs = [];
        foreach ($all_eligible_songs as $s) {
            $sid = (int)$s['id'];
            if (!in_array($sid, $excluded_ids)) {
                $eligible_songs[$sid] = is_null($s['genre_id']) ? 0 : (int)$s['genre_id'];
            }
        }

        if (count($eligible_songs) < 4) {
            $eligible_songs = [];
            foreach ($all_eligible_songs as $s) {
                $sid = (int)$s['id'];
                if ($sid !== $winner_id) {
                    $eligible_songs[$sid] = is_null($s['genre_id']) ? 0 : (int)$s['genre_id'];
                }
            }
        }

        // Calculate historical vote scores with decay
        $hist_votes_stmt = $db->query("SELECT song_id, timestamp FROM votos_historial");
        $hist_votes_data = $hist_votes_stmt->fetchAll(PDO::FETCH_ASSOC);
        $song_scores = array_fill_keys(array_keys($eligible_songs), 0.0);
        $currentTime = time();

        foreach ($hist_votes_data as $vote) {
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

        // Random 2 from different genres
        $remaining_eligible = [];
        foreach ($eligible_songs as $sid => $gid) {
            if (!in_array($sid, $selected_ids)) {
                $remaining_eligible[$gid][] = $sid;
            }
        }
        $available_genres = array_keys($remaining_eligible);

        $random_selections = [];
        if (count($available_genres) >= 2) {
            $random_genres = array_rand(array_flip($available_genres), 2);
            $random_selections[] = $remaining_eligible[$random_genres[0]][array_rand($remaining_eligible[$random_genres[0]])];
            $random_selections[] = $remaining_eligible[$random_genres[1]][array_rand($remaining_eligible[$random_genres[1]])];
        } else if (count($available_genres) === 1) {
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

        $final_options = array_merge($selected_ids, $random_selections);

        // Fill up to 4 if needed
        if (count($final_options) < 4) {
            foreach (array_keys($eligible_songs) as $sid) {
                if (!in_array($sid, $final_options)) {
                    $final_options[] = $sid;
                    if (count($final_options) === 4) break;
                }
            }
        }

        // Save options to database
        $update_options = $db->prepare("UPDATE sesion SET value = ? WHERE key = 'voting_options'");
        $update_options->execute([json_encode($final_options)]);
    }
    // --- END INLINE SELECT ---

    // Return the winner's info to the player
    echo json_encode([
        'success' => true,
        'winner' => [
            'id' => (int)$winner_song['id'],
            'title' => $winner_song['title'],
            'artist' => $winner_song['artist'],
            'file_path' => $winner_song['file_path'],
            'duration' => (float)$winner_song['duration'],
            'play_count' => (int)$winner_song['play_count'] + 1,
            'votes_received' => $total_votes_count
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to play winner: ' . $e->getMessage()]);
}
