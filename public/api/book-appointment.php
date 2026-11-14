<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;
use BusherianBiasharas\Services\AntiSpamGate;
use BusherianBiasharas\Services\LocationEngine;
use BusherianBiasharas\Services\Mailer;
use BusherianBiasharas\Services\OwnerAuth;

bbiz_send_security_headers(true);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    if (($_GET['action'] ?? '') !== 'tokens') {
        http_response_code(405);
        echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed']);
        exit;
    }
    try {
        $pdo = Database::getConnection();
        $clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        $tokBudget = AntiSpamGate::checkAuthAttemptAllowed($pdo, $clientIp, 'booking_tokens_get', '', 60, 400, 60, 400);
        if (!$tokBudget['allowed']) {
            http_response_code(429);
            header('Retry-After: 60');
            echo json_encode(['status' => 'error', 'message' => 'Too many booking form requests. Please wait a moment and try again.']);
            exit;
        }
        OwnerAuth::startSecureSession();
        $tokens = AntiSpamGate::generateFormTokens();
        echo json_encode([
            'status' => 'ok',
            'tokens' => $tokens,
        ]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Unable to initialize booking form security tokens.']);
    }
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed']);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$post = (json_decode($raw, true) ?: $_POST);
if (!is_array($post)) {
    $post = [];
}

OwnerAuth::startSecureSession();
$csrf = trim((string)($post['csrf_token'] ?? ''));
if (!AntiSpamGate::verifyCsrfToken($csrf)) {
    http_response_code(403);
    echo json_encode([
        'status' => 'error',
        'message' => 'Your booking form session expired. Please close and reopen the booking drawer and try again.',
        'next_tokens' => AntiSpamGate::generateFormTokens(),
    ]);
    exit;
}

try {
    $pdo = Database::getConnection();
    $clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

    $businessId = (int)($post['business_id'] ?? 0);
    $requesterName = trim((string)($post['requester_name'] ?? ''));
    $requesterEmail = strtolower(trim((string)($post['requester_email'] ?? '')));
    $requesterPhone = trim((string)($post['requester_phone'] ?? ''));
    $preferredDate = trim((string)($post['preferred_date'] ?? ''));
    $preferredTime = trim((string)($post['preferred_slot'] ?? $post['preferred_time'] ?? ''));
    $topic = trim((string)($post['topic'] ?? ''));
    $age18 = !empty($post['age_attestation_18']) && (string)$post['age_attestation_18'] !== '0';
    $rawPrivAck = $post['privacy_acknowledged'] ?? $post['booking_privacy_ack'] ?? null;
    $privAck = !empty($rawPrivAck) && (string)$rawPrivAck !== '0';

    if ($businessId <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid business selection.', 'next_tokens' => AntiSpamGate::generateFormTokens()]);
        exit;
    }

    // Run all 7 AntiSpamGate layers before business/mail handling
    $spamResult = AntiSpamGate::verifyBookingInquiry($pdo, $post, $clientIp);
    if (!$spamResult['passed']) {
        if ($spamResult['silent_drop']) {
            echo json_encode([
                'status' => 'ok',
                'message' => 'Your appointment request has been received and forwarded to the business.',
                'next_tokens' => AntiSpamGate::generateFormTokens(),
            ]);
            exit;
        }
        $friendly = match ($spamResult['reason']) {
            'layer2_form_expired', 'layer2_nonce_replayed', 'layer2_invalid_signature' => 'Your booking form session expired or was already submitted. Please try again with the refreshed form.',
            'layer4_excessive_urls_in_message' => 'Please include at most one website link in your consultation note.',
            'layer4_all_caps_shouting' => 'Please write your name and consultation note in normal sentence case rather than ALL CAPS.',
            default => 'Unable to verify booking request. Please review your entries and try again.',
        };
        http_response_code(422);
        echo json_encode([
            'status' => 'error',
            'message' => $friendly,
            'next_tokens' => AntiSpamGate::generateFormTokens(),
        ]);
        exit;
    }

    if (!$age18 || !$privAck) {
        http_response_code(422);
        echo json_encode([
            'status' => 'error',
            'message' => 'Please confirm that you are 18+ and agree to share your booking details with this business.',
            'next_tokens' => AntiSpamGate::generateFormTokens(),
        ]);
        exit;
    }

    if ($requesterName === '' || mb_strlen($requesterName) < 2 || mb_strlen($requesterName) > 120) {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => 'Please enter your full name (2 to 120 characters).', 'next_tokens' => AntiSpamGate::generateFormTokens()]);
        exit;
    }
    if ($requesterEmail === '' || mb_strlen($requesterEmail) > 190 || !filter_var($requesterEmail, FILTER_VALIDATE_EMAIL)) {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => 'Please enter a valid email address so the business can reply.', 'next_tokens' => AntiSpamGate::generateFormTokens()]);
        exit;
    }
    if ($requesterPhone !== '' && mb_strlen($requesterPhone) > 40) {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => 'Phone number must be 40 characters or fewer.', 'next_tokens' => AntiSpamGate::generateFormTokens()]);
        exit;
    }
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $preferredDate, $dateParts)) {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => 'Please choose a valid preferred date (YYYY-MM-DD).', 'next_tokens' => AntiSpamGate::generateFormTokens()]);
        exit;
    }
    if (!checkdate((int)$dateParts[2], (int)$dateParts[3], (int)$dateParts[1])) {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => 'Please choose a valid calendar date.', 'next_tokens' => AntiSpamGate::generateFormTokens()]);
        exit;
    }
    if ($preferredTime === '' || mb_strlen($preferredTime) > 80) {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => 'Please select a preferred time slot.', 'next_tokens' => AntiSpamGate::generateFormTokens()]);
        exit;
    }
    if ($topic === '' || mb_strlen($topic) < 4 || mb_strlen($topic) > 500) {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => 'Please enter a brief consultation topic or note (4 to 500 characters).', 'next_tokens' => AntiSpamGate::generateFormTokens()]);
        exit;
    }

    $bizStmt = $pdo->prepare("
        SELECT id, name, email_public, owner_email, booking_enabled, booking_url, country, city_county
        FROM businesses
        WHERE id = :id AND status = 'approved'
        LIMIT 1
    ");
    $bizStmt->execute([':id' => $businessId]);
    $biz = $bizStmt->fetch();
    if (!$biz || ((int)$biz['booking_enabled'] !== 1 && trim((string)($biz['booking_url'] ?? '')) === '')) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Appointment booking is not currently enabled for this business.', 'next_tokens' => AntiSpamGate::generateFormTokens()]);
        exit;
    }

    $bizTz = LocationEngine::getTimezoneForCountry($biz['country'] ?? null, $biz['city_county'] ?? null);
    $bizDt = new \DateTimeImmutable('now', new \DateTimeZone($bizTz));
    $bizToday = $bizDt->format('Y-m-d');
    if ($preferredDate < $bizToday) {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => 'Preferred appointment date cannot be in the past.', 'next_tokens' => AntiSpamGate::generateFormTokens()]);
        exit;
    }

    $pubEm = strtolower(trim((string)($biz['email_public'] ?? '')));
    $ownEm = strtolower(trim((string)($biz['owner_email'] ?? '')));
    $recipientEmail = '';
    if ($pubEm !== '' && filter_var($pubEm, FILTER_VALIDATE_EMAIL)) {
        $recipientEmail = $pubEm;
    } elseif ($ownEm !== '' && filter_var($ownEm, FILTER_VALIDATE_EMAIL)) {
        $recipientEmail = $ownEm;
    }
    if ($recipientEmail === '') {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => 'Email booking is unavailable for this listing — please use WhatsApp or Call instead.', 'next_tokens' => AntiSpamGate::generateFormTokens()]);
        exit;
    }

    $mailQuota = AntiSpamGate::checkAndRecordOutboundMailQuota($pdo, $clientIp, $recipientEmail, false);
    if ($mailQuota['allowed']) {
        Mailer::sendBookingInquiry($pdo, $biz, $recipientEmail, [
            'requester_name' => $requesterName,
            'requester_email' => $requesterEmail,
            'requester_phone' => $requesterPhone,
            'preferred_date' => $preferredDate,
            'preferred_time' => $preferredTime,
            'topic' => $topic,
            'timezone' => $bizTz,
            'timezone_abbr' => $bizDt->format('T'),
        ]);
    }

    $viewerCtx = \BusherianBiasharas\Services\EngagementRanker::resolveViewerWithoutStartingAnonymousSession($pdo);
    \BusherianBiasharas\Services\EngagementRanker::recordCheckout(
        $pdo,
        $businessId,
        'booking',
        $clientIp,
        substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        (string)($post['js_interaction_token'] ?? ''),
        $viewerCtx['owner_id'],
        $viewerCtx['is_admin']
    );

    echo json_encode([
        'status' => 'ok',
        'message' => 'Your appointment request has been sent to ' . (string)$biz['name'] . '! They can reply directly to ' . $requesterEmail . '.',
        'next_tokens' => AntiSpamGate::generateFormTokens(),
    ]);
} catch (\Throwable $e) {
    error_log('[api/book-appointment.php] Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Temporary server error while sending booking request. Please try WhatsApp or Call.']);
}
