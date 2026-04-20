<?php
require_once __DIR__ . '/config.php';
cors();

$userId = authenticate();
$pdo = db();

$stmt = $pdo->prepare('SELECT status, trial_ends_at, current_period_end, created_at FROM subscriptions WHERE user_id = ? ORDER BY id DESC LIMIT 1');
$stmt->execute([$userId]);
$sub = $stmt->fetch();

if (!$sub) json_error('Aucun abonnement trouvé', 404);

// Auto-expire le trial si dépassé
if ($sub['status'] === 'trial' && strtotime($sub['trial_ends_at']) < time()) {
    $stmt = $pdo->prepare('UPDATE subscriptions SET status = ? WHERE user_id = ? AND status = ?');
    $stmt->execute(['expired', $userId, 'trial']);
    $sub['status'] = 'expired';
}

// Auto-expire l'abonnement si current_period_end dépassé
if ($sub['status'] === 'active' && $sub['current_period_end'] && strtotime($sub['current_period_end']) < time()) {
    $stmt = $pdo->prepare('UPDATE subscriptions SET status = ? WHERE user_id = ? AND status = ?');
    $stmt->execute(['expired', $userId, 'active']);
    $sub['status'] = 'expired';
}

$daysLeft = null;
if ($sub['status'] === 'trial') {
    $daysLeft = max(0, (int) ceil((strtotime($sub['trial_ends_at']) - time()) / 86400));
} elseif ($sub['status'] === 'active' && $sub['current_period_end']) {
    $daysLeft = max(0, (int) ceil((strtotime($sub['current_period_end']) - time()) / 86400));
}

// Compteur de générations du mois
$monthKey = 'gen_count_' . date('Y-m');
$stmt = $pdo->prepare("SELECT track_data FROM tracking WHERE user_id = ? AND track_type = 'daily' AND track_key = ?");
$stmt->execute([$userId, $monthKey]);
$row = $stmt->fetch();
$genData = $row ? json_decode($row['track_data'], true) : [];
$genCount = $genData['count'] ?? 0;

json_response([
    'status' => $sub['status'],
    'trial_ends_at' => $sub['trial_ends_at'],
    'current_period_end' => $sub['current_period_end'],
    'days_left' => $daysLeft,
    'gen_count' => $genCount,
    'gen_limit' => $sub['status'] === 'trial' ? GEN_LIMIT_TRIAL : GEN_LIMIT_PRO,
    'is_active' => in_array($sub['status'], ['trial', 'active']),
    'checkout_url' => ''
]);
