<?php
declare(strict_types=1);

require_once __DIR__ . '/auth_check.php';
auth_require_role('directeur');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/filiere_helper.php';

filiere_ensure_columns($conn);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: liste_filieres.php');
    exit();
}

$filiereId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
$action = $_POST['archive_action'] ?? '';

if ($filiereId <= 0 || !in_array($action, ['archive', 'reactivate'], true)) {
    header('Location: liste_filieres.php?message=archive_invalide');
    exit();
}

$archived = $action === 'archive' ? 1 : 0;
$stmt = $conn->prepare('UPDATE filieres SET is_archived = ? WHERE id = ?');
$stmt->bind_param('ii', $archived, $filiereId);
$stmt->execute();
$stmt->close();
$conn->close();

$message = $action === 'archive' ? 'archive_reussie' : 'reactivation_reussie';
header('Location: modifier_filiere.php?id=' . $filiereId . '&message=' . $message);
exit();
