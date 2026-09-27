<?php
// C:\Users\desktop\Documents\Antigravity\RedTauros Player\api\genres.php
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }

    if (isset($input['id']) && isset($input['is_active'])) {
        try {
            $stmt = $db->prepare("UPDATE generos SET is_active = ? WHERE id = ?");
            $stmt->execute([(int)$input['is_active'], (int)$input['id']]);
            
            // Check that at least one genre remains active
            $active_count = (int)$db->query("SELECT COUNT(*) FROM generos WHERE is_active = 1")->fetchColumn();
            if ($active_count === 0) {
                // Force reactivate this one if no active genres remain
                $reactivate = $db->prepare("UPDATE generos SET is_active = 1 WHERE id = ?");
                $reactivate->execute([(int)$input['id']]);
                echo json_encode([
                    'success' => false,
                    'error' => 'Al menos un género debe permanecer activo.'
                ]);
                exit;
            }

            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to update genre: ' . $e->getMessage()]);
        }
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Missing id or is_active parameter']);
    }
    exit;
}

// GET request
try {
    $stmt = $db->query("SELECT id, name, color, icon, is_active FROM generos ORDER BY name ASC");
    $genres = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Typecast is_active
    foreach ($genres as &$genre) {
        $genre['is_active'] = (int)$genre['is_active'];
    }

    echo json_encode($genres);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to fetch genres: ' . $e->getMessage()]);
}
