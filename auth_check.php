<?php
declare(strict_types=1);

require_once __DIR__ . '/error_handler.php';

const AUTH_SESSION_TIMEOUT = 7200;

function auth_start_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function auth_is_ajax_request(): bool
{
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $requestedWith = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';

    return strcasecmp($requestedWith, 'XMLHttpRequest') === 0
        || str_contains($accept, 'application/json')
        || isset($_GET['ajax'])
        || isset($_POST['ajax'])
        || str_contains($_SERVER['SCRIPT_NAME'] ?? '', 'get_')
        || str_contains($_SERVER['SCRIPT_NAME'] ?? '', '_data.php');
}

function auth_log(string $event, string $details = ''): void
{
    $dir = __DIR__ . '/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $user = $_SESSION['nom'] ?? '-';
    $role = $_SESSION['role'] ?? '-';
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'CLI';
    $uri = $_SERVER['REQUEST_URI'] ?? ($_SERVER['SCRIPT_NAME'] ?? 'CLI');
    $line = sprintf(
        "[%s] %s | user=%s | role=%s | ip=%s | uri=%s | %s%s",
        date('Y-m-d H:i:s'),
        $event,
        $user,
        $role,
        $ip,
        $uri,
        $details,
        PHP_EOL
    );

    @file_put_contents($dir . '/security.log', $line, FILE_APPEND | LOCK_EX);
}

function auth_fail(string $message, int $status = 401): void
{
    auth_log('access_denied', $message);

    if (auth_is_ajax_request()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $type = $status === 401 ? 'session_expired' : 'access_denied';
    header('Location: error.php?type=' . $type);
    exit();
}

function auth_require_login(): void
{
    auth_start_session();

    if (empty($_SESSION['id']) || empty($_SESSION['nom']) || empty($_SESSION['role'])) {
        auth_fail('Votre session a expiré. Veuillez vous reconnecter.', 401);
    }

    $lastActivity = (int) ($_SESSION['last_activity'] ?? 0);
    if ($lastActivity > 0 && (time() - $lastActivity) > AUTH_SESSION_TIMEOUT) {
        auth_log('session_expired');
        require_once __DIR__ . '/db.php';
        require_once __DIR__ . '/annees_scolaires.php';
        if (isset($conn) && $conn instanceof mysqli) {
            annee_scolaire_cleanup_session_reactivations($conn);
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        auth_fail('Votre session a expiré. Veuillez vous reconnecter.', 401);
    }

    $_SESSION['last_activity'] = time();

    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
}

function auth_require_role($roles): void
{
    auth_require_login();
    $roles = (array) $roles;
    $currentRole = $_SESSION['role'] ?? '';

    if (!in_array($currentRole, $roles, true)) {
        auth_fail('Accès non autorisé.', 403);
    }
}

function auth_safe_redirect(?string $redirect, string $fallback): string
{
    $redirect = trim((string) $redirect);
    if ($redirect === '' || preg_match('/^[a-z][a-z0-9+.-]*:/i', $redirect) || str_starts_with($redirect, '//')) {
        return $fallback;
    }

    return $redirect;
}
