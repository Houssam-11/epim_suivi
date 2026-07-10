<?php
declare(strict_types=1);

ob_start();

require_once __DIR__ . '/auth_check.php';
auth_require_role('directeur');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/ExcelPlanningExporter.php';

function export_planning_excel_error(string $message, int $status = 400): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success' => false,
        'message' => $message,
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

$anneeScolaireId = filter_input(INPUT_GET, 'annee_scolaire_id', FILTER_VALIDATE_INT) ?: 0;
$filiereId = filter_input(INPUT_GET, 'filiere_id', FILTER_VALIDATE_INT) ?: 0;

if ($anneeScolaireId <= 0 || $filiereId <= 0) {
    export_planning_excel_error('Veuillez sélectionner une année scolaire et une filière avant l’export.');
}

try {
    $exporter = new ExcelPlanningExporter($conn);
    $export = $exporter->export($anneeScolaireId, $filiereId, 0);

    if (!is_file($export['path'])) {
        export_planning_excel_error('Le fichier Excel n’a pas pu être généré.', 500);
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . basename($export['filename']) . '"');
    header('Content-Length: ' . filesize($export['path']));
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    readfile($export['path']);
    exit();
} catch (Throwable $e) {
    export_planning_excel_error($e->getMessage(), 500);
}
