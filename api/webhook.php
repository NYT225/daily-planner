<?php
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

$rawBody = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

// Vérifier la signature Stripe
if (!empty(STRIPE_WEBHOOK_SECRET)) {
    $elements = [];
    foreach (explode(',', $sigHeader) as $part) {
        [$key, $val] = explode('=', trim($part), 2);
        $elements[$key] = $val;
    }
    $timestamp = $elements['t'] ?? '';
    $signature = $elements['v1'] ?? '';
    $expectedSig = hash_hmac('sha256', "$timestamp.$rawBody", STRIPE_WEBHOOK_SECRET);

    if (!hash_equals($expectedSig, $signature)) {
        http_response_code(403);
        echo json_encode(['error' => 'Signature invalide']);
        exit;
    }

    // Vérifier que le timestamp n'est pas trop vieux (5 min)
    if (abs(time() - (int)$timestamp) > 300) {
        http_response_code(403);
        echo json_encode(['error' => 'Timestamp expiré']);
        exit;
    }
} else {
    http_response_code(503);
    echo json_encode(['error' => 'Webhook non configuré']);
    exit;
}

$event = json_decode($rawBody, true);
if (!$event || !isset($event['type'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Payload invalide']);
    exit;
}

$pdo = db();
$type = $event['type'];
$data = $event['data']['object'] ?? [];

switch ($type) {
    case 'checkout.session.completed':
        // Paiement réussi — activer l'abonnement
        $userId = (int)($data['metadata']['user_id'] ?? 0);
        $stripeSubId = $data['subscription'] ?? '';
        if ($userId && $stripeSubId) {
            // Récupérer les détails de la subscription
            $ch = curl_init("https://api.stripe.com/v1/subscriptions/$stripeSubId");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . STRIPE_SECRET_KEY]
            ]);
            $subData = json_decode(curl_exec($ch), true);
            curl_close($ch);

            $periodEnd = isset($subData['current_period_end']) ? date('Y-m-d H:i:s', $subData['current_period_end']) : null;

            $stmt = $pdo->prepare('UPDATE subscriptions SET status = ?, lemon_subscription_id = ?, current_period_end = ? WHERE user_id = ?');
            $stmt->execute(['active', $stripeSubId, $periodEnd, $userId]);
        }
        break;

    case 'invoice.payment_succeeded':
        // Renouvellement réussi
        $stripeSubId = $data['subscription'] ?? '';
        if ($stripeSubId) {
            $periodEnd = isset($data['lines']['data'][0]['period']['end']) ? date('Y-m-d H:i:s', $data['lines']['data'][0]['period']['end']) : null;
            $stmt = $pdo->prepare('UPDATE subscriptions SET status = ?, current_period_end = ? WHERE lemon_subscription_id = ?');
            $stmt->execute(['active', $periodEnd, $stripeSubId]);
        }
        break;

    case 'customer.subscription.updated':
        $stripeSubId = $data['id'] ?? '';
        $status = $data['status'] ?? '';
        $periodEnd = isset($data['current_period_end']) ? date('Y-m-d H:i:s', $data['current_period_end']) : null;

        $dbStatus = 'active';
        if (in_array($status, ['canceled', 'unpaid', 'past_due'])) $dbStatus = 'cancelled';
        if ($status === 'incomplete_expired') $dbStatus = 'expired';

        $stmt = $pdo->prepare('UPDATE subscriptions SET status = ?, current_period_end = ? WHERE lemon_subscription_id = ?');
        $stmt->execute([$dbStatus, $periodEnd, $stripeSubId]);
        break;

    case 'customer.subscription.deleted':
        $stripeSubId = $data['id'] ?? '';
        $stmt = $pdo->prepare('UPDATE subscriptions SET status = ? WHERE lemon_subscription_id = ?');
        $stmt->execute(['expired', $stripeSubId]);
        break;
}

http_response_code(200);
echo json_encode(['received' => true]);
