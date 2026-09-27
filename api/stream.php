<?php
// C:\Users\desktop\Documents\Antigravity\RedTauros Player\api\stream.php
require_once __DIR__ . '/db.php';

// Turn off output buffering to allow real-time streaming
if (ob_get_level()) {
    ob_end_clean();
}

if (!isset($_GET['id'])) {
    http_response_code(400);
    echo "ID es requerido";
    exit;
}

$id = (int)$_GET['id'];

try {
    $stmt = $db->prepare("SELECT file_path FROM canciones WHERE id = ?");
    $stmt->execute([$id]);
    $file_path = $stmt->fetchColumn();

    $file_path_ansi = winPathToAnsi($file_path);

    if (!$file_path || !file_exists($file_path_ansi)) {
        http_response_code(404);
        echo "Archivo de audio no encontrado en el servidor.";
        exit;
    }

    $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
    $mime_types = [
        'mp3' => 'audio/mpeg',
        'm4a' => 'audio/mp4',
        'flac' => 'audio/flac',
        'ogg' => 'audio/ogg',
        'wav' => 'audio/wav',
        'wma' => 'audio/x-ms-wma'
    ];
    $mime = $mime_types[$ext] ?? 'application/octet-stream';

    $size = filesize($file_path_ansi);
    $fp = fopen($file_path_ansi, 'rb');
    if (!$fp) {
        http_response_code(500);
        echo "No se pudo abrir el archivo de audio.";
        exit;
    }

    header("Content-Type: $mime");
    header("Accept-Ranges: bytes");
    header("Cache-Control: public, max-age=31536000");

    $start = 0;
    $end = $size - 1;

    if (isset($_SERVER['HTTP_RANGE'])) {
        // Parse HTTP range header
        if (preg_match('/bytes=(\d+)-(\d+)?/', $_SERVER['HTTP_RANGE'], $matches)) {
            $start = (int)$matches[1];
            if (isset($matches[2]) && is_numeric($matches[2])) {
                $end = (int)$matches[2];
            }
        }
        
        header('HTTP/1.1 206 Partial Content');
        header("Content-Range: bytes $start-$end/$size");
        $content_length = $end - $start + 1;
        header("Content-Length: $content_length");
    } else {
        header("Content-Length: $size");
    }

    fseek($fp, $start);
    $chunk_size = 16384; // 16KB chunks
    $bytes_to_send = $end - $start + 1;

    while (!feof($fp) && $bytes_to_send > 0) {
        $read_size = min($chunk_size, $bytes_to_send);
        $data = fread($fp, $read_size);
        echo $data;
        $bytes_to_send -= strlen($data);
        flush();
    }
    
    fclose($fp);

} catch (Exception $e) {
    http_response_code(500);
    echo "Error de streaming: " . $e->getMessage();
}
