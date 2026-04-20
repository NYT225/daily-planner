<?php
require_once __DIR__ . '/config.php';
cors();

$userId = authenticate();
$pdo = db();

// Récupérer l'email de l'utilisateur
$stmt = $pdo->prepare('SELECT email FROM users WHERE id = ?');
$stmt->execute([$userId]);
$user = $stmt->fetch();
if (!$user) json_error('Utilisateur introuvable', 404);

// Créer une session Stripe Checkout
$baseUrl = SITE_URL;

$payload = [
    'mode' => 'subscription',
    'customer_email' => $user['email'],
    'line_items' => [
        ['price' => STRIPE_PRICE_ID, 'quantity' => 1]
    ],
    'success_url' => "$baseUrl/app.html?payment=success",
    'cancel_url' => "$baseUrl/app.html?payment=cancel",
    'metadata' => ['user_id' => $userId],
    'subscription_data' => [
        'metadata' => ['user_id' => $userId]
    ]
];

$ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query($payload),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . STRIPE_SECRET_KEY,
    ],
    CURLOPT_TIMEOUT => 30
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$result = json_decode($response, true);

if ($httpCode !== 200 || !isset($result['url'])) {
    json_error($result['error']['message'] ?? 'Erreur Stripe', 502);
}

json_response(['checkout_url' => $result['url']]);
