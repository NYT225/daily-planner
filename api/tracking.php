<?php
require_once __DIR__ . '/config.php';
cors();

$userId = authenticate();
$method = $_SERVER['REQUEST_METHOD'];
$pdo = db();

if ($method === 'GET') {
    $type = $_GET['type'] ?? '';
    $key = $_GET['key'] ?? '';
    if (!$type || !$key) json_error('type et key requis');

    $stmt = $pdo->prepare('SELECT track_data FROM tracking WHERE user_id = ? AND track_type = ? AND track_key = ?');
    $stmt->execute([$userId, $type, $key]);
    $row = $stmt->fetch();

    json_response(['data' => $row ? json_decode($row['track_data'], true) : new \stdClass()]);

} elseif ($method === 'POST') {
    $input = get_input();
    $type = $input['type'] ?? '';
    $key = $input['key'] ?? '';
    $data = $input['data'] ?? [];

    if (!$type || !$key) json_error('type et key requis');

    $jsonData = json_encode($data, JSON_UNESCAPED_UNICODE);

    $stmt = $pdo->prepare('INSERT INTO tracking (user_id, track_type, track_key, track_data) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE track_data = ?');
    $stmt->execute([$userId, $type, $key, $jsonData, $jsonData]);

    json_response(['saved' => true]);

} else {
    json_error('Méthode non supportée', 405);
}
