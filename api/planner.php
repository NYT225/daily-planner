<?php
require_once __DIR__ . '/config.php';
cors();

$userId = authenticate();
$method = $_SERVER['REQUEST_METHOD'];
$pdo = db();

if ($method === 'GET') {
    // Charger le planner de l'utilisateur
    $stmt = $pdo->prepare('SELECT id, planner_data, prompt_summary, updated_at FROM planners WHERE user_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$userId]);
    $planner = $stmt->fetch();

    if (!$planner) json_response(['planner' => null]);

    $planner['planner_data'] = json_decode($planner['planner_data'], true);
    json_response(['planner' => $planner]);

} elseif ($method === 'POST') {
    $input = get_input();
    $plannerData = $input['planner_data'] ?? null;
    $summary = $input['prompt_summary'] ?? '';

    if (!$plannerData) json_error('planner_data requis');

    $jsonData = json_encode($plannerData, JSON_UNESCAPED_UNICODE);

    // Upsert : met à jour le planner existant ou en crée un nouveau
    $stmt = $pdo->prepare('SELECT id FROM planners WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $existing = $stmt->fetch();

    if ($existing) {
        $stmt = $pdo->prepare('UPDATE planners SET planner_data = ?, prompt_summary = ? WHERE id = ?');
        $stmt->execute([$jsonData, $summary, $existing['id']]);
        $plannerId = $existing['id'];
    } else {
        $stmt = $pdo->prepare('INSERT INTO planners (user_id, planner_data, prompt_summary) VALUES (?, ?, ?)');
        $stmt->execute([$userId, $jsonData, $summary]);
        $plannerId = (int) $pdo->lastInsertId();
    }

    json_response(['id' => $plannerId, 'saved' => true]);

} else {
    json_error('Méthode non supportée', 405);
}
