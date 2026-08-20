<?php
declare(strict_types=1);

require_once 'config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

 $input = json_decode(file_get_contents('php://input'), true);
 $url = trim($input['url'] ?? '');
 $patientId = trim($input['patient_id'] ?? '');
 $patientName = trim($input['patient_name'] ?? '');

if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid URL']);
    exit;
}

try {
    $db = db();
    
    $stmt = $db->prepare("SELECT code FROM short_links WHERE target_url = :url LIMIT 1");
    $stmt->execute([':url' => $url]);
    $code = $stmt->fetchColumn();
    
    if (!$code) {
        // Updated query to include patient_id and patient_name
        $ins = $db->prepare("INSERT IGNORE INTO short_links (code, target_url, patient_id, patient_name) VALUES (:code, :url, :pid, :pname)");
        $chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        
        for ($i = 0; $i < 5; $i++) {
            $code = '';
            for ($j = 0; $j < 6; $j++) {
                $code .= $chars[random_int(0, 61)];
            }
            $ins->execute([
                ':code' => $code, 
                ':url' => $url, 
                ':pid' => $patientId, 
                ':pname' => $patientName
            ]);
            if ($ins->rowCount() > 0) break;
            $code = null;
        }
    }
    
    if ($code) {
        $baseUrl = defined('APP_URL') ? rtrim(APP_URL, '/') : 
                   (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . 
                   '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
                   
        echo json_encode([
            'success' => true,
            'short_url' => $baseUrl . '/s/?' . $code
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to generate code']);
    }
    
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal error']);
}