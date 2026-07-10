<?php
declare(strict_types=1);

require_once __DIR__ . '/auth_check.php';
auth_require_role('directeur');
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

$unite_id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$action = $_POST['archive_action'] ?? 'archive';

if (!$unite_id || !in_array($action, ['archive', 'reactivate'], true)) {
    echo json_encode(['success' => false, 'message' => 'Action invalide.'], JSON_UNESCAPED_UNICODE);
    exit();
}

try {
    $conn->begin_transaction();

    $check = $conn->prepare(
        "SELECT uf.id, COALESCE(uf.is_archived, 0) AS is_archived, COALESCE(f.is_archived, 0) AS filiere_is_archived
         FROM unites_de_formation uf
         LEFT JOIN filieres f ON f.id = uf.filiere_id
         WHERE uf.id = ?
         FOR UPDATE"
    );
    $check->bind_param('i', $unite_id);
    $check->execute();
    $unite = $check->get_result()->fetch_assoc();
    $check->close();

    if (!$unite) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Unité introuvable.'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    if ((int) $unite['filiere_is_archived'] === 1) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Cette filière est archivée. Action désactivée.'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    if ($action === 'archive' && (int) $unite['is_archived'] === 1) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Cette unité est déjà archivée.'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $newState = $action === 'archive' ? 1 : 0;
    $archivedAtSql = $action === 'archive' ? 'NOW()' : 'NULL';

    $archiveUnite = $conn->prepare("UPDATE unites_de_formation SET is_archived = ?, archived_at = $archivedAtSql WHERE id = ?");
    $archiveUnite->bind_param('ii', $newState, $unite_id);
    $archiveUnite->execute();
    $archiveUnite->close();

    $archiveSequences = $conn->prepare("UPDATE sequences_pedagogiques SET is_archived = ?, archived_at = $archivedAtSql WHERE unite_id = ?");
    $archiveSequences->bind_param('ii', $newState, $unite_id);
    $archiveSequences->execute();
    $archiveSequences->close();

    $archiveObjectifs = $conn->prepare(
        "UPDATE objectif_seance
         SET is_archived = ?, archived_at = $archivedAtSql
         WHERE id_sequence IN (
             SELECT id FROM sequences_pedagogiques WHERE unite_id = ?
         )"
    );
    $archiveObjectifs->bind_param('ii', $newState, $unite_id);
    $archiveObjectifs->execute();
    $archiveObjectifs->close();

    $conn->commit();
    echo json_encode([
        'success' => true,
        'message' => $action === 'archive' ? 'Unité archivée avec succès.' : 'Unité réactivée avec succès.',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    try {
        $conn->rollback();
    } catch (Throwable $rollbackException) {
        error_log('Archive unite rollback error: ' . $rollbackException->getMessage());
    }
    error_log('Archive unite error: ' . $exception->getMessage());
    echo json_encode(['success' => false, 'message' => "Erreur lors du changement d'état de l'unité."], JSON_UNESCAPED_UNICODE);
}
