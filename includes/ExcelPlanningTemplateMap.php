<?php
declare(strict_types=1);

require_once __DIR__ . '/ExcelPlanningConfig.php';

return [
    'template_path' => EXCEL_TEMPLATE_PATH,
    'sheet_name' => null,
    'cells' => [
        'title_block' => 'A1',
        'title' => null,
        'filiere' => null,
        'annee_scolaire' => null,
    ],
    'units' => [
        'start_row' => 6,
        'template_row' => 6,
        'total_template_row' => 23,
        'clear_start_row' => 6,
        'clear_end_row' => 36,
        'last_column' => 'AY',
        'columns' => [
            'intitule' => 'A',
            'formateur' => 'B',
            'code_formateur' => 'C',
            'vhg' => 'D',
            'vh_realise' => 'AW',
            'vh_restant' => 'AX',
            'pourcentage_realise' => 'AY',
        ],
        'weekly_columns' => [
            10 => [1 => 'E', 2 => 'F', 3 => 'G', 4 => 'H'],
            11 => [1 => 'I', 2 => 'J', 3 => 'K', 4 => 'L'],
            12 => [1 => 'M', 2 => 'N', 3 => 'O', 4 => 'P'],
            1 => [1 => 'Q', 2 => 'R', 3 => 'S', 4 => 'T'],
            2 => [1 => 'U', 2 => 'V', 3 => 'W', 4 => 'X'],
            3 => [1 => 'Y', 2 => 'Z', 3 => 'AA', 4 => 'AB'],
            4 => [1 => 'AC', 2 => 'AD', 3 => 'AE', 4 => 'AF'],
            5 => [1 => 'AG', 2 => 'AH', 3 => 'AI', 4 => 'AJ'],
            6 => [1 => 'AK', 2 => 'AL', 3 => 'AM', 4 => 'AN'],
            7 => [1 => 'AO', 2 => 'AP', 3 => 'AQ', 4 => 'AR'],
            8 => [1 => 'AS', 2 => 'AT', 3 => 'AU', 4 => 'AV'],
        ],
    ],
    'filename' => [
        'prefix' => 'Planning',
    ],
];
