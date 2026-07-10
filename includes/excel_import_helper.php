<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function excel_import_normalize_header(string $value): string
{
    $value = trim(mb_strtolower($value, 'UTF-8'));
    $value = str_replace(["\xc2\xa0", "\u{00A0}"], ' ', $value);
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    $value = str_replace(["'", '’', '`'], '', $value);
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

    return $ascii !== false ? $ascii : $value;
}

function excel_import_normalize_key(string $value): string
{
    return excel_import_normalize_header($value);
}

function excel_import_cell_to_string($value): string
{
    if ($value === null) {
        return '';
    }

    return trim((string) $value);
}

function excel_import_load_rows(string $tmpPath, array $requiredColumns, array $optionalColumns = []): array
{
    set_error_handler(static function (int $severity): bool {
        return in_array($severity, [E_WARNING, E_NOTICE, E_DEPRECATED], true);
    });

    try {
        $spreadsheet = IOFactory::load($tmpPath);
    } finally {
        restore_error_handler();
    }

    $worksheet = $spreadsheet->getActiveSheet();
    $rows = $worksheet->toArray(null, true, true, true);
    $spreadsheet->disconnectWorksheets();

    if (!$rows) {
        return ['headers' => [], 'records' => [], 'errors' => ['Le fichier est vide.']];
    }

    $headerRow = array_shift($rows);
    $headers = [];
    foreach ($headerRow as $column => $label) {
        $normalized = excel_import_normalize_header(excel_import_cell_to_string($label));
        if ($normalized !== '') {
            $headers[$normalized] = $column;
        }
    }

    $errors = [];
    $columnMap = [];
    foreach ($requiredColumns as $key => $label) {
        $normalized = excel_import_normalize_header($label);
        if (!isset($headers[$normalized])) {
            $errors[] = "Colonne obligatoire manquante : {$label}";
            continue;
        }
        $columnMap[$key] = $headers[$normalized];
    }

    foreach ($optionalColumns as $key => $label) {
        $normalized = excel_import_normalize_header($label);
        if (isset($headers[$normalized])) {
            $columnMap[$key] = $headers[$normalized];
        }
    }

    $records = [];
    if (!$errors) {
        foreach ($rows as $index => $row) {
            $record = [];
            foreach ($columnMap as $key => $column) {
                $record[$key] = excel_import_cell_to_string($row[$column] ?? null);
            }

            foreach ($optionalColumns as $key => $label) {
                if (!array_key_exists($key, $record)) {
                    $record[$key] = '';
                }
            }

            if (implode('', $record) === '') {
                continue;
            }

            $record['_row'] = $index + 2;
            $records[] = $record;
        }
    }

    return ['headers' => $headers, 'records' => $records, 'errors' => $errors];
}

function excel_import_output_template(string $filename, array $headers): void
{
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray($headers, null, 'A1');
    $sheet->getStyle('A1:' . chr(64 + count($headers)) . '1')->getFont()->setBold(true);
    foreach (range(1, count($headers)) as $columnIndex) {
        $sheet->getColumnDimensionByColumn($columnIndex)->setAutoSize(true);
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    $spreadsheet->disconnectWorksheets();
    exit();
}
