<?php
require_once __DIR__ . '/config.php';
cors();

$userId = authenticate();
$method = $_SERVER['REQUEST_METHOD'];
$pdo = db();

if ($method === 'GET') {
    // Charger le profil complet
    $stmt = $pdo->prepare('SELECT id, email, first_name, last_name, motivation, focus, sound_enabled, created_at FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user) json_error('Utilisateur introuvable', 404);

    // Badges
    $stmt = $pdo->prepare('SELECT badge_id, unlocked_at FROM badges WHERE user_id = ?');
    $stmt->execute([$userId]);
    $badges = $stmt->fetchAll();

    // Subscription
    $stmt = $pdo->prepare('SELECT status, trial_ends_at, current_period_end FROM subscriptions WHERE user_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$userId]);
    $sub = $stmt->fetch();

    // Stats globales
    $stmt = $pdo->prepare("SELECT track_data FROM tracking WHERE user_id = ? AND track_type = 'daily'");
    $stmt->execute([$userId]);
    $allDaily = $stmt->fetchAll();

    $totalChecked = 0;
    $perfectDays = 0;
    $bestStreak = 0;
    $currentStreak = 0;

    // Calculer stats depuis le tracking
    foreach ($allDaily as $row) {
        $data = json_decode($row['track_data'], true);
        if (!$data) continue;
        // Score entries
        if (isset($data['points'])) continue; // skip score entries
        if (isset($data['count'])) continue; // skip streak entries
        $checks = array_filter($data, function($v) { return $v === true; });
        $totalChecked += count($checks);
    }

    // Get best streak & perfect days from score tracking
    $stmt = $pdo->prepare("SELECT track_key, track_data FROM tracking WHERE user_id = ? AND track_type = 'daily' AND track_key LIKE 'score-%'");
    $stmt->execute([$userId]);
    $scores = $stmt->fetchAll();
    $totalPoints = 0;
    foreach ($scores as $s) {
        $d = json_decode($s['track_data'], true);
        $totalPoints += ($d['points'] ?? 0);
    }

    $stmt = $pdo->prepare("SELECT track_data FROM tracking WHERE user_id = ? AND track_type = 'daily' AND track_key = 'streak'");
    $stmt->execute([$userId]);
    $streakRow = $stmt->fetch();
    $streakData = $streakRow ? json_decode($streakRow['track_data'], true) : [];

    json_response([
        'user' => $user,
        'badges' => $badges,
        'subscription' => $sub,
        'stats' => [
            'total_checked' => $totalChecked,
            'total_points' => $totalPoints,
            'current_streak' => $streakData['count'] ?? 0,
            'best_streak' => $streakData['best'] ?? ($streakData['count'] ?? 0),
            'member_since_days' => max(1, (int)ceil((time() - strtotime($user['created_at'])) / 86400))
        ],
        'checkout_url' => ''
    ]);

} elseif ($method === 'POST') {
    $input = get_input();
    $action = $input['action'] ?? 'update';

    if ($action === 'update') {
        // Mettre à jour le profil
        $fields = [];
        $params = [];

        if (isset($input['first_name'])) { $fields[] = 'first_name = ?'; $params[] = substr($input['first_name'], 0, 100); }
        if (isset($input['last_name'])) { $fields[] = 'last_name = ?'; $params[] = substr($input['last_name'], 0, 100); }
        if (isset($input['motivation'])) { $fields[] = 'motivation = ?'; $params[] = substr($input['motivation'], 0, 500); }
        if (isset($input['focus'])) { $fields[] = 'focus = ?'; $params[] = substr($input['focus'], 0, 255); }
        if (isset($input['sound_enabled'])) { $fields[] = 'sound_enabled = ?'; $params[] = $input['sound_enabled'] ? 1 : 0; }

        if (empty($fields)) json_error('Rien à mettre à jour');

        $params[] = $userId;
        $stmt = $pdo->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?');
        $stmt->execute($params);

        json_response(['updated' => true]);

    } elseif ($action === 'change_password') {
        $current = $input['current_password'] ?? '';
        $newPw = $input['new_password'] ?? '';

        if (strlen($newPw) < 8) json_error('Mot de passe : 8 caractères minimum');

        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!password_verify($current, $user['password_hash'])) json_error('Mot de passe actuel incorrect');

        $hash = password_hash($newPw, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([$hash, $userId]);

        json_response(['updated' => true]);

    } elseif ($action === 'unlock_badge') {
        $badgeId = $input['badge_id'] ?? '';
        $validBadges = ['first_perfect','streak_7','streak_30','tasks_100','roadmap_done','early_bird','week_perfect','level_machine'];
        if (!in_array($badgeId, $validBadges)) json_error('Badge invalide');

        $stmt = $pdo->prepare('INSERT IGNORE INTO badges (user_id, badge_id) VALUES (?, ?)');
        $stmt->execute([$userId, $badgeId]);

        json_response(['unlocked' => true]);

    } elseif ($action === 'delete_account') {
        $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        json_response(['deleted' => true]);

    } else {
        json_error('Action inconnue');
    }

} else {
    json_error('Méthode non supportée', 405);
}
