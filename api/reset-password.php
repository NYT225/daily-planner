<?php
require_once __DIR__ . '/config.php';
cors();

$action = $_GET['action'] ?? '';

if ($action === 'request') {
    // Demander un reset de mot de passe
    $input = get_input();
    $email = trim($input['email'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_error('Email invalide');

    $pdo = db();

    // Vérifier si l'utilisateur existe (mais ne pas révéler s'il existe ou non)
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    // Toujours répondre succès (évite l'énumération d'emails)
    if (!$user) {
        json_response(['sent' => true]);
    }

    // Invalider les anciens tokens
    $stmt = $pdo->prepare('UPDATE password_resets SET used = 1 WHERE email = ? AND used = 0');
    $stmt->execute([$email]);

    // Générer un token
    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

    $tokenHash = hash('sha256', $token);
    $stmt = $pdo->prepare('INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)');
    $stmt->execute([$email, $tokenHash, $expiresAt]);

    // Construire le lien de reset
    $resetLink = SITE_URL . "/app.html?reset=$token";

    // Envoyer l'email
    $subject = 'Réinitialise ton mot de passe — Lifestyle Planner';
    $htmlBody = '
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;background:#FAF7F2;padding:40px 20px;margin:0">
<div style="max-width:480px;margin:0 auto;background:#ffffff;border-radius:16px;border:1px solid #E5DFD5;overflow:hidden">
  <div style="background:#C4654A;padding:24px;text-align:center">
    <span style="font-family:Georgia,serif;font-size:20px;font-weight:700;color:#FAF7F2">Lifestyle Planner</span>
  </div>
  <div style="padding:32px 28px">
    <h1 style="font-size:22px;font-weight:700;color:#1A1A1A;margin:0 0 12px">Réinitialisation du mot de passe</h1>
    <p style="font-size:15px;color:#5C5650;line-height:1.6;margin:0 0 24px">Tu as demandé à réinitialiser ton mot de passe. Clique sur le bouton ci-dessous pour en choisir un nouveau :</p>
    <a href="' . $resetLink . '" style="display:block;background:#C4654A;color:#ffffff;text-decoration:none;text-align:center;padding:14px 24px;border-radius:10px;font-size:15px;font-weight:600">Réinitialiser mon mot de passe</a>
    <p style="font-size:13px;color:#9B918A;line-height:1.6;margin:24px 0 0">Ce lien expire dans 1 heure. Si tu n\'as pas fait cette demande, ignore cet email.</p>
  </div>
</div>
</body>
</html>';

    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: Lifestyle Planner <noreply@lifestyleplanner.fr>\r\n";
    $headers .= "Reply-To: contact@lifestyleplanner.fr\r\n";

    mail($email, $subject, $htmlBody, $headers);

    json_response(['sent' => true]);

} elseif ($action === 'verify') {
    // Vérifier qu'un token est valide
    $token = $_GET['token'] ?? '';
    if (strlen($token) !== 64) json_error('Token invalide');

    $pdo = db();
    $tokenHash = hash('sha256', $token);
    $stmt = $pdo->prepare('SELECT email, expires_at FROM password_resets WHERE token = ? AND used = 0');
    $stmt->execute([$tokenHash]);
    $reset = $stmt->fetch();

    if (!$reset) json_error('Lien invalide ou expiré', 400);
    if (strtotime($reset['expires_at']) < time()) json_error('Lien expiré', 400);

    json_response(['valid' => true, 'email' => $reset['email']]);

} elseif ($action === 'reset') {
    // Réinitialiser le mot de passe
    $input = get_input();
    $token = $input['token'] ?? '';
    $password = $input['password'] ?? '';

    if (strlen($token) !== 64) json_error('Token invalide');
    if (strlen($password) < 8) json_error('Mot de passe : 8 caractères minimum');

    $pdo = db();
    $tokenHash = hash('sha256', $token);
    $stmt = $pdo->prepare('SELECT email, expires_at FROM password_resets WHERE token = ? AND used = 0');
    $stmt->execute([$tokenHash]);
    $reset = $stmt->fetch();

    if (!$reset) json_error('Lien invalide ou expiré', 400);
    if (strtotime($reset['expires_at']) < time()) json_error('Lien expiré', 400);

    // Mettre à jour le mot de passe
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE email = ?');
    $stmt->execute([$hash, $reset['email']]);

    // Marquer le token comme utilisé
    $stmt = $pdo->prepare('UPDATE password_resets SET used = 1 WHERE token = ?');
    $stmt->execute([$tokenHash]);

    json_response(['reset' => true]);

} else {
    json_error('Action inconnue', 404);
}
