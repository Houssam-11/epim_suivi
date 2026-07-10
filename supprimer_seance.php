<?php
require_once __DIR__ . '/auth_check.php';
auth_require_role('formateur');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/annees_scolaires.php';

$wantsJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
    || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';

function delete_seance_response(array $payload, int $status = 200): void
{
    global $wantsJson;

    http_response_code($status);
    if ($wantsJson) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit();
    }

    if (!empty($payload['redirect'])) {
        header('Location: ' . $payload['redirect']);
        exit();
    }

    exit($payload['message'] ?? 'Action impossible.');
}

$session_user_id = (int) ($_SESSION['id'] ?? 0);
$formateur_id = $session_user_id;
$stmt_formateur = $conn->prepare("SELECT id FROM formateurs WHERE utilisateur_id = ? OR id = ? LIMIT 1");
if ($stmt_formateur) {
    $stmt_formateur->bind_param('ii', $session_user_id, $session_user_id);
    $stmt_formateur->execute();
    $stmt_formateur->bind_result($resolved_formateur_id);
    if ($stmt_formateur->fetch()) {
        $formateur_id = (int) $resolved_formateur_id;
    }
    $stmt_formateur->close();
}

$seance_id = filter_input(INPUT_POST, 'seance_id', FILTER_VALIDATE_INT)
    ?: filter_input(INPUT_GET, 'seance_id', FILTER_VALIDATE_INT);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    delete_seance_response([
        'success' => false,
        'message' => 'Méthode non autorisée pour supprimer une séance.',
        'redirect' => 'gestion_seances.php',
    ], 405);
}

if (!$seance_id || $formateur_id <= 0) {
    delete_seance_response([
        'success' => false,
        'message' => 'Séance invalide.',
        'redirect' => 'gestion_seances.php',
    ], 400);
}

$sql = "SELECT sp.id, sp.sequence_id, sp.annee_scolaire_id, sq.unite_id, uf.intitule AS unite_nom
        FROM seances_pedagogiques sp
        INNER JOIN sequences_pedagogiques sq ON sp.sequence_id = sq.id
        INNER JOIN unites_de_formation uf ON sq.unite_id = uf.id
        WHERE sp.id = ? AND uf.formateur_id = ?
        LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param('ii', $seance_id, $formateur_id);
$stmt->execute();
$seance = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$seance) {
    delete_seance_response([
        'success' => false,
        'message' => 'Séance introuvable ou non autorisée.',
        'redirect' => 'gestion_seances.php',
    ], 404);
}

$unite_id = (int) $seance['unite_id'];
$unite_nom = (string) $seance['unite_nom'];
$sequence_id = (int) $seance['sequence_id'];
$annee_scolaire_id = (int) $seance['annee_scolaire_id'];
$redirect = 'gestion_seances.php?unite_id=' . $unite_id
    . '&unite_nom=' . urlencode($unite_nom)
    . '&annee_scolaire_id=' . $annee_scolaire_id;

if (!annee_scolaire_is_editable_for_current_user($conn, $annee_scolaire_id)) {
    delete_seance_response([
        'success' => false,
        'message' => 'Cette année scolaire est archivée. Suppression non autorisée.',
        'redirect' => $redirect,
    ], 403);
}

try {
    $conn->begin_transaction();

    $stmt = $conn->prepare('DELETE FROM recommendation_usage_events WHERE seance_id = ?');
    $stmt->bind_param('i', $seance_id);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare('DELETE FROM suivi_pedagogique WHERE seance_id = ?');
    $stmt->bind_param('i', $seance_id);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare('DELETE FROM seances_pedagogiques WHERE id = ?');
    $stmt->bind_param('i', $seance_id);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare(
        'SELECT sp.id, sp.heures_reelles, sp.heures_officielles
         FROM seances_pedagogiques sp
         WHERE sp.sequence_id = ? AND sp.annee_scolaire_id = ?
         ORDER BY sp.date_seance ASC, sp.id ASC'
    );
    $stmt->bind_param('ii', $sequence_id, $annee_scolaire_id);
    $stmt->execute();
    $remaining = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $heures_cumulees = 0.0;
    $stmt_update = $conn->prepare('UPDATE suivi_pedagogique SET heures_cumulees = ?, taux_realisation = ? WHERE seance_id = ?');
    foreach ($remaining as $row) {
        $heures_cumulees += (float) $row['heures_reelles'];
        $heures_officielles = (float) $row['heures_officielles'];
        $taux_realisation = $heures_officielles > 0 ? ($heures_cumulees / $heures_officielles) * 100 : 0;
        $remaining_seance_id = (int) $row['id'];
        $stmt_update->bind_param('ddi', $heures_cumulees, $taux_realisation, $remaining_seance_id);
        $stmt_update->execute();
    }
    $stmt_update->close();

    $conn->commit();

    delete_seance_response([
        'success' => true,
        'message' => 'Séance supprimée avec succès.',
        'redirect' => $redirect,
    ]);
} catch (Throwable $e) {
    $conn->rollback();
    error_log('Echec suppression seance : ' . $e->getMessage());

    delete_seance_response([
        'success' => false,
        'message' => 'Erreur lors de la suppression de la séance.',
        'redirect' => $redirect,
    ], 500);
}
