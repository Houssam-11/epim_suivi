<?php
declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/ExcelPlanningService.php';
require_once __DIR__ . '/ExcelPlanningConfig.php';

class ExcelPlanningExporter
{
    private const SESSION_WEEK_FILL = 'FFEDEDED';
    private const CONTINUOUS_ASSESSMENT_FILL = 'FFCC4125';
    private const TOTAL_ROW_FILL = 'FFD9EAD3';
    private const METRIC_COLUMN_FILL = 'FFF9C28B';
    private const VACATION_FILL = 'FFFFE599';
    private const EXAM_FILL = 'FF76A5AF';

    private mysqli $conn;
    private ExcelPlanningService $planningService;
    /** @var array<string, mixed> */
    private array $map;

    /**
     * @param array<string, mixed>|null $map
     */
    public function __construct(mysqli $conn, ?array $map = null)
    {
        $this->conn = $conn;
        $this->planningService = new ExcelPlanningService($conn);
        $this->map = $map ?? require __DIR__ . '/ExcelPlanningTemplateMap.php';
    }

    /**
     * Generates a filled copy of the official template and returns its metadata.
     *
     * @return array{path:string, filename:string}
     */
    public function export(int $anneeScolaireId, int $filiereId, int $anneeFormation = 0): array
    {
        $anneeFormation = 0;
        $templatePath = (string) ($this->map['template_path'] ?? '');
        if ($templatePath === '' || !is_file($templatePath)) {
            throw new RuntimeException("Le modèle Excel officiel est introuvable. Vérifiez la constante EXCEL_TEMPLATE_PATH : " . $templatePath);
        }

        $data = $this->planningService->getPlanningData($anneeScolaireId, $filiereId, $anneeFormation);
        $filiere = $this->selectSingleFiliere($data);
        if (!$filiere) {
            throw new RuntimeException('Aucune filière trouvée pour les filtres sélectionnés.');
        }

        $tmpTemplate = $this->createTemplateCopy($templatePath);
        $spreadsheet = $this->runPhpSpreadsheetOperation(static function () use ($tmpTemplate): Spreadsheet {
            return IOFactory::load($tmpTemplate);
        });
        $sheet = $this->selectWorksheet($spreadsheet);

        $this->runPhpSpreadsheetOperation(function () use ($sheet, $data, $filiere): void {
            $this->fillHeader($sheet, $data, $filiere);
            $this->fillUnits($sheet, $filiere['unites'] ?? [], $data);
            $this->finalizeWorksheetLayout($sheet, $data);
        });

        $filename = $this->buildFilename($data, $filiere);
        $outputPath = $this->createOutputPath($filename);

        $writer = new Xlsx($spreadsheet);
        $this->runPhpSpreadsheetOperation(static function () use ($writer, $outputPath): void {
            $writer->save($outputPath);
        });
        $this->removeDrawingPartsFromWorkbook($outputPath);
        $spreadsheet->disconnectWorksheets();
        @unlink($tmpTemplate);

        return [
            'path' => $outputPath,
            'filename' => $filename,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    private function selectSingleFiliere(array $data): ?array
    {
        $filieres = $data['filieres'] ?? [];
        return is_array($filieres) && isset($filieres[0]) ? $filieres[0] : null;
    }

    private function createTemplateCopy(string $templatePath): string
    {
        $tmpDir = __DIR__ . '/../exports/tmp';
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0775, true);
        }

        $copyPath = $tmpDir . '/planning_template_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.xlsx';
        if (!copy($templatePath, $copyPath)) {
            throw new RuntimeException('Impossible de créer une copie temporaire du modèle Excel.');
        }

        return $copyPath;
    }

    private function selectWorksheet(Spreadsheet $spreadsheet): Worksheet
    {
        $sheetName = $this->map['sheet_name'] ?? null;
        if (is_string($sheetName) && $sheetName !== '') {
            $sheet = $spreadsheet->getSheetByName($sheetName);
            if ($sheet instanceof Worksheet) {
                return $sheet;
            }
        }

        return $spreadsheet->getActiveSheet();
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $filiere
     */
    private function fillHeader(Worksheet $sheet, array $data, array $filiere): void
    {
        $cells = $this->map['cells'] ?? [];
        $anneeScolaire = $data['annee_scolaire']['libelle'] ?? '';
        $titleBlockCell = $cells['title_block'] ?? null;
        if ($titleBlockCell) {
            $this->setMappedValue(
                $sheet,
                $titleBlockCell,
                'Planning prévisionnel de réalisation des unités de formation du programme de la filière "' .
                trim((string) ($filiere['nom'] ?? '')) .
                '" - Année scolaire : ' . $anneeScolaire
            );

            return;
        }

        $this->setMappedValue(
            $sheet,
            $cells['title'] ?? null,
            'Planning prévisionnel de réalisation des unités de formation du programme de la filière "' . trim((string) ($filiere['nom'] ?? '')) . '"'
        );
        $this->setMappedValue($sheet, $cells['filiere'] ?? null, (string) ($filiere['nom'] ?? ''));
        $this->setMappedValue($sheet, $cells['annee_scolaire'] ?? null, 'Année scolaire : ' . $anneeScolaire);
    }

    /**
     * @param array<int, array<string, mixed>> $units
     */
    private function fillUnits(Worksheet $sheet, array $units, array $data): void
    {
        $unitMap = $this->map['units'] ?? [];
        $startRow = (int) ($unitMap['start_row'] ?? 1);
        $templateRow = (int) ($unitMap['template_row'] ?? $startRow);
        $totalTemplateRow = (int) ($unitMap['total_template_row'] ?? $templateRow);
        $lastColumn = (string) ($unitMap['last_column'] ?? 'A');
        $columns = $unitMap['columns'] ?? [];
        $weeklyColumns = $unitMap['weekly_columns'] ?? [];

        $orderedUnits = $this->sortUnits($units);
        $planRows = $this->buildGroupedPlanRows($orderedUnits);
        $this->clearTemplateDataRows($sheet, $unitMap, $columns);
        $rowCount = count($planRows);
        if ($rowCount === 0) {
            return;
        }

        $availableRows = max(1, ((int) ($unitMap['clear_end_row'] ?? $startRow)) - $startRow + 1);
        if ($rowCount > $availableRows) {
            $rowsToInsert = $rowCount - $availableRows;
            $insertBefore = $startRow + $availableRows;
            $this->insertStyledRows($sheet, $templateRow, $insertBefore, $rowsToInsert, $lastColumn);
        }

        $unitDisplayIndex = 1;
        $groupStartRow = $startRow;
        $totalRows = [];
        foreach ($planRows as $index => $planRow) {
            $row = $startRow + $index;
            if (($planRow['type'] ?? '') === 'total') {
                $this->applyRowStyle($sheet, $totalTemplateRow, $row, $lastColumn);
                $this->mergeTotalLabelCells($sheet, $row);
                $this->fillGroupTotalRow($sheet, $columns, $weeklyColumns, $row, $groupStartRow, $row - 1, (int) ($planRow['annee_formation'] ?? 0));
                $totalRows[] = $row;
                $groupStartRow = $row + 1;
                continue;
            }

            $this->applyRowStyle($sheet, $templateRow, $row, $lastColumn);
            $this->unmergeTotalLabelCells($sheet, $row);
            $this->fillUnitRow($sheet, $columns, $weeklyColumns, $row, $planRow['unit'], $unitDisplayIndex);
            $unitDisplayIndex++;
        }

        $lastRow = $startRow + $rowCount - 1;
        $calendarStyles = $this->buildCalendarWeekStyles($weeklyColumns, $data);
        $this->applyCalendarWeekStyles($sheet, $calendarStyles, $startRow, $lastRow);
        $this->labelCalendarPeriodsOnTotalRows($sheet, $weeklyColumns, $data, $totalRows);
        $this->applyControlContinuousStyles($sheet, $weeklyColumns, $planRows, $startRow);
    }

    /**
     * @param array<int, array<string, mixed>> $units
     * @return array<int, array<string, mixed>>
     */
    private function buildGroupedPlanRows(array $units): array
    {
        $rows = [];
        $currentYear = null;

        foreach ($units as $unit) {
            $year = (int) ($unit['annee_formation'] ?? 0);
            if ($currentYear !== null && $year !== $currentYear) {
                $rows[] = ['type' => 'total', 'annee_formation' => $currentYear];
            }

            $rows[] = ['type' => 'unit', 'annee_formation' => $year, 'unit' => $unit];
            $currentYear = $year;
        }

        if ($currentYear !== null) {
            $rows[] = ['type' => 'total', 'annee_formation' => $currentYear];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $columns
     * @param array<int|string, array<int|string, string>> $weeklyColumns
     * @param array<string, mixed> $unit
     */
    private function fillUnitRow(Worksheet $sheet, array $columns, array $weeklyColumns, int $row, array $unit, int $displayIndex): void
    {
        $unitTitle = trim((string) ($unit['intitule'] ?? ''));
        $this->setColumnValue($sheet, $columns['intitule'] ?? null, $row, $displayIndex . '. ' . $unitTitle);
        $this->setColumnValue($sheet, $columns['formateur'] ?? null, $row, (string) ($unit['formateur']['nom'] ?? ''));
        $this->setColumnValue($sheet, $columns['code_formateur'] ?? null, $row, null);
        $this->setColumnValue($sheet, $columns['vhg'] ?? null, $row, (float) ($unit['masse_horaire'] ?? 0));

        $this->fillWeeklyDistribution($sheet, $weeklyColumns, $row, $unit);
        $this->fillUnitTotals($sheet, $columns, $weeklyColumns, $row);
    }

    /**
     * @param array<int|string, array<int|string, string>> $weeklyColumns
     * @param array<string, mixed> $unit
     */
    private function fillWeeklyDistribution(Worksheet $sheet, array $weeklyColumns, int $row, array $unit): void
    {
        $distribution = $unit['repartition_hebdomadaire'] ?? [];
        if (!is_array($distribution)) {
            return;
        }

        foreach ($weeklyColumns as $monthNumber => $weeks) {
            foreach ($weeks as $weekIndex => $column) {
                $hours = (float) ($distribution[(int) $monthNumber]['weeks'][(int) $weekIndex] ?? 0);
                $this->setColumnValue($sheet, $column, $row, $hours > 0 ? $hours : null);
            }
        }
    }

    /**
     * @param array<string, mixed> $columns
     * @param array<int|string, array<int|string, string>> $weeklyColumns
     */
    private function fillUnitTotals(Worksheet $sheet, array $columns, array $weeklyColumns, int $row): void
    {
        $weeklyRange = $this->getWeeklyRange($weeklyColumns, $row);
        $vhgCell = ($columns['vhg'] ?? null) ? $columns['vhg'] . $row : '';
        $vhRealiseCell = ($columns['vh_realise'] ?? null) ? $columns['vh_realise'] . $row : '';
        $vhRestantCell = ($columns['vh_restant'] ?? null) ? $columns['vh_restant'] . $row : '';
        $pourcentageCell = ($columns['pourcentage_realise'] ?? null) ? $columns['pourcentage_realise'] . $row : '';

        if ($vhRealiseCell !== '' && $weeklyRange !== '') {
            $sheet->setCellValue($vhRealiseCell, '=SUM(' . $weeklyRange . ')');
        }

        if ($vhRestantCell !== '' && $vhgCell !== '' && $vhRealiseCell !== '') {
            $sheet->setCellValue($vhRestantCell, '=(' . $vhgCell . '-' . $vhRealiseCell . ')');
        }

        if ($pourcentageCell !== '' && $vhgCell !== '' && $vhRealiseCell !== '') {
            $sheet->setCellValue($pourcentageCell, '=IF(' . $vhRealiseCell . '=0,"pas encore démarré",ROUND((' . $vhRealiseCell . '/' . $vhgCell . ')*100,2))');
            $sheet->getStyle($pourcentageCell)->getNumberFormat()->setFormatCode('0.##" %"');
        }
    }

    /**
     * @param array<string, mixed> $columns
     * @param array<int|string, array<int|string, string>> $weeklyColumns
     */
    private function fillGroupTotalRow(Worksheet $sheet, array $columns, array $weeklyColumns, int $row, int $startRow, int $endRow, int $anneeFormation): void
    {
        $label = $anneeFormation > 0
            ? 'Total ' . $anneeFormation . ($anneeFormation === 1 ? 'ère année' : 'ème année')
            : 'Total';
        $this->setColumnValue($sheet, $columns['intitule'] ?? null, $row, $label);
        $this->setColumnValue($sheet, $columns['formateur'] ?? null, $row, null);
        $this->setColumnValue($sheet, $columns['code_formateur'] ?? null, $row, null);

        $unitRows = $this->unitRowsRange($startRow, $endRow);
        if ($unitRows === []) {
            return;
        }

        $this->setSumFormulaForRows($sheet, $columns['vhg'] ?? null, $row, $unitRows);
        foreach ($weeklyColumns as $weeks) {
            foreach ($weeks as $column) {
                $this->setSumFormulaForRows($sheet, $column, $row, $unitRows);
            }
        }
        $this->fillUnitTotals($sheet, $columns, $weeklyColumns, $row);
    }

    /**
     * @return int[]
     */
    private function unitRowsRange(int $startRow, int $endRow): array
    {
        if ($endRow < $startRow) {
            return [];
        }

        return range($startRow, $endRow);
    }

    /**
     * @param int[] $rows
     */
    private function setSumFormulaForRows(Worksheet $sheet, ?string $column, int $targetRow, array $rows): void
    {
        if (!$column || $rows === []) {
            return;
        }

        $refs = array_map(static fn (int $row): string => $column . $row, $rows);
        $sheet->setCellValue($column . $targetRow, '=SUM(' . implode(',', $refs) . ')');
    }

    /**
     * @param array<int|string, array<int|string, string>> $weeklyColumns
     */
    private function getWeeklyRange(array $weeklyColumns, int $row): string
    {
        $columns = [];
        foreach ($weeklyColumns as $weeks) {
            foreach ($weeks as $column) {
                $columns[] = $column;
            }
        }

        if ($columns === []) {
            return '';
        }

        return reset($columns) . $row . ':' . end($columns) . $row;
    }

    /**
     * @param array<int, array<string, mixed>> $units
     * @return array<int, array<string, mixed>>
     */
    private function sortUnits(array $units): array
    {
        usort($units, static function (array $a, array $b): int {
            return [
                (int) ($a['annee_formation'] ?? 0),
                (int) ($a['semestre'] ?? 1),
                (int) ($a['id'] ?? 0),
                (string) ($a['intitule'] ?? ''),
            ] <=> [
                (int) ($b['annee_formation'] ?? 0),
                (int) ($b['semestre'] ?? 1),
                (int) ($b['id'] ?? 0),
                (string) ($b['intitule'] ?? ''),
            ];
        });

        return $units;
    }

    /**
     * @param array<string, mixed> $unitMap
     * @param array<string, mixed> $columns
     */
    private function clearTemplateDataRows(Worksheet $sheet, array $unitMap, array $columns): void
    {
        $startRow = (int) ($unitMap['clear_start_row'] ?? $unitMap['start_row'] ?? 1);
        $endRow = (int) ($unitMap['clear_end_row'] ?? $startRow);
        $lastColumn = (string) ($unitMap['last_column'] ?? 'A');
        $lastColumnIndex = Coordinate::columnIndexFromString($lastColumn);

        for ($row = $startRow; $row <= $endRow; $row++) {
            for ($columnIndex = 1; $columnIndex <= $lastColumnIndex; $columnIndex++) {
                $column = Coordinate::stringFromColumnIndex($columnIndex);
                $sheet->setCellValue($column . $row, null);
            }
        }

        for ($row = $startRow; $row <= $endRow; $row++) {
            $this->setColumnValue($sheet, $columns['vh_realise'] ?? null, $row, null);
            $this->setColumnValue($sheet, $columns['vh_restant'] ?? null, $row, null);
            $this->setColumnValue($sheet, $columns['pourcentage_realise'] ?? null, $row, null);
        }
    }

    private function insertStyledRows(Worksheet $sheet, int $templateRow, int $firstTargetRow, int $count, string $lastColumn): void
    {
        if ($count <= 0) {
            return;
        }

        $sheet->insertNewRowBefore($firstTargetRow, $count);

        for ($i = 0; $i < $count; $i++) {
            $targetRow = $firstTargetRow + $i;
            $sheet->duplicateStyle($sheet->getStyle('A' . $templateRow . ':' . $lastColumn . $templateRow), 'A' . $targetRow . ':' . $lastColumn . $targetRow);
            $sheet->getRowDimension($targetRow)->setRowHeight($sheet->getRowDimension($templateRow)->getRowHeight());
        }
    }

    private function applyRowStyle(Worksheet $sheet, int $templateRow, int $targetRow, string $lastColumn): void
    {
        $sheet->duplicateStyle(
            $sheet->getStyle('A' . $templateRow . ':' . $lastColumn . $templateRow),
            'A' . $targetRow . ':' . $lastColumn . $targetRow
        );
        $sheet->getRowDimension($targetRow)->setRowHeight($sheet->getRowDimension($templateRow)->getRowHeight());
    }

    private function mergeTotalLabelCells(Worksheet $sheet, int $row): void
    {
        $range = 'A' . $row . ':C' . $row;
        if (!in_array($range, $sheet->getMergeCells(), true)) {
            $this->unmergeTotalLabelCells($sheet, $row);
            $sheet->mergeCells($range);
        }
    }

    private function unmergeTotalLabelCells(Worksheet $sheet, int $row): void
    {
        foreach ($sheet->getMergeCells() as $range) {
            if (preg_match('/^A' . $row . ':C' . $row . '$/', $range)) {
                $sheet->unmergeCells($range);
            }
        }
    }

    /**
     * @param array<int|string, array<int|string, string>> $weeklyColumns
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    private function buildCalendarWeekStyles(array $weeklyColumns, array $data): array
    {
        $styles = [];
        foreach (($data['periodes_vacances'] ?? []) as $period) {
            foreach ($this->columnsForPeriod($weeklyColumns, $period['date_debut'] ?? null, $period['date_fin'] ?? null) as $column) {
                $styles[$column] = self::VACATION_FILL;
            }
        }

        foreach (($data['periodes_examens'] ?? []) as $examPeriod) {
            foreach (['semestre1', 'semestre2'] as $semesterKey) {
                $period = $examPeriod[$semesterKey] ?? [];
                foreach ($this->columnsForPeriod($weeklyColumns, $period['date_debut'] ?? null, $period['date_fin'] ?? null) as $column) {
                    $styles[$column] = self::EXAM_FILL;
                }
            }
        }

        return $styles;
    }

    /**
     * @param array<int|string, array<int|string, string>> $weeklyColumns
     * @return string[]
     */
    private function columnsForPeriod(array $weeklyColumns, $startDate, $endDate): array
    {
        if (!$startDate && !$endDate) {
            return [];
        }

        try {
            $start = new DateTime((string) ($startDate ?: $endDate));
            $end = new DateTime((string) ($endDate ?: $startDate));
        } catch (Throwable $e) {
            return [];
        }

        if ($end < $start) {
            [$start, $end] = [$end, $start];
        }

        $columns = [];
        $current = clone $start;
        while ($current <= $end) {
            $week = PedagogicalCalendar::getPedagogicalWeek($current);
            $monthNumber = (int) ($week['month_number'] ?? 0);
            $weekIndex = (int) ($week['week'] ?? 0);
            $column = $weeklyColumns[$monthNumber][$weekIndex] ?? null;
            if ($column) {
                $columns[$column] = $column;
            }
            $current->modify('+1 day');
        }

        return array_values($columns);
    }

    /**
     * @param array<int|string, array<int|string, string>> $weeklyColumns
     * @param array<string, string> $styles
     */
    private function applyCalendarWeekStyles(Worksheet $sheet, array $styles, int $startRow, int $endRow): void
    {
        if ($styles === []) {
            return;
        }

        foreach ($styles as $column => $color) {
            $this->applyCellFill($sheet, $column . $startRow . ':' . $column . $endRow, $color);
        }
    }

    /**
     * @param array<int|string, array<int|string, string>> $weeklyColumns
     * @param array<string, mixed> $data
     * @param int[] $totalRows
     */
    private function labelCalendarPeriodsOnTotalRows(Worksheet $sheet, array $weeklyColumns, array $data, array $totalRows): void
    {
        if ($totalRows === []) {
            return;
        }

        $periods = [];
        foreach (($data['periodes_vacances'] ?? []) as $period) {
            $periods[] = [
                'label' => trim((string) ($period['nom'] ?? '')) !== '' ? (string) $period['nom'] : 'Vacances',
                'columns' => $this->columnsForPeriod($weeklyColumns, $period['date_debut'] ?? null, $period['date_fin'] ?? null),
            ];
        }
        foreach (($data['periodes_examens'] ?? []) as $examPeriod) {
            foreach (['semestre1' => 'Examens S1', 'semestre2' => 'Examens S2'] as $semesterKey => $label) {
                $period = $examPeriod[$semesterKey] ?? [];
                $periods[] = [
                    'label' => $label,
                    'columns' => $this->columnsForPeriod($weeklyColumns, $period['date_debut'] ?? null, $period['date_fin'] ?? null),
                ];
            }
        }

        foreach ($periods as $period) {
            $columns = $period['columns'];
            if ($columns === []) {
                continue;
            }

            foreach ($totalRows as $row) {
                $this->clearMergesForColumns($sheet, $columns, $row);
                $first = $columns[0];
                $last = $columns[count($columns) - 1];
                if ($first !== $last) {
                    $sheet->mergeCells($first . $row . ':' . $last . $row);
                }
                $this->setColumnValue($sheet, $first, $row, (string) $period['label']);
            }
        }
    }

    /**
     * @param string[] $columns
     */
    private function clearMergesForColumns(Worksheet $sheet, array $columns, int $row): void
    {
        $columnIndexes = array_map(static fn (string $column): int => Coordinate::columnIndexFromString($column), $columns);
        foreach ($sheet->getMergeCells() as $range) {
            [$start, $end] = explode(':', $range) + [null, null];
            if (!$start || !$end) {
                continue;
            }
            [$startColumn, $startRow] = Coordinate::coordinateFromString($start);
            [$endColumn, $endRow] = Coordinate::coordinateFromString($end);
            if ((int) $startRow !== $row || (int) $endRow !== $row) {
                continue;
            }
            $rangeStart = Coordinate::columnIndexFromString($startColumn);
            $rangeEnd = Coordinate::columnIndexFromString($endColumn);
            foreach ($columnIndexes as $columnIndex) {
                if ($columnIndex >= $rangeStart && $columnIndex <= $rangeEnd) {
                    $sheet->unmergeCells($range);
                    break;
                }
            }
        }
    }

    /**
     * @param array<int|string, array<int|string, string>> $weeklyColumns
     * @param array<int, array<string, mixed>> $planRows
     */
    private function applyControlContinuousStyles(Worksheet $sheet, array $weeklyColumns, array $planRows, int $startRow): void
    {
        foreach ($planRows as $index => $planRow) {
            if (($planRow['type'] ?? '') !== 'unit') {
                continue;
            }

            $unit = $planRow['unit'] ?? [];
            $distribution = is_array($unit) ? ($unit['repartition_hebdomadaire'] ?? []) : [];
            if (!is_array($distribution)) {
                continue;
            }

            $row = $startRow + $index;
            foreach ($weeklyColumns as $monthNumber => $weeks) {
                foreach ($weeks as $weekIndex => $column) {
                    if ((int) ($distribution[(int) $monthNumber]['controles_continus'][(int) $weekIndex] ?? 0) > 0) {
                        $this->applyCellFill($sheet, $column . $row, self::CONTINUOUS_ASSESSMENT_FILL);
                    }
                }
            }
        }
    }

    private function applyCellFill(Worksheet $sheet, string $range, string $argb): void
    {
        $sheet->getStyle($range)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB($argb);
    }

    private function setMappedValue(Worksheet $sheet, ?string $cell, $value): void
    {
        if (!$cell) {
            return;
        }

        $sheet->setCellValueExplicit($cell, (string) $value, DataType::TYPE_STRING);
    }

    private function setColumnValue(Worksheet $sheet, ?string $column, int $row, $value): void
    {
        if (!$column) {
            return;
        }

        $cell = $column . $row;
        if ($value === null || $value === '') {
            $sheet->setCellValue($cell, null);
            return;
        }

        if (is_numeric($value)) {
            $sheet->setCellValue($cell, (float) $value);
            return;
        }

        $sheet->setCellValueExplicit($cell, (string) $value, DataType::TYPE_STRING);
    }

    private function finalizeWorksheetLayout(Worksheet $sheet, array $data): void
    {
        $this->removeDrawings($sheet);

        $sheet->removeColumn('AS', 4);
        $sheet->removeColumn('C', 1);
        $this->removeColumnsAfter($sheet, 'AT');
        $this->removeMergesIntersectingColumnsAfter($sheet, 'AT');

        $sheet->getColumnDimension('A')->setWidth(36);
        $sheet->getColumnDimension('B')->setWidth(22);
        $sheet->getColumnDimension('C')->setWidth(8);
        foreach ($this->flattenWeeklyColumns($this->finalWeeklyColumns()) as $column) {
            $sheet->getColumnDimension($column)->setWidth(4.5);
        }
        $sheet->getColumnDimension('AR')->setWidth(10);
        $sheet->getColumnDimension('AS')->setWidth(10);
        $sheet->getColumnDimension('AT')->setWidth(12);

        $highestRow = $sheet->getHighestRow();
        $finalWeeklyColumns = $this->finalWeeklyColumns();
        $totalRows = $this->normalizeFinalRows($sheet, $finalWeeklyColumns);
        $finalUnits = $data['filieres'][0]['unites'] ?? [];
        $finalPlanRows = is_array($finalUnits) ? $this->buildGroupedPlanRows($this->sortUnits($finalUnits)) : [];
        $this->clearWeeklyFills($sheet, $finalWeeklyColumns, 6, $highestRow);
        $this->clearVacationWeekValues($sheet, $finalWeeklyColumns, $data, 6, $highestRow);
        $this->applySessionWeekStyles($sheet, $finalWeeklyColumns, $finalPlanRows, 6);
        $this->styleFinalTotalRows($sheet, $totalRows, 'AT');
        $calendarStyles = $this->buildCalendarWeekStyles($finalWeeklyColumns, $data);
        $this->applyCalendarWeekStyles($sheet, $calendarStyles, 6, $highestRow);
        $this->labelCalendarPeriodsOnTotalRows($sheet, $finalWeeklyColumns, $data, $totalRows);
        $this->applyControlContinuousStyles($sheet, $finalWeeklyColumns, $finalPlanRows, 6);
        $this->resetFinalHeaders($sheet);
        $this->applyMetricColumnFills($sheet, $totalRows, $highestRow);
        $this->styleFinalTotalRows($sheet, $totalRows, 'AT');
        $this->alignNumericAreas($sheet, $finalWeeklyColumns, $highestRow);
        $this->applyPercentageColumnBorders($sheet, $highestRow);
        $sheet->getStyle('AT6:AT' . $highestRow)->getNumberFormat()->setFormatCode('0.##" %"');
        $this->removeMergesIntersectingColumnsAfter($sheet, 'AT');
        $sheet->garbageCollect();
    }

    private function removeDrawings(Worksheet $sheet): void
    {
        $drawingCollection = $sheet->getDrawingCollection();
        foreach (array_reverse(array_keys(iterator_to_array($drawingCollection))) as $index) {
            $drawingCollection->offsetUnset($index);
        }
    }

    private function removeDrawingPartsFromWorkbook(string $xlsxPath): void
    {
        if (!class_exists(ZipArchive::class) || !is_file($xlsxPath)) {
            return;
        }

        $zip = new ZipArchive();
        if ($zip->open($xlsxPath) !== true) {
            return;
        }

        $entriesToDelete = [];
        $entriesToUpdate = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);
            if (preg_match('#^xl/(drawings|media|charts)/#', $name)) {
                $entriesToDelete[] = $name;
                continue;
            }

            $contents = $zip->getFromIndex($index);
            if (!is_string($contents)) {
                continue;
            }

            if ($name === '[Content_Types].xml') {
                $updated = preg_replace('#<Override[^>]+PartName="/xl/(drawings|media|charts)/[^"]+"[^>]*/>#', '', $contents) ?? $contents;
                if ($updated !== $contents) {
                    $entriesToUpdate[$name] = $updated;
                }
                continue;
            }

            if (preg_match('#^xl/worksheets/_rels/.*\.rels$#', $name)) {
                $updated = preg_replace(
                    '#<Relationship\b[^>]*(?:Type="[^"]*/(?:drawing|image|chart)"|Target="(?:\.\./)?(?:drawings|media|charts)/[^"]+")[^>]*/>#',
                    '',
                    $contents
                ) ?? $contents;
                if ($updated !== $contents) {
                    $entriesToUpdate[$name] = $updated;
                }
                continue;
            }

            if (preg_match('#^xl/worksheets/[^/]+\.xml$#', $name)) {
                $updated = preg_replace('#<drawing\b[^>]*/>#', '', $contents) ?? $contents;
                $updated = preg_replace('#<legacyDrawing\b[^>]*/>#', '', $updated) ?? $updated;
                $updated = preg_replace('#<picture\b[^>]*/>#', '', $updated) ?? $updated;
                if ($updated !== $contents) {
                    $entriesToUpdate[$name] = $updated;
                }
            }
        }

        foreach ($entriesToDelete as $name) {
            $zip->deleteName($name);
        }
        foreach ($entriesToUpdate as $name => $contents) {
            $zip->deleteName($name);
            $zip->addFromString($name, $contents);
        }

        $zip->close();
    }

    private function removeColumnsAfter(Worksheet $sheet, string $lastAllowedColumn): void
    {
        $lastAllowedIndex = Coordinate::columnIndexFromString($lastAllowedColumn);
        $highestIndex = Coordinate::columnIndexFromString($sheet->getHighestColumn());
        for ($columnIndex = $highestIndex; $columnIndex > $lastAllowedIndex; $columnIndex--) {
            $sheet->removeColumn(Coordinate::stringFromColumnIndex($columnIndex), 1);
        }
    }

    private function removeMergesIntersectingColumnsAfter(Worksheet $sheet, string $lastAllowedColumn): void
    {
        $lastAllowedIndex = Coordinate::columnIndexFromString($lastAllowedColumn);
        foreach ($sheet->getMergeCells() as $range) {
            [$start, $end] = explode(':', $range) + [null, null];
            if (!$start || !$end) {
                continue;
            }
            [$startColumn] = Coordinate::coordinateFromString($start);
            [$endColumn] = Coordinate::coordinateFromString($end);
            if (Coordinate::columnIndexFromString($startColumn) > $lastAllowedIndex || Coordinate::columnIndexFromString($endColumn) > $lastAllowedIndex) {
                $sheet->unmergeCells($range);
            }
        }
    }

    /**
     * @param array<int, array<int, string>> $weeklyColumns
     */
    private function clearWeeklyFills(Worksheet $sheet, array $weeklyColumns, int $startRow, int $endRow): void
    {
        foreach ($weeklyColumns as $weeks) {
            foreach ($weeks as $column) {
                $sheet->getStyle($column . $startRow . ':' . $column . $endRow)
                    ->getFill()
                    ->setFillType(Fill::FILL_NONE);
            }
        }
    }

    /**
     * @param array<int, array<int, string>> $weeklyColumns
     * @param array<string, mixed> $data
     */
    private function clearVacationWeekValues(Worksheet $sheet, array $weeklyColumns, array $data, int $startRow, int $endRow): void
    {
        $columns = [];
        foreach (($data['periodes_vacances'] ?? []) as $period) {
            foreach ($this->columnsForPeriod($weeklyColumns, $period['date_debut'] ?? null, $period['date_fin'] ?? null) as $column) {
                $columns[$column] = $column;
            }
        }

        foreach ($columns as $column) {
            for ($row = $startRow; $row <= $endRow; $row++) {
                $sheet->setCellValue($column . $row, null);
            }
        }
    }

    /**
     * @param array<int, array<int, string>> $weeklyColumns
     * @param array<int, array<string, mixed>> $planRows
     */
    private function applySessionWeekStyles(Worksheet $sheet, array $weeklyColumns, array $planRows, int $startRow): void
    {
        foreach ($planRows as $index => $planRow) {
            if (($planRow['type'] ?? '') !== 'unit') {
                continue;
            }

            $unit = $planRow['unit'] ?? [];
            $distribution = is_array($unit) ? ($unit['repartition_hebdomadaire'] ?? []) : [];
            if (!is_array($distribution)) {
                continue;
            }

            $row = $startRow + $index;
            foreach ($weeklyColumns as $monthNumber => $weeks) {
                foreach ($weeks as $weekIndex => $column) {
                    $hours = (float) ($distribution[(int) $monthNumber]['weeks'][(int) $weekIndex] ?? 0);
                    if ($hours > 0) {
                        $this->applyCellFill($sheet, $column . $row, self::SESSION_WEEK_FILL);
                    }
                }
            }
        }
    }

    /**
     * @param int[] $totalRows
     */
    private function styleFinalTotalRows(Worksheet $sheet, array $totalRows, string $lastColumn): void
    {
        $referenceHeight = null;
        foreach ($totalRows as $row) {
            if ($referenceHeight === null) {
                $referenceHeight = $sheet->getRowDimension($row)->getRowHeight();
            }

            $range = 'A' . $row . ':' . $lastColumn . $row;
            $sheet->getRowDimension($row)->setRowHeight($referenceHeight);
            $sheet->getStyle($range)->getFont()->setBold(true);
            $sheet->getStyle($range)->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB(self::TOTAL_ROW_FILL);
            $sheet->getStyle($range)->getBorders()->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN);
            $sheet->getStyle($range)->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                ->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getStyle('A' . $row . ':B' . $row)->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }
    }

    /**
     * @param int[] $totalRows
     */
    private function applyMetricColumnFills(Worksheet $sheet, array $totalRows, int $highestRow): void
    {
        $totalLookup = array_fill_keys($totalRows, true);
        foreach (['C', 'AR', 'AT'] as $column) {
            $this->applyCellFill($sheet, $column . '5', self::METRIC_COLUMN_FILL);
        }

        for ($row = 6; $row <= $highestRow; $row++) {
            if (isset($totalLookup[$row])) {
                continue;
            }

            foreach (['C', 'AR', 'AT'] as $column) {
                $this->applyCellFill($sheet, $column . $row, self::METRIC_COLUMN_FILL);
            }
        }
    }

    /**
     * @param array<int, array<int, string>> $weeklyColumns
     */
    private function alignNumericAreas(Worksheet $sheet, array $weeklyColumns, int $highestRow): void
    {
        foreach (['C', 'AR', 'AS', 'AT'] as $column) {
            $this->centerRange($sheet, $column . '6:' . $column . $highestRow);
        }

        foreach ($this->flattenWeeklyColumns($weeklyColumns) as $column) {
            $this->centerRange($sheet, $column . '6:' . $column . $highestRow);
        }
    }

    private function centerRange(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);
    }

    private function applyPercentageColumnBorders(Worksheet $sheet, int $highestRow): void
    {
        $sheet->duplicateStyle($sheet->getStyle('AR5'), 'AT5');
        $this->applyCellFill($sheet, 'AT5', self::METRIC_COLUMN_FILL);
        $sheet->getStyle('AT6:AT' . $highestRow)->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN);
    }

    /**
     * @param array<int, array<int, string>> $weeklyColumns
     * @return string[]
     */
    private function flattenWeeklyColumns(array $weeklyColumns): array
    {
        $columns = [];
        foreach ($weeklyColumns as $weeks) {
            foreach ($weeks as $column) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function finalWeeklyColumns(): array
    {
        $source = $this->map['units']['weekly_columns'] ?? [];
        $final = [];
        foreach ($source as $monthNumber => $weeks) {
            if ((int) $monthNumber === 8) {
                continue;
            }
            foreach ($weeks as $weekIndex => $column) {
                $index = Coordinate::columnIndexFromString((string) $column);
                if ($index > Coordinate::columnIndexFromString('C')) {
                    $index--;
                }
                $final[(int) $monthNumber][(int) $weekIndex] = Coordinate::stringFromColumnIndex($index);
            }
        }

        return $final;
    }

    /**
     * @param array<int, array<int, string>> $weeklyColumns
     * @return int[]
     */
    private function normalizeFinalRows(Worksheet $sheet, array $weeklyColumns): array
    {
        $highestRow = $sheet->getHighestRow();
        $groupStartRow = 6;
        $totalRows = [];

        for ($row = 6; $row <= $highestRow; $row++) {
            $label = trim((string) $sheet->getCell('A' . $row)->getValue());
            if ($label === '') {
                continue;
            }

            $this->unmergeLabelArea($sheet, $row);
            if (stripos($label, 'Total') === 0) {
                $this->mergeFinalTotalLabelCells($sheet, $row);
                $this->fillFinalTotalRow($sheet, $weeklyColumns, $row, $groupStartRow, $row - 1);
                $totalRows[] = $row;
                $groupStartRow = $row + 1;
            } else {
                $this->fillFinalTotals($sheet, $weeklyColumns, $row);
            }
        }

        return $totalRows;
    }

    private function unmergeLabelArea(Worksheet $sheet, int $row): void
    {
        foreach ($sheet->getMergeCells() as $range) {
            if (preg_match('/^A' . $row . ':[BC]' . $row . '$/', $range)) {
                $sheet->unmergeCells($range);
            }
        }
    }

    private function mergeFinalTotalLabelCells(Worksheet $sheet, int $row): void
    {
        $range = 'A' . $row . ':B' . $row;
        if (!in_array($range, $sheet->getMergeCells(), true)) {
            $sheet->mergeCells($range);
        }
    }

    /**
     * @param array<int, array<int, string>> $weeklyColumns
     */
    private function fillFinalTotals(Worksheet $sheet, array $weeklyColumns, int $row): void
    {
        $weeklyRange = $this->finalWeeklyRange($weeklyColumns, $row);
        $sheet->setCellValue('AR' . $row, '=SUM(' . $weeklyRange . ')');
        $sheet->setCellValue('AS' . $row, '=(C' . $row . '-AR' . $row . ')');
        $sheet->setCellValue('AT' . $row, '=IF(AR' . $row . '=0,"pas encore démarré",ROUND((AR' . $row . '/C' . $row . ')*100,2))');
        $sheet->getStyle('AT' . $row)->getNumberFormat()->setFormatCode('0.##" %"');
    }

    /**
     * @param array<int, array<int, string>> $weeklyColumns
     */
    private function fillFinalTotalRow(Worksheet $sheet, array $weeklyColumns, int $row, int $startRow, int $endRow): void
    {
        $unitRows = $this->unitRowsRange($startRow, $endRow);
        $this->setSumFormulaForRows($sheet, 'C', $row, $unitRows);
        foreach ($weeklyColumns as $weeks) {
            foreach ($weeks as $column) {
                $this->setSumFormulaForRows($sheet, $column, $row, $unitRows);
            }
        }
        $this->fillFinalTotals($sheet, $weeklyColumns, $row);
    }

    /**
     * @param array<int, array<int, string>> $weeklyColumns
     */
    private function finalWeeklyRange(array $weeklyColumns, int $row): string
    {
        $columns = [];
        foreach ($weeklyColumns as $weeks) {
            foreach ($weeks as $column) {
                $columns[] = $column;
            }
        }

        return reset($columns) . $row . ':' . end($columns) . $row;
    }

    private function resetFinalHeaders(Worksheet $sheet): void
    {
        foreach ($sheet->getMergeCells() as $range) {
            if (preg_match('/^AR5:.*5$/', $range)) {
                $sheet->unmergeCells($range);
            }
        }
        $sheet->setCellValue('AR5', 'VH réalisé');
        $sheet->setCellValue('AS5', 'VH restant');
        $sheet->duplicateStyle($sheet->getStyle('AR5'), 'AS5');
        $sheet->duplicateStyle($sheet->getStyle('AR5'), 'AT5');
        $sheet->setCellValue('AT5', 'Le % réalisé');
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $filiere
     */
    private function buildFilename(array $data, array $filiere): string
    {
        $prefix = (string) ($this->map['filename']['prefix'] ?? 'Planning');
        $filiereName = $this->slug((string) ($filiere['nom'] ?? 'filiere'));
        $anneeScolaire = $this->slug((string) ($data['annee_scolaire']['libelle'] ?? 'annee'));
        $anneeFormation = (int) ($data['annee_formation'] ?? 0);
        $formationPart = $anneeFormation > 0 ? '_' . $anneeFormation . 'A' : '';

        return $prefix . '_' . $filiereName . '_' . $anneeScolaire . $formationPart . '.xlsx';
    }

    private function createOutputPath(string $filename): string
    {
        $dir = __DIR__ . '/../exports/plannings';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir . '/' . date('Ymd_His') . '_' . $filename;
    }

    private function slug(string $value): string
    {
        $value = trim($value);
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $value = $transliterated !== false ? $transliterated : $value;
        $value = preg_replace('/[^A-Za-z0-9]+/', '_', $value) ?? '';
        $value = trim($value, '_');

        return $value !== '' ? $value : 'export';
    }

    /**
     * Older PhpSpreadsheet versions can emit PHP notices on recent PHP runtimes.
     * Keep that compatibility noise inside the library without masking app errors.
     */
    private function runPhpSpreadsheetOperation(callable $operation)
    {
        set_error_handler(static function (int $severity, string $message, string $file): bool {
            $normalizedFile = str_replace('\\', '/', $file);
            if (str_contains($normalizedFile, '/vendor/phpoffice/phpspreadsheet/')) {
                return true;
            }

            return false;
        });

        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }
}
