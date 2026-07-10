<?php
require_once __DIR__ . '/auth_check.php';
auth_require_role('directeur');
require_once 'db.php';

header('Content-Type: application/json; charset=utf-8');

$unite_id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (!$unite_id) {
    echo json_encode(['success' => false, 'message' => "Unité invalide."], JSON_UNESCAPED_UNICODE);
    exit();
}

try {
    $conn->begin_transaction();

    $check = $conn->prepare(
        "SELECT uf.id, COALESCE(f.is_archived, 0) AS filiere_is_archived
         FROM unites_de_formation uf
         LEFT JOIN filieres f ON f.id = uf.filiere_id
         WHERE uf.id = ?
         FOR UPDATE"
    );
    $check->bind_param("i", $unite_id);
    $check->execute();
    $unite = $check->get_result()->fetch_assoc();
    $check->close();

    if (!$unite) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => "Unité introuvable."], JSON_UNESCAPED_UNICODE);
        exit();
    }

    if ((int) $unite['filiere_is_archived'] === 1) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => "Cette filière est archivée. Action désactivée."], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $countSequences = $conn->prepare("SELECT COUNT(*) AS total FROM sequences_pedagogiques WHERE unite_id = ?");
    $countSequences->bind_param("i", $unite_id);
    $countSequences->execute();
    $totalSequences = (int) ($countSequences->get_result()->fetch_assoc()['total'] ?? 0);
    $countSequences->close();

    if ($totalSequences > 0) {
        $conn->rollback();
        echo json_encode([
            'success' => false,
            'message' => "Cette unité contient déjà des séquences pédagogiques. Elle ne peut pas être supprimée. Vous pouvez l'archiver afin de conserver l'historique."
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $delete = $conn->prepare("DELETE FROM unites_de_formation WHERE id = ?");
    $delete->bind_param("i", $unite_id);
    $delete->execute();
    $delete->close();

    $conn->commit();
    echo json_encode(['success' => true, 'message' => "Unité supprimée définitivement."], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    try {
        $conn->rollback();
    } catch (Throwable $rollbackException) {
        error_log('Delete unite rollback error: ' . $rollbackException->getMessage());
    }
    error_log('Delete unite error: ' . $exception->getMessage());
    echo json_encode(['success' => false, 'message' => "Erreur lors de la suppression de l'unité."], JSON_UNESCAPED_UNICODE);
}
