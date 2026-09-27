<?php
// C:\Users\desktop\Documents\Antigravity\RedTauros Player\api\scan.php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/id3.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Disable time limits for scanning large libraries
set_time_limit(0);

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

$force_rescan = isset($input['force']) && $input['force'] == 1;

try {
    // 1. Fetch folders
    $folders_stmt = $db->query("SELECT id, path FROM carpetas WHERE is_active = 1");
    $folders = $folders_stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($folders)) {
        echo json_encode([
            'success' => true,
            'added' => 0,
            'skipped' => 0,
            'message' => 'No hay carpetas activas configuradas para escanear.'
        ]);
        exit;
    }

    // 2. Fetch genres for classification mapping
    $genres_stmt = $db->query("SELECT id, name FROM generos");
    $genres = $genres_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Keep track of songs currently in DB (for incremental check)
    $songs_in_db = [];
    $existing_songs_stmt = $db->query("SELECT file_path, id FROM canciones");
    while ($row = $existing_songs_stmt->fetch(PDO::FETCH_ASSOC)) {
        $songs_in_db[$row['file_path']] = $row['id'];
    }

    $added_count = 0;
    $skipped_count = 0;
    $scanned_paths = [];

    // Prepared statements
    $insert_song = $db->prepare("
        INSERT INTO canciones (file_path, title, artist, album, genre_id, duration)
        VALUES (:file_path, :title, :artist, :album, :genre_id, :duration)
    ");
    $update_song = $db->prepare("
        UPDATE canciones 
        SET title = :title, artist = :artist, album = :album, genre_id = :genre_id, duration = :duration
        WHERE file_path = :file_path
    ");

    $supported_extensions = ['mp3', 'm4a', 'flac', 'ogg', 'wav', 'wma'];

    foreach ($folders as $folder) {
        $pathUtf8 = $folder['path'];
        $pathAnsi = winPathToAnsi($pathUtf8);
        if (!is_dir($pathAnsi)) {
            continue; // Skip invalid directories
        }

        // Scan directory recursively (using ANSI path for Windows filesystem)
        $directoryIterator = new RecursiveDirectoryIterator($pathAnsi, RecursiveDirectoryIterator::SKIP_DOTS);
        $iterator = new RecursiveIteratorIterator($directoryIterator, RecursiveIteratorIterator::SELF_FIRST);

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isFile()) {
                $filePathAnsi = $fileInfo->getPathname();
                // Normalize slashes for consistency (Windows compatibility)
                $filePathAnsi = str_replace('\\', '/', $filePathAnsi);
                
                $ext = strtolower($fileInfo->getExtension());
                if (!in_array($ext, $supported_extensions)) {
                    continue; // Skip unsupported formats
                }

                $filePathUtf8 = winPathToUtf8($filePathAnsi);
                $scanned_paths[] = $filePathUtf8;

                // Incremental check: if file is already indexed and we're not forcing re-scan, skip
                if (isset($songs_in_db[$filePathUtf8]) && !$force_rescan) {
                    $skipped_count++;
                    continue;
                }

                // Extract metadata (pass ANSI path to open binary file)
                $meta = AudioMetadataReader::read($filePathAnsi);

                // Genre classification:
                $genre_id = null;
                $classified = false;

                // (1) Check ID3 genre
                if (!empty($meta['genre']) && strtolower($meta['genre']) !== 'desconocido') {
                    $id3_genre = strtolower($meta['genre']);
                    foreach ($genres as $g) {
                        $g_name = strtolower($g['name']);
                        // Check exact or substring match
                        if ($id3_genre === $g_name || strpos($id3_genre, $g_name) !== false || strpos($g_name, $id3_genre) !== false) {
                            $genre_id = $g['id'];
                            $classified = true;
                            break;
                        }
                    }
                }

                // (2) Check folder name in path if not classified yet
                if (!$classified) {
                    $normalized_path = strtolower($filePathUtf8);
                    foreach ($genres as $g) {
                        $g_name = strtolower($g['name']);
                        // Check if the genre name is a folder in the path (e.g. /salsa/)
                        if (strpos($normalized_path, '/' . $g_name . '/') !== false || 
                            strpos($normalized_path, '\\' . $g_name . '\\') !== false ||
                            basename(dirname($filePathUtf8)) === $g['name']) {
                            $genre_id = $g['id'];
                            $classified = true;
                            break;
                        }
                    }
                }

                // (3) Fallback to NULL (represents "Desconocido")
                if (!$classified) {
                    $genre_id = null;
                }

                // Insert or Update in DB
                $params = [
                    ':file_path' => $filePathUtf8,
                    ':title' => $meta['title'],
                    ':artist' => $meta['artist'],
                    ':album' => $meta['album'],
                    ':genre_id' => $genre_id,
                    ':duration' => $meta['duration']
                ];

                if (isset($songs_in_db[$filePathUtf8])) {
                    $update_song->execute($params);
                } else {
                    $insert_song->execute($params);
                    $added_count++;
                }
            }
        }

        // Update folder last scan date
        $update_folder = $db->prepare("UPDATE carpetas SET last_scan = CURRENT_TIMESTAMP WHERE id = ?");
        $update_folder->execute([$folder['id']]);
    }

    // Optional: Clean up songs from database if they no longer exist on disk
    // To be perfectly safe, we only do this on full rescan or if we scan all active folders
    if ($force_rescan && !empty($scanned_paths)) {
        $db_paths = array_keys($songs_in_db);
        $deleted_paths = array_diff($db_paths, $scanned_paths);
        if (!empty($deleted_paths)) {
            $delete_song = $db->prepare("DELETE FROM canciones WHERE file_path = ?");
            foreach ($deleted_paths as $del_path) {
                $delete_song->execute([$del_path]);
            }
        }
    }

    echo json_encode([
        'success' => true,
        'added' => $added_count,
        'skipped' => $skipped_count,
        'total_scanned' => count($scanned_paths),
        'message' => "Escaneo completado. Agregadas: $added_count, Omitidas: $skipped_count."
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error durante el escaneo: ' . $e->getMessage()
    ]);
}
