<?php
declare(strict_types=1);

const APP_ENV = 'production';
const APP_LOG_DIR = __DIR__ . '/logs';

function app_is_development(): bool
{
    $env = getenv('APP_ENV');
    return ($env ?: APP_ENV) === 'development';
}

function app_is_ajax_request(): bool
{
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $requestedWith = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
    $script = $_SERVER['SCRIPT_NAME'] ?? '';

    return strcasecmp($requestedWith, 'XMLHttpRequest') === 0
        || str_contains($accept, 'application/json')
        || isset($_GET['ajax'])
        || isset($_POST['ajax'])
        || str_contains($script, 'get_')
        || str_contains($script, '_data.php');
}

function app_error_log(string $type, string $technicalMessage = ''): void
{
    if (!is_dir(APP_LOG_DIR)) {
        @mkdir(APP_LOG_DIR, 0775, true);
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }

    $line = sprintf(
        "[%s] type=%s | user=%s | role=%s | page=%s | ip=%s | message=%s%s",
        date('Y-m-d H:i:s'),
        $type,
        $_SESSION['nom'] ?? '-',
        $_SESSION['role'] ?? '-',
        $_SERVER['REQUEST_URI'] ?? ($_SERVER['SCRIPT_NAME'] ?? 'CLI'),
        $_SERVER['REMOTE_ADDR'] ?? 'CLI',
        str_replace(["\r", "\n"], ' ', $technicalMessage),
        PHP_EOL
    );

    @file_put_contents(APP_LOG_DIR . '/app_errors.log', $line, FILE_APPEND | LOCK_EX);
}

function app_error_redirect(string $type): void
{
    if (headers_sent()) {
        echo '<script>window.location.href="error.php?type=' . htmlspecialchars($type, ENT_QUOTES, 'UTF-8') . '";</script>';
        exit();
    }

    header('Location: error.php?type=' . rawurlencode($type));
    exit();
}

function app_error_response(string $type, string $technicalMessage = '', int $status = 500): void
{
    app_error_log($type, $technicalMessage);

    if (app_is_development()) {
        http_response_code($status);
        ini_set('display_errors', '1');
        error_reporting(E_ALL);
        echo '<pre>' . htmlspecialchars($technicalMessage, ENT_QUOTES, 'UTF-8') . '</pre>';
        exit();
    }

    if (app_is_ajax_request()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'success' => false,
            'message' => 'Une erreur est survenue. Veuillez réessayer ultérieurement.'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    app_error_redirect($type);
}

function app_register_error_handler(): void
{
    if (!app_is_development()) {
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
    }

    set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        throw new ErrorException($message, 0, $severity, $file, $line);
    });

    set_exception_handler(function (Throwable $exception): void {
        app_error_response(
            'system_error',
            $exception::class . ': ' . $exception->getMessage() . ' in ' . $exception->getFile() . ':' . $exception->getLine(),
            500
        );
    });

    register_shutdown_function(function (): void {
        $error = error_get_last();
        if (!$error) {
            return;
        }

        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
        if (in_array($error['type'], $fatalTypes, true)) {
            app_error_response(
                'system_error',
                $error['message'] . ' in ' . $error['file'] . ':' . $error['line'],
                500
            );
        }
    });
}

app_register_error_handler();
