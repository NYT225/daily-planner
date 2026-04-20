<?php
require_once __DIR__ . '/config.php';
cors();

$action = $_GET['action'] ?? '';

if ($action === 'register') {
    $input = get_input();
    $email = trim($input['email'] ?? '');
    $password = $input['password'] ?? '';
    $firstName = trim($input['first_name'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_error('Email invalide');
    if (strlen($password) < 8) json_error('Mot de passe : 8 caractères minimum');

    $pdo = db();

    // Vérifier si l'email existe déjà
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) json_error('Un compte existe déjà avec cet email');

    // Créer l'utilisateur
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, first_name) VALUES (?, ?, ?)');
    $stmt->execute([$email, $hash, substr($firstName, 0, 100)]);
    $userId = (int) $pdo->lastInsertId();

    // Créer le trial (30 jours)
    $trialEnd = date('Y-m-d H:i:s', strtotime('+30 days'));
    $stmt = $pdo->prepare('INSERT INTO subscriptions (user_id, status, trial_ends_at) VALUES (?, ?, ?)');
    $stmt->execute([$userId, 'trial', $trialEnd]);

    // Email de bienvenue
    $displayName = $firstName ?: explode('@', $email)[0];
    $welcomeHtml = '
<!DOCTYPE html>
<html><head><meta charset="UTF-8"></head>
<body style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;background:#FAF7F2;padding:40px 20px;margin:0">
<div style="max-width:480px;margin:0 auto;background:#ffffff;border-radius:16px;border:1px solid #E5DFD5;overflow:hidden">
  <div style="background:#C4654A;padding:24px;text-align:center">
    <span style="font-family:Georgia,serif;font-size:20px;font-weight:700;color:#FAF7F2">Lifestyle Planner</span>
  </div>
  <div style="padding:32px 28px">
    <h1 style="font-size:22px;font-weight:700;color:#1A1A1A;margin:0 0 12px">Bienvenue ' . htmlspecialchars($displayName) . ' !</h1>
    <p style="font-size:15px;color:#5C5650;line-height:1.6;margin:0 0 8px">Ton compte est créé. Tu as <strong>30 jours gratuits</strong> pour structurer ta vie avec Lifestyle Planner.</p>
    <p style="font-size:15px;color:#5C5650;line-height:1.6;margin:0 0 24px">Crée ton premier planning en 2 minutes :</p>
    <a href="https://lifestyleplanner.fr/app.html" style="display:block;background:#C4654A;color:#ffffff;text-decoration:none;text-align:center;padding:14px 24px;border-radius:10px;font-size:15px;font-weight:600">Créer mon planning</a>
    <p style="font-size:13px;color:#9B918A;line-height:1.6;margin:24px 0 0">À bientôt,<br>L\'équipe Lifestyle Planner</p>
  </div>
</div>
</body></html>';

    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: Lifestyle Planner <noreply@lifestyleplanner.fr>\r\n";
    @mail($email, 'Bienvenue sur Lifestyle Planner !', $welcomeHtml, $headers);

    // Retourner le JWT
    $token = jwt_encode(['user_id' => $userId]);
    json_response([
        'token' => $token,
        'user' => ['id' => $userId, 'email' => $email, 'first_name' => $firstName, 'last_name' => ''],
        'subscription' => ['status' => 'trial', 'trial_ends_at' => $trialEnd]
    ]);

} elseif ($action === 'login') {
    $input = get_input();
    $email = trim($input['email'] ?? '');
    $password = $input['password'] ?? '';

    // Rate limiting simple — max 10 tentatives par 15 min par IP
    $pdo = db();
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $rlKey = 'login_' . md5($ip);
    $stmt = $pdo->prepare("SELECT track_data FROM tracking WHERE user_id = 0 AND track_type = 'daily' AND track_key = ?");
    $stmt->execute([$rlKey]);
    $rlRow = $stmt->fetch();
    $rlData = $rlRow ? json_decode($rlRow['track_data'], true) : ['count' => 0, 'reset_at' => time() + 900];
    if (time() > ($rlData['reset_at'] ?? 0)) { $rlData = ['count' => 0, 'reset_at' => time() + 900]; }
    if (($rlData['count'] ?? 0) >= 10) { json_error('Trop de tentatives. Réessaie dans quelques minutes.', 429); }
    $rlData['count'] = ($rlData['count'] ?? 0) + 1;
    $rlJson = json_encode($rlData);
    $stmt = $pdo->prepare("INSERT INTO tracking (user_id, track_type, track_key, track_data) VALUES (0, 'daily', ?, ?) ON DUPLICATE KEY UPDATE track_data = ?");
    $stmt->execute([$rlKey, $rlJson, $rlJson]);
    $stmt = $pdo->prepare('SELECT id, email, password_hash, first_name, last_name FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        json_error('Email ou mot de passe incorrect', 401);
    }

    $token = jwt_encode(['user_id' => $user['id']]);

    // Récupérer le statut d'abonnement
    $stmt = $pdo->prepare('SELECT status, trial_ends_at, current_period_end FROM subscriptions WHERE user_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$user['id']]);
    $sub = $stmt->fetch();

    json_response([
        'token' => $token,
        'user' => ['id' => $user['id'], 'email' => $user['email'], 'first_name' => $user['first_name'] ?? '', 'last_name' => $user['last_name'] ?? ''],
        'subscription' => $sub ?: null
    ]);

} elseif ($action === 'me') {
    $userId = authenticate();
    $pdo = db();

    $stmt = $pdo->prepare('SELECT id, email, first_name, last_name, created_at FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user) json_error('Utilisateur introuvable', 404);

    $stmt = $pdo->prepare('SELECT status, trial_ends_at, current_period_end FROM subscriptions WHERE user_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$userId]);
    $sub = $stmt->fetch();

    // Vérifier si le trial est expiré
    if ($sub && $sub['status'] === 'trial' && strtotime($sub['trial_ends_at']) < time()) {
        $stmt = $pdo->prepare('UPDATE subscriptions SET status = ? WHERE user_id = ? AND status = ?');
        $stmt->execute(['expired', $userId, 'trial']);
        $sub['status'] = 'expired';
    }

    // Vérifier s'il y a un planner
    $stmt = $pdo->prepare('SELECT id FROM planners WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $hasPlanner = (bool) $stmt->fetch();

    json_response([
        'user' => $user,
        'subscription' => $sub ?: null,
        'has_planner' => $hasPlanner,
        'checkout_url' => ''
    ]);

} else {
    json_error('Action inconnue', 404);
}
