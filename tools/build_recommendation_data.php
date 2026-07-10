<?php
declare(strict_types=1);

const ROOT_DIR = __DIR__ . '/..';
const DATA_DIR = ROOT_DIR . '/data';
const REPORT_DIR = ROOT_DIR . '/reports';
const SEED_DIR = ROOT_DIR . '/database/seeds';

$sources = [
    'suivi pédagogique(Développement informatique).csv' => [
        'filiere' => 'Développement informatique',
    ],
    'suivi pédagogique(Gestion des entreprises).csv' => [
        'filiere' => 'Gestion des entreprises',
    ],
    'suivi pédagogique(Infographiste).csv' => [
        'filiere' => 'Infographiste',
    ],
];

foreach ([DATA_DIR, REPORT_DIR, SEED_DIR] as $directory) {
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException("Impossible de creer {$directory}");
    }
}

$schemaSql = file_get_contents(ROOT_DIR . '/script_sp.sql');
if ($schemaSql === false) {
    throw new RuntimeException('Le schema script_sp.sql est introuvable.');
}
$sequenceInsertStart = strpos($schemaSql, 'INSERT INTO `sequences_pedagogiques`');
$sequenceInsertEnd = strpos($schemaSql, 'ALTER TABLE `sequences_pedagogiques` ENABLE KEYS');
if ($sequenceInsertStart === false || $sequenceInsertEnd === false) {
    throw new RuntimeException('La liste des sequences est introuvable dans script_sp.sql.');
}
$sequenceSection = substr($schemaSql, $sequenceInsertStart, $sequenceInsertEnd - $sequenceInsertStart);
preg_match_all('/^\s*\((\d+),\s*(\d+),/m', $sequenceSection, $sequenceMatches, PREG_SET_ORDER);
$schemaSequencesByUnit = [];
foreach ($sequenceMatches as $match) {
    $schemaSequencesByUnit[(int) $match[2]][] = (int) $match[1];
}
foreach ($schemaSequencesByUnit as &$sequenceIds) {
    sort($sequenceIds, SORT_NUMERIC);
}
unset($sequenceIds);

function normalize_text(string $value): string
{
    $value = trim($value);
    $value = str_replace(["\xC2\xA0", "\r", "\n", "\t"], [' ', ' ', ' ', ' '], $value);
    $value = strtr($value, [
        '’' => "'", '‘' => "'", '´' => "'", '“' => '"', '”' => '"',
        '–' => '-', '—' => '-', '…' => '...', 'œ' => 'oe', 'Œ' => 'Oe',
    ]);
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    $value = preg_replace('/\s+([,.;:!?])/u', '$1', $value) ?? $value;
    $value = preg_replace('/([,.;:!?])(?=\p{L})/u', '$1 ', $value) ?? $value;
    $value = preg_replace('/\bournir\b/u', 'Fournir', $value) ?? $value;
    $value = preg_replace('/\bnecessaires\b/ui', 'nécessaires', $value) ?? $value;
    $value = preg_replace('/\bappropries\b/ui', 'appropriés', $value) ?? $value;
    $value = preg_replace('/\bevenements\b/ui', 'événements', $value) ?? $value;
    $value = ucfirst_utf8($value);
    if ($value !== '' && !preg_match('/[.!?;:]$/u', $value)) {
        $value .= '.';
    }
    return $value;
}

function ucfirst_utf8(string $value): string
{
    if ($value === '') {
        return '';
    }
    return mb_strtoupper(mb_substr($value, 0, 1, 'UTF-8'), 'UTF-8')
        . mb_substr($value, 1, null, 'UTF-8');
}

function comparison_key(string $value): string
{
    $value = mb_strtolower(normalize_text($value), 'UTF-8');
    $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    $value = $transliterated !== false ? $transliterated : $value;
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;
    return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
}

function content_hash(string $type, string $text): string
{
    return hash('sha256', $type . '|' . comparison_key($text));
}

function token_set(string $value): array
{
    $stopWords = array_flip([
        'avec', 'dans', 'pour', 'des', 'les', 'une', 'sur', 'par', 'aux', 'est',
        'sont', 'leur', 'leurs', 'plus', 'bien', 'afin', 'cette', 'ces', 'entre',
    ]);
    $tokens = preg_split('/\s+/', comparison_key($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $tokens = array_filter($tokens, static fn($token) => strlen($token) >= 4 && !isset($stopWords[$token]));
    return array_values(array_unique($tokens));
}

function jaccard_similarity(string $left, string $right): float
{
    $a = token_set($left);
    $b = token_set($right);
    $union = array_unique(array_merge($a, $b));
    if ($union === []) {
        return 0.0;
    }
    return count(array_intersect($a, $b)) / count($union);
}

function sql_string(string $value): string
{
    return "'" . str_replace(["\\", "'"], ["\\\\", "''"], $value) . "'";
}

function generated_row(array $base): array
{
    $objective = rtrim($base['objectif'], '.');
    $prefix = match ($base['filiere']) {
        'Développement informatique' => 'Atelier pratique guidé sur poste informatique',
        'Gestion des entreprises' => "Étude de cas contextualisée dans une entreprise marocaine",
        'Infographiste' => 'Atelier de création visuelle avec démonstration technique',
        default => 'Mise en situation professionnelle guidée',
    };
    $support = match ($base['filiere']) {
        'Développement informatique' => 'un exercice progressif, des tests de validation et une correction collective',
        'Gestion des entreprises' => 'des documents professionnels, des calculs appliqués et une synthèse collective',
        'Infographiste' => 'un brief créatif, des références visuelles et une critique collective des productions',
        default => 'un cas pratique et une correction collective',
    };

    return [
        'filiere' => $base['filiere'],
        'unite_id' => $base['unite_id'],
        'sequence_id' => $base['sequence_id'],
        'objectif' => normalize_text("Mettre en pratique l'objectif suivant : {$objective}"),
        'description' => normalize_text("{$prefix} afin de mettre en pratique « {$objective} », avec {$support}"),
        'observation' => normalize_text("La mise en application est globalement acquise ; les écarts observés concernent surtout la rigueur de la démarche et la justification des choix"),
        'disposition' => normalize_text("Prévoir une activité de consolidation ciblée sur « {$objective} » avec critères de réussite explicites et retour individualisé"),
        'source' => 'generated',
        'source_file' => 'enrichissement_interne',
        'source_line' => 0,
    ];
}

function complete_partial_row(array $row): array
{
    $objective = rtrim($row['objectif'], '.');
    $context = match ($row['filiere']) {
        'Développement informatique' => [
            'description' => "Démonstration technique suivie d'un atelier pratique sur « {$objective} », avec réalisation progressive, tests et correction collective",
            'observation' => "La démarche technique est comprise ; quelques stagiaires doivent encore consolider l'analyse des erreurs et la validation du résultat",
            'disposition' => "Prévoir un exercice d'application autonome sur « {$objective} » avec jeu de tests et critères de validation",
        ],
        'Gestion des entreprises' => [
            'description' => "Étude d'un cas d'entreprise portant sur « {$objective} », exploitation de documents professionnels, mise en commun des résultats et correction argumentée",
            'observation' => "Les principes sont globalement compris ; des écarts subsistent dans l'application méthodique et la justification des résultats",
            'disposition' => "Proposer un cas d'entreprise complémentaire sur « {$objective} » avec documents chiffrés et grille de correction",
        ],
        'Infographiste' => [
            'description' => "Démonstration visuelle puis atelier de création consacré à « {$objective} », à partir d'un brief, de références graphiques et d'une critique collective",
            'observation' => "Les intentions visuelles sont pertinentes ; la maîtrise technique et la cohérence des choix graphiques restent inégales selon les stagiaires",
            'disposition' => "Prévoir une production courte de consolidation sur « {$objective} » avec contraintes graphiques et retour individualisé",
        ],
        default => [
            'description' => "Mise en situation guidée portant sur « {$objective} », suivie d'une application pratique et d'une correction collective",
            'observation' => "L'objectif est globalement compris ; une consolidation pratique reste nécessaire pour certains stagiaires",
            'disposition' => "Prévoir un exercice complémentaire sur « {$objective} » avec critères de réussite explicites",
        ],
    };

    foreach (['description', 'observation', 'disposition'] as $type) {
        if ($row[$type] === '') {
            $row[$type] = normalize_text($context[$type]);
        }
    }
    $row['source'] = 'generated';
    return $row;
}

$rows = [];
$invalidRows = [];
$sourceStats = [];
$sourceHashes = [];
$completedPartial = 0;
$sourceSequenceMaps = [];

foreach ($sources as $filename => $sourceConfig) {
    $filiere = $sourceConfig['filiere'];
    $path = ROOT_DIR . '/' . $filename;
    if (!is_file($path)) {
        throw new RuntimeException("Source introuvable : {$filename}");
    }
    $sourceHashes[] = hash_file('sha256', $path);
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException("Lecture impossible : {$filename}");
    }
    fgetcsv($handle, 0, ';');
    $line = 1;
    $accepted = 0;
    while (($record = fgetcsv($handle, 0, ';')) !== false) {
        $line++;
        if (count(array_filter($record, static fn($v) => trim((string) $v) !== '')) === 0) {
            continue;
        }
        $record = array_pad($record, 6, '');
        $unitId = filter_var(trim((string) $record[0]), FILTER_VALIDATE_INT);
        $rawSequenceId = filter_var(trim((string) $record[1]), FILTER_VALIDATE_INT);
        $content = array_map(
            static fn($value) => normalize_text((string) $value),
            array_slice($record, 2, 4)
        );
        if (!$unitId || !$rawSequenceId || $content[0] === '') {
            $invalidRows[] = ['file' => $filename, 'line' => $line, 'reason' => 'Unite, sequence ou objectif invalide'];
            continue;
        }
        if (!isset($sourceSequenceMaps[$filename][$unitId][$rawSequenceId])) {
            $position = count($sourceSequenceMaps[$filename][$unitId] ?? []);
            $sequenceId = $schemaSequencesByUnit[(int) $unitId][$position] ?? null;
            if ($sequenceId === null) {
                $invalidRows[] = [
                    'file' => $filename,
                    'line' => $line,
                    'reason' => 'Sequence CSV sans correspondance dans le referentiel SQL',
                ];
                continue;
            }
            $sourceSequenceMaps[$filename][$unitId][$rawSequenceId] = $sequenceId;
        }
        $sequenceId = $sourceSequenceMaps[$filename][$unitId][$rawSequenceId];
        $row = [
            'filiere' => $filiere,
            'unite_id' => (int) $unitId,
            'sequence_id' => (int) $sequenceId,
            'objectif' => $content[0],
            'description' => $content[1],
            'observation' => $content[2],
            'disposition' => $content[3],
            'source' => 'historical',
            'source_file' => $filename,
            'source_line' => $line,
        ];
        if (in_array('', array_slice($content, 1), true)) {
            $row = complete_partial_row($row);
            $completedPartial++;
        }
        $rows[] = $row;
        $accepted++;
    }
    fclose($handle);
    $sourceStats[$filename] = ['accepted' => $accepted];
}

$sequenceGroups = [];
foreach ($rows as $row) {
    $sequenceGroups[$row['sequence_id']][] = $row;
}

$generated = [];
foreach ($sequenceGroups as $group) {
    if (count($group) < 2) {
        $generated[] = generated_row($group[0]);
    }
}
$rows = array_merge($rows, $generated);

$uniqueChains = [];
$duplicates = 0;
foreach ($rows as $row) {
    $key = implode('|', [
        $row['sequence_id'],
        comparison_key($row['objectif']),
        comparison_key($row['description']),
        comparison_key($row['observation']),
        comparison_key($row['disposition']),
    ]);
    if (isset($uniqueChains[$key])) {
        $duplicates++;
        continue;
    }
    $uniqueChains[$key] = $row;
}
$rows = array_values($uniqueChains);

$types = ['objectif', 'description', 'observation', 'disposition'];
$uniqueByType = array_fill_keys($types, []);
$frequencyByType = array_fill_keys($types, []);
foreach ($rows as &$row) {
    foreach ($types as $type) {
        $row[$type . '_hash'] = content_hash($type, $row[$type]);
        $uniqueByType[$type][$row[$type . '_hash']] = $row[$type];
        $frequencyByType[$type][$row[$type . '_hash']] = ($frequencyByType[$type][$row[$type . '_hash']] ?? 0) + 1;
    }
}
unset($row);

$nearDuplicates = [];
$semanticOverlaps = [];
foreach ($sequenceGroups as $sequenceId => $group) {
    foreach ($types as $type) {
        $values = [];
        foreach ($group as $row) {
            $key = comparison_key($row[$type]);
            $values[$key] = $row[$type];
        }
        $keys = array_keys($values);
        for ($i = 0, $count = count($keys); $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $maxLength = max(strlen($keys[$i]), strlen($keys[$j]));
                if ($maxLength < 20) {
                    continue;
                }
                $similarity = 1 - (levenshtein($keys[$i], $keys[$j]) / $maxLength);
                if ($similarity >= 0.90) {
                    $nearDuplicates[] = [
                        'sequence_id' => $sequenceId,
                        'type' => $type,
                        'similarity' => round($similarity, 3),
                        'a' => $values[$keys[$i]],
                        'b' => $values[$keys[$j]],
                    ];
                } else {
                    $semanticSimilarity = jaccard_similarity($values[$keys[$i]], $values[$keys[$j]]);
                    if ($semanticSimilarity >= 0.65) {
                        $semanticOverlaps[] = [
                            'sequence_id' => $sequenceId,
                            'type' => $type,
                            'similarity' => round($semanticSimilarity, 3),
                            'a' => $values[$keys[$i]],
                            'b' => $values[$keys[$j]],
                        ];
                    }
                }
            }
        }
    }
}

usort($rows, static fn($a, $b) => [$a['filiere'], $a['unite_id'], $a['sequence_id'], $a['objectif']]
    <=> [$b['filiere'], $b['unite_id'], $b['sequence_id'], $b['objectif']]);

$hierarchy = [];
foreach ($rows as $row) {
    $filiereKey = $row['filiere'];
    $unitKey = (string) $row['unite_id'];
    $sequenceKey = (string) $row['sequence_id'];
    $hierarchy[$filiereKey]['unites'][$unitKey]['sequences'][$sequenceKey]['objectifs'][] = [
        'texte' => $row['objectif'],
        'source' => $row['source'],
        'descriptions' => [[
            'texte' => $row['description'],
            'source' => $row['source'],
            'observations' => [[
                'texte' => $row['observation'],
                'source' => $row['source'],
                'dispositions' => [[
                    'texte' => $row['disposition'],
                    'source' => $row['source'],
                ]],
            ]],
        ]],
    ];
}

$jsonFlags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
file_put_contents(DATA_DIR . '/recommendation_knowledge_base.json', json_encode([
    'version' => 1,
    'generated_at' => date(DATE_ATOM),
    'filieres' => $hierarchy,
], $jsonFlags) . PHP_EOL);
file_put_contents(DATA_DIR . '/recommendation_rows.json', json_encode($rows, $jsonFlags) . PHP_EOL);

$sql = [];
$sql[] = '-- Donnees generees automatiquement par tools/build_recommendation_data.php';
$sql[] = '-- Executer apres database/migrations/001_recommendation_engine.sql';
$sql[] = 'SET NAMES utf8mb4;';
$sql[] = 'START TRANSACTION;';
$sql[] = "DELETE FROM recommendation_links WHERE source IN ('historical', 'generated');";
$sql[] = "DELETE FROM recommendation_sequence_contents WHERE source IN ('historical', 'generated');";
$sql[] = "DELETE FROM recommendation_contents WHERE source IN ('historical', 'generated');";
$sql[] = 'DROP TEMPORARY TABLE IF EXISTS recommendation_import_rows;';
$sql[] = 'CREATE TEMPORARY TABLE recommendation_import_rows (';
$sql[] = ' sequence_id INT NOT NULL, filiere VARCHAR(255) NOT NULL, unite_id INT NOT NULL,';
$sql[] = ' objectif TEXT NOT NULL, objectif_hash CHAR(64) NOT NULL, description TEXT NOT NULL, description_hash CHAR(64) NOT NULL,';
$sql[] = ' observation TEXT NOT NULL, observation_hash CHAR(64) NOT NULL, disposition TEXT NOT NULL, disposition_hash CHAR(64) NOT NULL,';
$sql[] = " source ENUM('historical','generated') NOT NULL";
$sql[] = ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

foreach (array_chunk($rows, 100) as $chunk) {
    $values = [];
    foreach ($chunk as $row) {
        $values[] = '(' . implode(', ', [
            $row['sequence_id'], sql_string($row['filiere']), $row['unite_id'],
            sql_string($row['objectif']), sql_string($row['objectif_hash']),
            sql_string($row['description']), sql_string($row['description_hash']),
            sql_string($row['observation']), sql_string($row['observation_hash']),
            sql_string($row['disposition']), sql_string($row['disposition_hash']),
            sql_string($row['source']),
        ]) . ')';
    }
    $sql[] = 'INSERT INTO recommendation_import_rows VALUES' . PHP_EOL . implode(',' . PHP_EOL, $values) . ';';
}

foreach ($types as $type) {
    $sql[] = "
INSERT INTO recommendation_contents
    (content_type, content_text, normalized_text, normalized_hash, source, occurrence_count)
SELECT " . sql_string($type) . ", MIN({$type}), MIN({$type}), {$type}_hash,
       IF(SUM(source = 'historical') > 0, 'historical', 'generated'), COUNT(*)
FROM recommendation_import_rows
GROUP BY {$type}_hash;";
}

$unionParts = [];
foreach ($types as $type) {
    $unionParts[] = "SELECT sequence_id, " . sql_string($type) . " AS content_type, {$type}_hash AS content_hash,
        IF(SUM(source = 'historical') > 0, 'historical', 'generated') AS source, COUNT(*) AS occurrences
        FROM recommendation_import_rows GROUP BY sequence_id, {$type}_hash";
}
$sql[] = "
INSERT INTO recommendation_sequence_contents
    (sequence_id, content_id, source, occurrence_count, base_score)
SELECT expanded.sequence_id, contents.id, expanded.source, expanded.occurrences,
       IF(expanded.source = 'historical', 1.0000, 0.6500)
FROM (" . implode(PHP_EOL . ' UNION ALL ' . PHP_EOL, $unionParts) . ") expanded
INNER JOIN recommendation_contents contents
    ON contents.content_type = expanded.content_type
   AND contents.normalized_hash = expanded.content_hash;";

$pairs = [
    ['objectif', 'description'],
    ['description', 'observation'],
    ['observation', 'disposition'],
];
foreach ($pairs as [$parent, $child]) {
    $sql[] = "
INSERT INTO recommendation_links
    (sequence_id, parent_content_id, child_content_id, occurrence_count, confidence_score, source)
SELECT rows.sequence_id, parent.id, child.id, COUNT(*),
       IF(SUM(rows.source = 'historical') > 0, 1.0000, 0.6500),
       IF(SUM(rows.source = 'historical') > 0, 'historical', 'generated')
FROM recommendation_import_rows rows
INNER JOIN recommendation_contents parent
    ON parent.content_type = " . sql_string($parent) . "
   AND parent.normalized_hash = rows.{$parent}_hash
INNER JOIN recommendation_contents child
    ON child.content_type = " . sql_string($child) . "
   AND child.normalized_hash = rows.{$child}_hash
GROUP BY rows.sequence_id, parent.id, child.id;";
}

$combinedHash = hash('sha256', implode('|', $sourceHashes));
$details = json_encode(['sources' => array_keys($sources), 'generated_rows' => count($generated)], JSON_UNESCAPED_UNICODE);
$sql[] = sprintf(
    "INSERT INTO recommendation_import_batches
    (source_name, source_sha256, imported_rows, rejected_rows, status, details, completed_at)
VALUES ('CSV historiques consolides', '%s', %d, %d, 'completed', %s, NOW())
ON DUPLICATE KEY UPDATE imported_rows = VALUES(imported_rows), rejected_rows = VALUES(rejected_rows),
status = 'completed', details = VALUES(details), completed_at = NOW();",
    $combinedHash,
    count($rows),
    count($invalidRows),
    sql_string($details ?: '{}')
);
$sql[] = 'DROP TEMPORARY TABLE recommendation_import_rows;';
$sql[] = 'COMMIT;';
file_put_contents(SEED_DIR . '/001_recommendation_knowledge_base.sql', implode(PHP_EOL, $sql) . PHP_EOL);

$historicalCount = count(array_filter($rows, static fn($row) => $row['source'] === 'historical'));
$generatedCount = count($rows) - $historicalCount;
$units = array_unique(array_column($rows, 'unite_id'));
$sequences = array_unique(array_column($rows, 'sequence_id'));

$statistics = [
    '# Rapport statistique du moteur de recommandation',
    '',
    'Généré le ' . date('Y-m-d H:i:s') . '.',
    '',
    '## Vue générale',
    '',
    '| Indicateur | Valeur |',
    '|---|---:|',
    '| Lignes historiques retenues | ' . $historicalCount . ' |',
    '| Lignes générées pour faible couverture | ' . $generatedCount . ' |',
    '| Lignes partielles complétées | ' . $completedPartial . ' |',
    '| Chaînes dupliquées supprimées | ' . $duplicates . ' |',
    '| Lignes rejetées | ' . count($invalidRows) . ' |',
    '| Filières | ' . count($sources) . ' |',
    '| Unités couvertes | ' . count($units) . ' |',
    '| Séquences couvertes | ' . count($sequences) . ' |',
    '| Quasi-doublons détectés | ' . count($nearDuplicates) . ' |',
    '| Recouvrements sémantiques détectés | ' . count($semanticOverlaps) . ' |',
    '',
    '## Volumétrie par source',
    '',
    '| Source | Lignes acceptées |',
    '|---|---:|',
];
foreach ($sourceStats as $file => $stats) {
    $statistics[] = '| ' . str_replace('|', '\|', $file) . ' | ' . $stats['accepted'] . ' |';
}
$statistics[] = '';
$statistics[] = '## Contenus canoniques';
$statistics[] = '';
$statistics[] = '| Type | Valeurs uniques | Occurrences | Taux de réutilisation |';
$statistics[] = '|---|---:|---:|---:|';
foreach ($types as $type) {
    $unique = count($uniqueByType[$type]);
    $reuse = count($rows) > 0 ? (1 - $unique / count($rows)) * 100 : 0;
    $statistics[] = sprintf('| %s | %d | %d | %.1f %% |', ucfirst($type), $unique, count($rows), $reuse);
}
file_put_contents(REPORT_DIR . '/rapport_statistiques.md', implode(PHP_EOL, $statistics) . PHP_EOL);

$quality = [
    '# Rapport de qualité des données',
    '',
    '## Règles appliquées',
    '',
    '- Encodage UTF-8 et espaces insécables normalisés.',
    '- Espaces, retours à la ligne et ponctuation harmonisés.',
    '- Majuscule initiale et ponctuation finale ajoutées lorsque nécessaire.',
    '- Apostrophes typographiques harmonisées.',
    '- Les IDs de séquence CSV sont remappés par unité et ordre d’apparition vers les IDs autoritatifs de `script_sp.sql`. Cette règle corrige notamment la dérive non constante du fichier Infographiste.',
    '- Déduplication exacte sur la chaîne séquence → objectif → description → observation → disposition.',
    '- Détection prudente des quasi-doublons à 90 % de similarité, sans fusion automatique.',
    '- Détection des recouvrements sémantiques par similarité de Jaccard des termes significatifs à partir de 65 %.',
    '',
    '## Enrichissement',
    '',
    $completedPartial . ' ligne(s) historiques partielles ont été complétées uniquement pour leurs champs manquants. '
        . count($generated) . ' séquence(s) ne disposaient que d’une seule chaîne exploitable et ont reçu une chaîne complémentaire. '
        . 'Toute chaîne enrichie porte la source `generated` et un score initial inférieur aux données entièrement historiques.',
    '',
    '## Anomalies',
    '',
    '- Lignes rejetées : ' . count($invalidRows) . '.',
    '- Quasi-doublons à vérifier : ' . count($nearDuplicates) . '.',
    '- Recouvrements sémantiques à vérifier : ' . count($semanticOverlaps) . '.',
    '- Les quasi-doublons ne sont pas fusionnés automatiquement afin de préserver les nuances pédagogiques.',
    '',
    '## Échantillon des quasi-doublons',
    '',
];
foreach (array_slice($nearDuplicates, 0, 20) as $item) {
    $quality[] = sprintf(
        '- Séquence %d, %s, similarité %.1f %% : « %s » / « %s »',
        $item['sequence_id'],
        $item['type'],
        $item['similarity'] * 100,
        $item['a'],
        $item['b']
    );
}
$quality[] = '';
$quality[] = '## Échantillon des recouvrements sémantiques';
$quality[] = '';
foreach (array_slice($semanticOverlaps, 0, 20) as $item) {
    $quality[] = sprintf(
        '- Séquence %d, %s, Jaccard %.1f %% : « %s » / « %s »',
        $item['sequence_id'],
        $item['type'],
        $item['similarity'] * 100,
        $item['a'],
        $item['b']
    );
}
file_put_contents(REPORT_DIR . '/rapport_qualite_donnees.md', implode(PHP_EOL, $quality) . PHP_EOL);

echo json_encode([
    'rows' => count($rows),
    'historical' => $historicalCount,
    'generated' => $generatedCount,
    'duplicates_removed' => $duplicates,
    'near_duplicates' => count($nearDuplicates),
    'semantic_overlaps' => count($semanticOverlaps),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

