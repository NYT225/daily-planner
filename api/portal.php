<?php
require_once __DIR__ . '/config.php';
cors();

$userId = authenticate();
$pdo = db();

// Récupérer le Stripe subscription ID
$stmt = $pdo->prepare('SELECT lemon_subscription_id FROM subscriptions WHERE user_id = ? AND status = ? ORDER BY id DESC LIMIT 1');
$stmt->execute([$userId, 'active']);
$sub = $stmt->fetch();

if (!$sub || !$sub['lemon_subscription_id']) {
    json_error('Aucun abonnement actif', 404);
}

// Récupérer le customer ID depuis la subscription
$ch = curl_init('https://api.stripe.com/v1/subscriptions/' . $sub['lemon_subscription_id']);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . STRIPE_SECRET_KEY]
]);
$subData = json_decode(curl_exec($ch), true);
curl_close($ch);

$customerId = $subData['customer'] ?? '';
if (!$customerId) json_error('Client Stripe introuvable', 404);

// Créer une session de portail
$baseUrl = SITE_URL;

$ch = curl_init('https://api.stripe.com/v1/billing_portal/sessions');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'customer' => $customerId,
        'return_url' => "$baseUrl/app.html"
    ]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . STRIPE_SECRET_KEY]
]);
$result = json_decode(curl_exec($ch), true);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200 || !isset($result['url'])) {
    json_error($result['error']['message'] ?? 'Erreur Stripe', 502);
}

json_response(['portal_url' => $result['url']]);
