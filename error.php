<?php
require_once __DIR__ . '/error_handler.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$type = $_GET['type'] ?? 'system_error';
$errors = [
    '404' => [
        'title' => 'Page introuvable',
        'message' => "Le contenu demandé n'existe pas ou a été déplacé.",
        'icon' => 'fa-search',
        'status' => 404,
    ],
    'access_denied' => [
        'title' => 'Accès refusé',
        'message' => "Vous ne disposez pas des autorisations nécessaires pour accéder à cette page.",
        'icon' => 'fa-lock',
        'status' => 403,
    ],
    'session_expired' => [
        'title' => 'Session expirée',
        'message' => 'Votre session a expiré. Veuillez vous reconnecter.',
        'icon' => 'fa-hourglass-end',
        'status' => 401,
    ],
    'system_error' => [
        'title' => 'Erreur système',
        'message' => 'Une erreur inattendue est survenue. Veuillez réessayer ultérieurement.',
        'icon' => 'fa-exclamation-triangle',
        'status' => 500,
    ],
];

$current = $errors[$type] ?? $errors['system_error'];
http_response_code($current['status']);

$role = $_SESSION['role'] ?? '';
$dashboard = $role === 'directeur'
    ? 'tableau_bord_directeur.php'
    : ($role === 'formateur' ? 'tableau_bord_formateur.php' : 'index.php');

app_error_log($type, 'error_page_displayed');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($current['title'], ENT_QUOTES, 'UTF-8'); ?> - EPIM</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <link rel="stylesheet" href="assets/css/theme.css">
    <style>
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f4f7fb;
            padding: 24px;
        }

        .error-card {
            width: min(560px, 100%);
            text-align: center;
        }

        .error-icon {
            width: 72px;
            height: 72px;
            margin: 0 auto 18px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(0, 75, 156, .1);
            color: var(--epim-blue, #004b9c);
            font-size: 1.8rem;
        }

        .error-actions {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 10px;
            margin-top: 24px;
        }
    </style>
</head>
<body>
    <main class="epim-card no-hover p-4 error-card">
        <div class="error-icon">
            <i class="fas <?php echo htmlspecialchars($current['icon'], ENT_QUOTES, 'UTF-8'); ?>"></i>
        </div>
        <h1 class="mb-3"><?php echo htmlspecialchars($current['title'], ENT_QUOTES, 'UTF-8'); ?></h1>
        <p class="text-muted mb-0"><?php echo htmlspecialchars($current['message'], ENT_QUOTES, 'UTF-8'); ?></p>

        <div class="error-actions">
            <?php if ($type !== 'session_expired'): ?>
                <button type="button" class="btn btn-outline-epim" onclick="history.back()">
                    <i class="fas fa-arrow-left mr-1"></i> Retour
                </button>
            <?php endif; ?>

            <?php if ($type === 'session_expired' || $role === ''): ?>
                <a href="index.php" class="btn btn-epim-primary">
                    <i class="fas fa-sign-in-alt mr-1"></i> Connexion
                </a>
            <?php else: ?>
                <a href="<?php echo htmlspecialchars($dashboard, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-epim-primary">
                    <i class="fas fa-home mr-1"></i> Tableau de bord
                </a>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>
