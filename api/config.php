<?php
// C:\Users\desktop\Documents\Antigravity\RedTauros Player\api\config.php
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if JSON
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }

    $db->beginTransaction();
    try {
        // Update general session settings
        $updatable_keys = ['fade_out', 'fade_in', 'silence', 'anti_repeat'];
        $update_session = $db->prepare("UPDATE sesion SET value = ? WHERE key = ?");
        
        foreach ($updatable_keys as $key) {
            if (isset($input[$key])) {
                $val = $input[$key];
                
                // Validation for N anti-repeat range
                if ($key === 'anti_repeat') {
                    $val = (int)$val;
                    if ($val < 0) $val = 0;
                    // Upper bound validation range: 0 to library_size - 4.
                    // Checked at runtime or here. Let's cap it reasonably or check library size.
                    $song_count = (int)$db->query("SELECT COUNT(*) FROM canciones")->fetchColumn();
                    $max_limit = max(0, $song_count - 4);
                    if ($val > $max_limit) {
                        $val = $max_limit;
                    }
                } else if (in_array($key, ['fade_out', 'fade_in', 'silence'])) {
                    $val = (float)$val;
                    if ($val < 0) $val = 0;
                }
                
                $update_session->execute([$val, $key]);
            }
        }

        // Update music scan folders if provided
        if (isset($input['folders']) && is_array($input['folders'])) {
            // Delete folders not in the new list
            $placeholders = implode(',', array_fill(0, count($input['folders']), '?'));
            if (count($input['folders']) > 0) {
                $delete_stmt = $db->prepare("DELETE FROM carpetas WHERE path NOT IN ($placeholders)");
                $delete_stmt->execute($input['folders']);
            } else {
                $db->exec("DELETE FROM carpetas");
            }

            // Insert new folders
            $insert_folder = $db->prepare("INSERT OR IGNORE INTO carpetas (path) VALUES (?)");
            foreach ($input['folders'] as $folder) {
                if (!empty(trim($folder))) {
                    $insert_folder->execute([trim($folder)]);
                }
            }
        }

        $db->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $db->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save config: ' . $e->getMessage()]);
    }
    exit;
}

// GET request
try {
    // Read session configuration
    $stmt = $db->query("SELECT key, value FROM sesion");
    $config = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $config[$row['key']] = $row['value'];
    }

    // Convert types
    $config['fade_out'] = (float)$config['fade_out'];
    $config['fade_in'] = (float)$config['fade_in'];
    $config['silence'] = (float)$config['silence'];
    $config['anti_repeat'] = (int)$config['anti_repeat'];
    $config['voting_options'] = json_decode($config['voting_options'], true);

    // Read folders
    $folders_stmt = $db->query("SELECT id, path, is_active FROM carpetas");
    $config['folders'] = $folders_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Dynamic max anti-repeat boundary for the UI
    $song_count = (int)$db->query("SELECT COUNT(*) FROM canciones")->fetchColumn();
    $config['max_anti_repeat'] = max(0, $song_count - 4);

    // Detect server's local IP on the network for QR connectivity
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['SERVER_PORT'] ?? 80) == 443) ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    
    // Check if host is loopback or localhost, and resolve to local IP
    $hostname = host_without_port($host);
    if ($hostname === 'localhost' || $hostname === '127.0.0.1') {
        $local_ip = gethostbyname(gethostname());
        if ($local_ip && $local_ip !== '127.0.0.1') {
            $port = parse_url($protocol . $host, PHP_URL_PORT);
            $host = $local_ip . ($port ? ':' . $port : '');
        }
    }
    
    // Calculate PWA URL
    $pwa_path = dirname($_SERVER['PHP_SELF']);
    // dirname("/api/config.php") -> "/api". We want the root folder:
    $pwa_path = rtrim(dirname($pwa_path), '/\\');
    if ($pwa_path === '') {
        $pwa_path = '/';
    }
    $config['pwa_url'] = $protocol . $host . $pwa_path . '/';

    echo json_encode($config);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to fetch config: ' . $e->getMessage()]);
}

// Function host_without_port is defined in db.php
