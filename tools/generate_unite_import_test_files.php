<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function write_unite_import_test_xlsx(string $path, array $headers, array $rows): void
{
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray($headers, null, 'A1');
    $sheet->fromArray($rows, null, 'A2');
    $sheet->getStyle('A1:' . chr(64 + count($headers)) . '1')->getFont()->setBold(true);
    foreach (range(1, count($headers)) as $columnIndex) {
        $sheet->getColumnDimensionByColumn($columnIndex)->setAutoSize(true);
    }

    $writer = new Xlsx($spreadsheet);
    $writer->save($path);
    $spreadsheet->disconnectWorksheets();
}

$outputDir = __DIR__ . '/../data/test_imports';
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0775, true);
}

$formateur = 'John Doetest';
$existing = 'Métier et formation';

$headers = [
    "Nom de l'unité",
    'Formateur',
    'Objectif général',
    'Heures par séance par défaut',
    'Masse horaire',
    'Colonne supplémentaire ignorée',
];

write_unite_import_test_xlsx($outputDir . '/unites_import_valide.xlsx', $headers, [
    ['Unité test import A', $formateur, 'Objectif général de test A', '4', '30', 'Cette colonne doit être ignorée'],
    ['Unité test import B', $formateur, 'Objectif général de test B', '3.5', '24', 'Cette colonne doit être ignorée'],
]);

write_unite_import_test_xlsx($outputDir . '/unites_import_erreurs_doublons.xlsx', $headers, [
    [$existing, $formateur, 'Objectif déjà existant', '4', '30', 'Doublon en base'],
    ['Unité erreur formateur', 'Formateur Inexistant', 'Objectif test', '4', '30', 'Erreur formateur'],
    ['Unité erreur heures', $formateur, 'Objectif test', 'abc', '30', 'Erreur heures'],
    ['', $formateur, 'Objectif sans nom', '4', '30', 'Erreur nom obligatoire'],
]);

echo "Fichiers générés dans {$outputDir}" . PHP_EOL;
