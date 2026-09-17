<?php
require_once __DIR__.'/includes/config.php';
header('Content-Type: application/json');

$id = (int)($_GET['id'] ?? 0);
$email = trim($_GET['email'] ?? '');

if (!$id || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing or invalid id/email.']);
    exit;
}

$payload = application_status_payload(db(), $id, $email);
if (!$payload) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Not found.']);
    exit;
}

echo json_encode(['ok' => true] + $payload);
