<?php
// C:\Users\desktop\Documents\Antigravity\RedTauros Player\api\vote.php
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use POST.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

if (!isset($input['song_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Parámetro song_id es requerido.']);
    exit;
}

$song_id = (int)$input['song_id'];
$user_hash = md5($_SERVER['REMOTE_ADDR'] . ($_SERVER['HTTP_USER_AGENT'] ?? ''));

try {
    // 1. Verify if song exists and get its genre
    $song_stmt = $db->prepare("SELECT id, genre_id FROM canciones WHERE id = ?");
    $song_stmt->execute([$song_id]);
    $song = $song_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$song) {
        http_response_code(404);
        echo json_encode(['error' => 'Canción no encontrada.']);
        exit;
    }

    $genre_id = $song['genre_id'];

    // 2. Verify that song is in the current 4 voting options
    $options_stmt = $db->prepare("SELECT value FROM sesion WHERE key = 'voting_options'");
    $options_stmt->execute();
    $options_json = $options_stmt->fetchColumn();
    $voting_options = json_decode($options_json ?: '[]', true);

    if (!in_array($song_id, $voting_options)) {
        http_response_code(400);
        echo json_encode([
            'error' => 'La canción seleccionada no es una opción de votación activa en esta ronda.',
            'voting_options' => $voting_options,
            'attempted' => $song_id
        ]);
        exit;
    }

    // 3. Register the vote in database transaction
    $db->beginTransaction();

    // Remove existing vote for this user in the current round (RN2)
    $delete_stmt = $db->prepare("DELETE FROM votos WHERE user_hash = ?");
    $delete_stmt->execute([$user_hash]);

    // Insert new vote with base weight = 1.0 (R3.2 / RN1)
    $insert_stmt = $db->prepare("INSERT INTO votos (song_id, user_hash, weight) VALUES (?, ?, 1.0)");
    $insert_stmt->execute([$song_id, $user_hash]);

    // Log the historical vote for calculating preference bonus (R3.4 / RN4)
    // To prevent the history from growing infinitely, we can keep the last 1000 records per user or just let it log.
    // Given the local nature, letting it log is fine. Let's insert it:
    $hist_stmt = $db->prepare("INSERT INTO votos_historial (user_hash, song_id, genre_id) VALUES (?, ?, ?)");
    $hist_stmt->execute([$user_hash, $song_id, $genre_id]);

    $db->commit();

    echo json_encode([
        'success' => true,
        'user_hash' => $user_hash,
        'voted_song_id' => $song_id
    ]);

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => 'Error al registrar el voto: ' . $e->getMessage()]);
}
