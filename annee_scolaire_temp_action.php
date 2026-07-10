<?php
declare(strict_types=1);

require_once __DIR__ . '/auth_check.php';
auth_require_role('formateur');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/annees_scolaires.php';

header('Content-Type: application/json; charset=UTF-8');

$anneeId = filter_input(INPUT_POST, 'annee_scolaire_id', FILTER_VALIDATE_INT) ?: 0;
$action = $_POST['action'] ?? '';

if ($anneeId <= 0 || !in_array($action, ['reactivate_temp', 'archive_temp'], true)) {
    echo json_encode(['success' => false, 'message' => 'Action invalide.'], JSON_UNESCAPED_UNICODE);
    exit();
}

if (annee_scolaire_status($conn, $anneeId) !== 'archivee') {
    echo json_encode(['success' => false, 'message' => "Cette année scolaire n'est pas archivée."], JSON_UNESCAPED_UNICODE);
    exit();
}

annee_scolaire_set_temp_reactivated($anneeId, $action === 'reactivate_temp');
setCurrentWorkingAcademicYear($conn, $anneeId);

echo json_encode([
    'success' => true,
    'message' => $action === 'reactivate_temp'
        ? 'Année scolaire réactivée temporairement.'
        : 'Année scolaire réarchivée pour votre session.',
], JSON_UNESCAPED_UNICODE);
