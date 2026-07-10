<?php
declare(strict_types=1);

$isAjax = isset($_GET['ajax']) && $_GET['ajax'] === '1';

if ($isAjax) {
    require_once __DIR__ . '/auth_check.php';
    auth_require_role('directeur');
    include 'db.php';
} else {
    include 'page_directeur.php';
}

require_once __DIR__ . '/annees_scolaires.php';
require_once __DIR__ . '/includes/unite_helper.php';
require_once __DIR__ . '/includes/filiere_helper.php';

function etats_ensure_reporting_tables(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE IF NOT EXISTS configurations_dates_academiques_globales (
            id INT AUTO_INCREMENT PRIMARY KEY,
            annee_scolaire_id INT NOT NULL,
            semestre1_debut DATE NULL,
            semestre1_fin DATE NULL,
            semestre2_debut DATE NULL,
            semestre2_fin DATE NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_config_dates_annee (annee_scolaire_id),
            KEY idx_config_dates_globales_annee (annee_scolaire_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS configurations_examens_annees_formation (
            id INT AUTO_INCREMENT PRIMARY KEY,
            annee_scolaire_id INT NOT NULL,
            annee_formation INT NOT NULL,
            examen_semestre1_debut DATE NULL,
            examen_semestre1_fin DATE NULL,
            examen_semestre2_debut DATE NULL,
            examen_semestre2_fin DATE NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_config_examens_annee_formation (annee_scolaire_id, annee_formation),
            KEY idx_config_examens_annee (annee_scolaire_id),
            KEY idx_config_examens_formation (annee_formation)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

}

function etats_bind(mysqli_stmt $stmt, string $types, array &$params): void
{
    if ($types === '') {
        return;
    }

    $bindParams = [$types];
    foreach ($params as $key => $value) {
        $bindParams[] = &$params[$key];
    }
    call_user_func_array([$stmt, 'bind_param'], $bindParams);
}

function etats_fetch_all(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    etats_bind($stmt, $types, $params);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();

    return $rows;
}

function etats_scalar(mysqli $conn, string $sql, string $types = '', array $params = [])
{
    $row = etats_fetch_all($conn, $sql, $types, $params)[0] ?? [];
    return $row['total'] ?? 0;
}

function etats_first_id(array $rows): int
{
    return $rows ? (int) $rows[0]['id'] : 0;
}

function etats_selected_id(array $rows, $requested): int
{
    $requested = filter_var($requested, FILTER_VALIDATE_INT);
    foreach ($rows as $row) {
        if ($requested && (int) $row['id'] === (int) $requested) {
            return (int) $row['id'];
        }
    }

    return etats_first_id($rows);
}

function etats_selected_row(array $rows, int $selectedId): array
{
    foreach ($rows as $row) {
        if ((int) ($row['id'] ?? 0) === $selectedId) {
            return $row;
        }
    }

    return [];
}

function etats_date_or_null($value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    $timestamp = strtotime($value);
    return $timestamp ? date('Y-m-d', $timestamp) : null;
}

function etats_format_date(?string $date): string
{
    if (!$date) {
        return '-';
    }

    $timestamp = strtotime($date);
    return $timestamp ? date('d/m/Y', $timestamp) : '-';
}

function etats_format_period(?string $start, ?string $end): string
{
    if (!$start && !$end) {
        return '-';
    }

    return etats_format_date($start) . ' - ' . etats_format_date($end);
}

function etats_format_summary_period(?string $start, ?string $end): string
{
    if (!$start && !$end) {
        return '-';
    }

    return etats_format_date($start) . ' → ' . etats_format_date($end);
}

function etats_filieres(mysqli $conn): array
{
    return filiere_options_by_annee_formation($conn, 0);
}

function etats_annees_formation_for_filiere(mysqli $conn, array $filiere): array
{
    $duree = filiere_normalize_annee_formation($filiere['annee_formation'] ?? 1);
    $filiereId = (int) ($filiere['id'] ?? 0);
    $maxUniteYear = 0;
    if ($filiereId > 0) {
        $maxUniteYear = (int) etats_scalar(
            $conn,
            "SELECT MAX(COALESCE(annee_formation, 2)) AS total
             FROM unites_de_formation
             WHERE filiere_id = ?",
            'i',
            [$filiereId]
        );
    }

    $duree = max(1, $duree, $maxUniteYear);
    $rows = [];

    for ($annee = 1; $annee <= $duree; $annee++) {
        $rows[] = [
            'id' => $annee,
            'label' => unite_annee_formation_label($annee),
            'display_label' => unite_annee_formation_label($annee),
        ];
    }

    return $rows;
}

function etats_planning_title(string $filiereNom, string $anneeScolaire): string
{
    return sprintf(
        'Planning prévisionnel de réalisation des unités de formation du programme de la filière "%s" - Année scolaire : %s',
        trim($filiereNom),
        trim($anneeScolaire)
    );
}

function etats_calendar(mysqli $conn, int $filiereId, int $anneeId, int $anneeFormation): array
{
    $academic = etats_fetch_all(
        $conn,
        "SELECT semestre1_debut, semestre1_fin, semestre2_debut, semestre2_fin
         FROM configurations_dates_academiques_globales
         WHERE annee_scolaire_id = ?
         LIMIT 1",
        'i',
        [$anneeId]
    )[0] ?? [];

    $examens = etats_fetch_all(
        $conn,
        "SELECT examen_semestre1_debut, examen_semestre1_fin,
                examen_semestre2_debut, examen_semestre2_fin
         FROM configurations_examens_annees_formation
         WHERE annee_scolaire_id = ? AND annee_formation = ?
         LIMIT 1",
        'ii',
        [$anneeId, $anneeFormation]
    )[0] ?? [];

    return [
        'semestre1_debut' => (string) ($academic['semestre1_debut'] ?? ''),
        'semestre1_fin' => (string) ($academic['semestre1_fin'] ?? ''),
        'semestre2_debut' => (string) ($academic['semestre2_debut'] ?? ''),
        'semestre2_fin' => (string) ($academic['semestre2_fin'] ?? ''),
        'examen_semestre1_debut' => (string) ($examens['examen_semestre1_debut'] ?? ''),
        'examen_semestre1_fin' => (string) ($examens['examen_semestre1_fin'] ?? ''),
        'examen_semestre2_debut' => (string) ($examens['examen_semestre2_debut'] ?? ''),
        'examen_semestre2_fin' => (string) ($examens['examen_semestre2_fin'] ?? ''),
    ];
}

function etats_units(mysqli $conn, int $filiereId, int $anneeId, int $anneeFormation): array
{
    return etats_fetch_all(
        $conn,
        "SELECT
                uf.id,
                uf.intitule,
                COALESCE(uf.annee_formation, 2) AS annee_formation,
                COALESCE(uf.semestre, 1) AS semestre,
                COALESCE(uf.type_unite, 'pedagogique') AS type_unite,
                COALESCE(uf.masse_horaire, 0) AS masse_horaire,
                COALESCE(SUM(CASE WHEN COALESCE(s.valide_par_directeur, 0) = 1 THEN GREATEST(sp.heures_reelles, 0) ELSE 0 END), 0) AS heures_realisees,
                MAX(CASE WHEN COALESCE(sp.controle_continu, 0) = 1 THEN 1 ELSE 0 END) AS has_controle,
                COUNT(DISTINCT CASE WHEN COALESCE(sp.controle_continu, 0) = 1 THEN sp.id END) AS controle_count
         FROM unites_de_formation uf
         LEFT JOIN sequences_pedagogiques seq ON seq.unite_id = uf.id
         LEFT JOIN seances_pedagogiques sp ON sp.sequence_id = seq.id AND sp.annee_scolaire_id = ?
         LEFT JOIN suivi_pedagogique s ON s.seance_id = sp.id
         WHERE uf.filiere_id = ?
           AND COALESCE(uf.annee_formation, 2) = ?
         GROUP BY uf.id, uf.intitule, uf.annee_formation, uf.semestre, uf.type_unite, uf.masse_horaire
         ORDER BY COALESCE(uf.semestre, 1), uf.id, uf.intitule",
        'iii',
        [$anneeId, $filiereId, $anneeFormation]
    );
}

function etats_stage_unit_summary(mysqli $conn, int $filiereId, int $anneeId, int $anneeFormation): array
{
    $rows = etats_fetch_all(
        $conn,
        "SELECT
            COALESCE(SUM(stage_units.masse_horaire), 0) AS masse_horaire,
            MIN(stage_units.date_stage) AS date_stage,
            GROUP_CONCAT(DISTINCT NULLIF(stage_units.encadrant, '') ORDER BY stage_units.encadrant SEPARATOR ', ') AS encadrant
         FROM (
            SELECT
                uf.id,
                COALESCE(uf.masse_horaire, 0) AS masse_horaire,
                COALESCE(fo.nom, '-') AS encadrant,
                MIN(CASE WHEN COALESCE(s.valide_par_directeur, 0) = 1 THEN sp.date_seance END) AS date_stage
            FROM unites_de_formation uf
            LEFT JOIN formateurs fo ON fo.id = uf.formateur_id
            LEFT JOIN sequences_pedagogiques seq ON seq.unite_id = uf.id
            LEFT JOIN seances_pedagogiques sp ON sp.sequence_id = seq.id AND sp.annee_scolaire_id = ?
            LEFT JOIN suivi_pedagogique s ON s.seance_id = sp.id
            WHERE uf.filiere_id = ?
              AND COALESCE(uf.annee_formation, 2) = ?
              AND COALESCE(uf.type_unite, ?) = ?
            GROUP BY uf.id, uf.masse_horaire, fo.nom
         ) stage_units",
        'iiiss',
        [$anneeId, $filiereId, $anneeFormation, TYPE_UNITE_PEDAGOGIQUE, TYPE_UNITE_STAGE]
    );

    $row = $rows[0] ?? [];

    return [
        'date_stage' => etats_format_date($row['date_stage'] ?? null),
        'masse_horaire' => etats_format_number((float) ($row['masse_horaire'] ?? 0)) . ' h',
        'encadrant' => (string) (($row['encadrant'] ?? '') !== '' ? $row['encadrant'] : '-'),
    ];
}

function etats_control_markers(mysqli $conn, int $uniteId, int $anneeId, float $masseHoraire): array
{
    $rows = etats_fetch_all(
        $conn,
        "SELECT
            sp.id,
            sp.date_seance,
            seq.intitule AS sequence_intitule,
            s.heures_cumulees,
            (
                SELECT COUNT(*)
                FROM seances_pedagogiques sp2
                INNER JOIN sequences_pedagogiques seq2 ON seq2.id = sp2.sequence_id
                WHERE seq2.unite_id = seq.unite_id
                  AND sp2.annee_scolaire_id = sp.annee_scolaire_id
                  AND (
                      sp2.date_seance < sp.date_seance
                      OR (sp2.date_seance = sp.date_seance AND sp2.id <= sp.id)
                  )
            ) AS numero_seance
         FROM seances_pedagogiques sp
         INNER JOIN sequences_pedagogiques seq ON seq.id = sp.sequence_id
         LEFT JOIN suivi_pedagogique s ON s.seance_id = sp.id
         WHERE seq.unite_id = ?
           AND sp.annee_scolaire_id = ?
           AND COALESCE(sp.controle_continu, 0) = 1
           AND s.heures_cumulees IS NOT NULL
         ORDER BY sp.date_seance, sp.id",
        'ii',
        [$uniteId, $anneeId]
    );

    $markers = [];
    foreach ($rows as $row) {
        $heuresCumulees = (float) ($row['heures_cumulees'] ?? 0);
        $position = $masseHoraire > 0 ? ($heuresCumulees / $masseHoraire) * 100 : 0;
        $progression = min(100, max(0, $position));
        $markers[] = [
            'position' => $progression,
            'date' => etats_format_date($row['date_seance'] ?? null),
            'sequence' => (string) ($row['sequence_intitule'] ?? '-'),
            'numero_seance' => (int) ($row['numero_seance'] ?? 0),
            'progression' => etats_format_number($progression) . ' %',
        ];
    }

    return $markers;
}

function etats_format_number(float $value): string
{
    return rtrim(rtrim(number_format($value, 2, ',', ' '), '0'), ',');
}

function etats_unit_payload(array $unit): array
{
    $masse = (float) ($unit['masse_horaire'] ?? 0);
    $realise = (float) ($unit['heures_realisees'] ?? 0);
    $taux = $masse > 0 ? max(0, ($realise / $masse) * 100) : 0;

    return [
        'id' => (int) $unit['id'],
        'unite' => (string) $unit['intitule'],
        'annee_formation' => unite_normalize_annee_formation($unit['annee_formation'] ?? 2),
        'annee_formation_label' => unite_annee_formation_label($unit['annee_formation'] ?? 2),
        'semestre' => unite_normalize_semestre($unit['semestre'] ?? 1),
        'semestre_label' => unite_semestre_label($unit['semestre'] ?? 1),
        'type_unite' => unite_normalize_type($unit['type_unite'] ?? TYPE_UNITE_PEDAGOGIQUE),
        'masse_horaire' => $masse,
        'heures_realisees' => $realise,
        'progression' => $taux,
        'bar' => min(100, $taux),
        'taux_realisation' => $taux,
        'controle_continu' => (int) ($unit['has_controle'] ?? 0) === 1,
        'controle_count' => (int) ($unit['controle_count'] ?? 0),
        'markers' => $unit['markers'] ?? [],
    ];
}

function etats_dashboard_data(mysqli $conn, $requestedYearId = null, $requestedFiliereId = null, $requestedAnneeFormation = null): array
{
    $annees = annee_scolaire_options($conn);
    $filieres = etats_filieres($conn);

    $anneeId = annee_scolaire_selected_id($conn, $requestedYearId);
    if (!$anneeId && $annees) {
        $anneeId = (int) $annees[0]['id'];
    }
    $filiereId = etats_selected_id($filieres, $requestedFiliereId);
    $selectedFiliere = etats_selected_row($filieres, $filiereId);
    $selectedAnnee = etats_selected_row($annees, $anneeId);
    $anneesFormation = etats_annees_formation_for_filiere($conn, $selectedFiliere);
    $anneeFormation = unite_normalize_annee_formation($requestedAnneeFormation ?? 0);
    $anneeFormationIds = array_map(static function (array $row): int {
        return (int) $row['id'];
    }, $anneesFormation);
    if (!in_array($anneeFormation, $anneeFormationIds, true)) {
        $anneeFormation = $anneeFormationIds[0] ?? 1;
    }

    $calendar = $filiereId > 0 && $anneeId > 0 ? etats_calendar($conn, $filiereId, $anneeId, $anneeFormation) : [
        'semestre1_debut' => '',
        'semestre1_fin' => '',
        'semestre2_debut' => '',
        'semestre2_fin' => '',
        'examen_semestre1_debut' => '',
        'examen_semestre1_fin' => '',
        'examen_semestre2_debut' => '',
        'examen_semestre2_fin' => '',
    ];
    $units = $filiereId > 0 && $anneeId > 0 ? etats_units($conn, $filiereId, $anneeId, $anneeFormation) : [];
	    $stageSummary = $filiereId > 0 && $anneeId > 0
	        ? etats_stage_unit_summary($conn, $filiereId, $anneeId, $anneeFormation)
	        : ['date_stage' => '-', 'masse_horaire' => '0 h', 'encadrant' => '-'];
    foreach ($units as $key => $unit) {
        $units[$key]['markers'] = etats_control_markers(
            $conn,
            (int) $unit['id'],
            $anneeId,
            (float) ($unit['masse_horaire'] ?? 0)
        );
    }
    $unitRows = array_map(static function (array $unit): array {
        return etats_unit_payload($unit);
    }, $units);

    $totalMasse = 0.0;
    $totalRealise = 0.0;
    foreach ($units as $unit) {
        $totalMasse += (float) ($unit['masse_horaire'] ?? 0);
        $totalRealise += (float) ($unit['heures_realisees'] ?? 0);
    }
    $progressionGlobale = $totalMasse > 0 ? max(0, ($totalRealise / $totalMasse) * 100) : 0;

    $controleCount = $filiereId > 0 && $anneeId > 0
        ? (int) etats_scalar(
            $conn,
            "SELECT COUNT(DISTINCT uf.id) AS total
             FROM unites_de_formation uf
             INNER JOIN sequences_pedagogiques seq ON seq.unite_id = uf.id
             INNER JOIN seances_pedagogiques sp ON sp.sequence_id = seq.id
             WHERE uf.filiere_id = ?
               AND COALESCE(uf.annee_formation, 2) = ?
               AND sp.annee_scolaire_id = ?
               AND COALESCE(sp.controle_continu, 0) = 1",
            'iii',
            [$filiereId, $anneeFormation, $anneeId]
        )
        : 0;

    $examCount = 0;
    if (($calendar['examen_semestre1_debut'] ?? '') !== '' || ($calendar['examen_semestre1_fin'] ?? '') !== '') {
        $examCount++;
    }
    if (($calendar['examen_semestre2_debut'] ?? '') !== '' || ($calendar['examen_semestre2_fin'] ?? '') !== '') {
        $examCount++;
    }

    return [
        'filters' => [
            'annees_scolaires' => $annees,
            'annees_formation' => $anneesFormation,
            'annee_formation' => $anneeFormation,
            'filieres' => $filieres,
            'annee_scolaire_id' => $anneeId,
            'filiere_id' => $filiereId,
        ],
        'kpis' => [
            'unites' => count($units),
            'progression_globale' => $progressionGlobale,
            'controle_continu' => $controleCount,
            'examens_programmes' => $examCount,
        ],
        'global_progression' => [
            'progression' => $progressionGlobale,
            'bar' => min(100, $progressionGlobale),
            'markers' => [],
        ],
        'summary' => [
            'masse_horaire_totale' => $totalMasse,
            'masse_horaire_realisee' => $totalRealise,
            'taux_global' => $progressionGlobale,
        ],
        'units' => $unitRows,
        'calendar' => $calendar,
        'calendar_summary' => [
            'examens_s1' => etats_format_summary_period($calendar['examen_semestre1_debut'] ?: null, $calendar['examen_semestre1_fin'] ?: null),
            'examens_s2' => etats_format_summary_period($calendar['examen_semestre2_debut'] ?: null, $calendar['examen_semestre2_fin'] ?: null),
            'stage_date' => $stageSummary['date_stage'],
            'stage_masse_horaire' => $stageSummary['masse_horaire'],
            'stage_encadrant' => $stageSummary['encadrant'] ?? '-',
        ],
        'planning_title' => etats_planning_title(
            (string) ($selectedFiliere['label'] ?? $selectedFiliere['nom'] ?? ''),
            (string) ($selectedAnnee['label'] ?? $selectedAnnee['libelle'] ?? '')
        ),
    ];
}

etats_ensure_reporting_tables($conn);
unite_ensure_columns($conn);

$data = etats_dashboard_data(
    $conn,
    $_GET['annee_scolaire_id'] ?? null,
    $_GET['filiere_id'] ?? null,
    $_GET['annee_formation'] ?? null
);

if ($isAjax) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit();
}
?>

<div class="dashboard-page fade-in edition-etats-page">
    <div class="page-header d-flex flex-column flex-lg-row justify-content-between align-items-lg-end mb-4">
        <div>
            <h1 class="page-title">Édition des États</h1>
            <p class="page-subtitle mb-0">Suivi pédagogique des filières et centre de contrôle des prochains rapports.</p>
        </div>
        <span class="badge-epim-info mt-3 mt-lg-0">Module reporting</span>
    </div>

    <section class="epim-card filter-panel no-hover mb-4">
        <div class="filter-panel-header">
            <span class="badge-epim-info" id="editionEtatsState" hidden>Chargement</span>
        </div>
        <form id="editionEtatsFilters" class="dashboard-filters">
            <div class="form-row">
                <div class="form-group col-lg-4">
                    <label for="annee_scolaire_id">Année scolaire</label>
                    <select class="form-control" id="annee_scolaire_id" name="annee_scolaire_id"></select>
                </div>
                <div class="form-group col-lg-4">
                    <label for="filiere_id">Filière</label>
                    <select class="form-control" id="filiere_id" name="filiere_id"></select>
                </div>
                <div class="form-group col-lg-4">
                    <label for="annee_formation">Année de formation</label>
                    <select class="form-control" id="annee_formation" name="annee_formation"></select>
                </div>
            </div>
        </form>
    </section>

    <section class="mb-4">
        <div class="section-header">
            <div>
                <h2>Vue globale du suivi pédagogique</h2>
                <p>Indicateurs calculés selon l'année scolaire et la filière sélectionnées.</p>
            </div>
        </div>

        <div class="director-kpi-grid edition-etats-kpi-grid" id="editionEtatsKpis" aria-live="polite">
            <article class="stat-card bg-blue director-kpi-card">
                <i class="fas fa-book"></i>
                <div class="stat-value" data-kpi="unites">--</div>
                <div class="stat-label">Nombre d'unités</div>
            </article>
            <article class="stat-card bg-orange director-kpi-card">
                <i class="fas fa-chart-line"></i>
                <div class="stat-value" data-kpi="progression_globale">--</div>
                <div class="stat-label">Progression globale</div>
            </article>
            <article class="stat-card bg-dark director-kpi-card">
                <i class="fas fa-clipboard-check"></i>
                <div class="stat-value" data-kpi="controle_continu">--</div>
                <div class="stat-label">Unités avec contrôle continu</div>
            </article>
            <article class="stat-card bg-blue director-kpi-card">
                <i class="fas fa-calendar-check"></i>
                <div class="stat-value" data-kpi="examens_programmes">--</div>
                <div class="stat-label">Examens programmés</div>
            </article>
        </div>

        <section class="epim-card no-hover mt-4">
            <div class="section-header">
                <div>
                    <h2>Tableau de suivi pédagogique</h2>
                    <p>Vue synthétique par unité de formation.</p>
                </div>
            </div>
            <div class="edition-global-progress mb-4" id="globalProgressWrap"></div>
            <div class="table-responsive epim-data-table" id="edition_etats_table_wrap">
		                <table class="table epim-table edition-etats-table table-borderless mb-0">
		                    <colgroup>
		                        <col class="edition-col-unite">
		                        <col class="edition-col-progression">
		                        <col class="edition-col-realisee">
		                        <col class="edition-col-taux">
		                        <col class="edition-col-controle">
		                    </colgroup>
		                    <thead>
		                        <tr>
		                            <th>Unité</th>
		                            <th class="text-center">Progression</th>
		                            <th class="text-center"><span class="edition-stacked-heading">Masse<br>horaire<br>réalisée</span></th>
		                            <th class="text-center"><span class="edition-stacked-heading">Taux de<br>réalisation</span></th>
		                            <th class="text-center"><span class="edition-stacked-heading">Contrôle<br>continu</span></th>
		                        </tr>
		                    </thead>
		                    <tbody id="editionEtatsRows">
		                        <tr>
		                            <td colspan="5" class="text-center text-muted py-4">Chargement des données...</td>
		                        </tr>
		                    </tbody>
		                    <tfoot id="editionEtatsSummary">
		                        <tr>
		                            <td colspan="5" class="text-muted text-center py-3">Synthèse en cours de chargement...</td>
		                        </tr>
		                    </tfoot>
		                </table>
            </div>
            <div class="edition-progress-legend small text-muted mt-3">
                <span><span class="edition-marker-symbol">▲</span> Contrôle continu</span>
                <span><span class="edition-legend-done"></span> Partie réalisée</span>
                <span><span class="edition-legend-rest"></span> Partie restante</span>
            </div>
            <div class="edition-calendar-summary mt-4" id="editionCalendarSummary"></div>
        </section>
    </section>

    <section class="epim-card no-hover p-4">
        <div class="section-header">
            <div>
                <h2>Export des rapports</h2>
                <p>Les exports utilisent les filtres actuellement sélectionnés.</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-outline-epim" id="editionEtatsExcelExport">
                    <i class="fas fa-file-excel mr-2"></i>Exporter Excel
                </button>
            </div>
        </div>
    </section>
</div>

<style>
    .edition-etats-kpi-grid {
        grid-template-columns: repeat(4, minmax(0, 1fr));
    }

	    .edition-etats-progress {
	        min-width: 320px;
	    }
	
	    .edition-progress-component {
	        min-width: 320px;
	    }
	
	    .edition-col-unite {
	        width: 31%;
	    }

		    .edition-col-progression {
		        width: 43%;
		    }

	    .edition-col-realisee {
	        width: 11%;
	    }
		
		    .edition-col-taux {
		        width: 9%;
		    }
	
	    .edition-col-controle {
	        width: 6%;
	    }

	    .edition-hours-value {
	        cursor: help;
	        white-space: nowrap;
	    }

	    .edition-stacked-heading {
	        display: inline-block;
	        line-height: 1.15;
	        text-align: center;
	        white-space: normal;
	    }

	    .edition-unit-title {
	        display: block;
	        max-width: 100%;
	        overflow: hidden;
	        text-overflow: ellipsis;
	        white-space: nowrap;
	    }

	    .edition-etats-table td:nth-child(n+3),
	    .edition-etats-table th:nth-child(n+3) {
	        text-align: center;
	    }

	    .edition-etats-table td:nth-child(2),
	    .edition-etats-table th:nth-child(2) {
	        text-align: center;
	    }

	    .edition-etats-table tfoot td {
	        background: #f7f9fc;
	        border-top: 2px solid #dfe7f1;
	    }

	    .edition-summary-row {
	        display: grid;
	        grid-template-columns: repeat(3, minmax(0, 1fr));
	        gap: 12px;
	        text-align: left;
	    }

	    .edition-summary-item {
	        padding: 10px 12px;
	        border: 1px solid #e3e9f2;
	        border-radius: 8px;
	        background: #fff;
	    }

	    .edition-summary-item span {
	        display: block;
	        color: #5f6f82;
	        font-size: .78rem;
	        font-weight: 700;
	        text-transform: uppercase;
	    }

	    .edition-summary-item strong {
	        display: block;
	        margin-top: 3px;
	        color: var(--epim-blue);
	        font-size: 1rem;
	    }

    .edition-progress-track {
        position: relative;
        height: 12px;
        overflow: visible;
        background: repeating-linear-gradient(
            90deg,
            #e8edf3 0,
            #e8edf3 8px,
            #f6f8fb 8px,
            #f6f8fb 14px
        );
        border-radius: 999px;
    }

    .edition-progress-track::before {
        content: "";
        position: absolute;
        top: -3px;
        bottom: -3px;
        left: 50%;
        width: 1px;
        background: rgba(16, 43, 67, .28);
        z-index: 1;
    }

    .edition-progress-track::after {
        content: "S1   S2";
        position: absolute;
        left: 0;
        right: 0;
        top: 14px;
        display: flex;
        justify-content: space-around;
        color: #7b8794;
        font-size: 10px;
        font-weight: 700;
        letter-spacing: 0;
        pointer-events: none;
    }

    .edition-progress-fill {
        position: absolute;
        top: 0;
        left: 0;
        height: 100%;
        border-radius: 999px;
        background: linear-gradient(135deg, var(--epim-blue), #1064c4);
    }

    .edition-progress-marker {
        position: absolute;
        top: -12px;
        transform: translateX(-50%);
        color: var(--epim-orange);
        font-size: 15px;
        line-height: 1;
        text-shadow: 0 1px 2px rgba(0, 0, 0, .18);
        cursor: help;
        z-index: 3;
    }

    .edition-progress-tooltip {
        position: absolute;
        left: 50%;
        bottom: calc(100% + 8px);
        transform: translateX(-50%);
        min-width: 220px;
        max-width: 280px;
        padding: 10px 12px;
        border-radius: 8px;
        background: var(--epim-text);
        color: #fff;
        font-size: 12px;
        line-height: 1.45;
        text-align: left;
        text-shadow: none;
        box-shadow: 0 10px 24px rgba(16, 43, 67, .22);
        opacity: 0;
        visibility: hidden;
        pointer-events: none;
        transition: opacity .18s ease, visibility .18s ease;
        white-space: normal;
    }

    .edition-progress-tooltip strong {
        display: block;
        margin-bottom: 6px;
        font-size: 13px;
    }

    .edition-progress-tooltip span {
        display: block;
    }

    .edition-progress-marker:hover .edition-progress-tooltip,
    .edition-progress-marker:focus .edition-progress-tooltip {
        opacity: 1;
        visibility: visible;
    }

    .edition-global-progress {
        max-width: 760px;
        margin-bottom: 30px !important;
    }

    .edition-global-progress .edition-progress-track::before,
    .edition-global-progress .edition-progress-track::after {
        content: none;
        display: none;
    }

    .edition-progress-legend {
        display: flex;
        flex-wrap: wrap;
        gap: 14px;
        align-items: center;
    }

    .edition-calendar-summary {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 16px;
    }

    .edition-calendar-summary-card {
        border: 1px solid #e3e9f2;
        border-radius: 8px;
        background: #fff;
        padding: 14px 16px;
    }

    .edition-calendar-summary-card h3 {
        color: #1f2d3d;
        font-size: .98rem;
        font-weight: 800;
        margin: 0 0 10px;
    }

    .edition-calendar-summary-line {
        display: flex;
        justify-content: space-between;
        gap: 12px;
        color: #5f6f82;
        font-size: .9rem;
        padding: 4px 0;
    }

    .edition-calendar-summary-line strong {
        color: #263238;
        white-space: nowrap;
    }

    .edition-marker-symbol {
        color: var(--epim-orange);
        font-weight: 700;
    }

    .edition-legend-done,
    .edition-legend-rest {
        display: inline-block;
        width: 28px;
        height: 8px;
        border-radius: 999px;
        vertical-align: middle;
        margin-right: 4px;
    }

    .edition-legend-done {
        background: linear-gradient(135deg, var(--epim-blue), #1064c4);
    }

    .edition-legend-rest {
        background: repeating-linear-gradient(90deg, #e8edf3 0, #e8edf3 8px, #f6f8fb 8px, #f6f8fb 14px);
    }

    @media (max-width: 1199.98px) {
        .edition-etats-kpi-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 575.98px) {
        .edition-etats-kpi-grid {
            grid-template-columns: 1fr;
        }

        .edition-calendar-summary {
            grid-template-columns: 1fr;
        }
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const initialData = <?php echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
    const stateBadge = document.getElementById('editionEtatsState');
    const yearSelect = document.getElementById('annee_scolaire_id');
	    const formationSelect = document.getElementById('annee_formation');
	    const filiereSelect = document.getElementById('filiere_id');
	    const rowsBody = document.getElementById('editionEtatsRows');
    const summaryBody = document.getElementById('editionEtatsSummary');
	    const globalProgressWrap = document.getElementById('globalProgressWrap');
    const calendarSummaryWrap = document.getElementById('editionCalendarSummary');
    const excelExportButton = document.getElementById('editionEtatsExcelExport');
    const planningTitle = document.getElementById('editionPlanningTitle');
    const formatNumber = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 2 });

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, function(char) {
            return ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            })[char];
        });
    }

    function setState(text, className, hidden) {
        stateBadge.className = className;
        stateBadge.textContent = text;
        stateBadge.hidden = !!hidden;
    }

    function fillSelect(select, options, selectedValue) {
        select.innerHTML = '';
        options.forEach(function(option) {
            const item = document.createElement('option');
            item.value = option.id;
            item.textContent = option.display_label || option.label;
            if (String(option.id) === String(selectedValue)) {
                item.selected = true;
            }
            select.appendChild(item);
        });
    }

    function setKpi(key, value) {
        const node = document.querySelector('[data-kpi="' + key + '"]');
        if (node) {
            node.textContent = value;
        }
    }

    function updateKpis(kpis) {
        setKpi('unites', formatNumber.format(kpis.unites || 0));
        setKpi('progression_globale', formatNumber.format(kpis.progression_globale || 0) + ' %');
        setKpi('controle_continu', formatNumber.format(kpis.controle_continu || 0));
        setKpi('examens_programmes', formatNumber.format(kpis.examens_programmes || 0));
    }

    function progressBarHtml(value, markers, label) {
        const normalizedValue = Math.max(0, Number(value || 0));
        const bar = Math.min(100, normalizedValue);
        const markerHtml = (markers || []).map(function(marker) {
            const position = Math.min(100, Math.max(0, Number(marker.position || 0)));
            return '<span class="edition-progress-marker" style="left:' + position + '%;" title="' + escapeHtml(marker.label || 'Contrôle continu') + '">▲</span>';
        }).join('');

        return '<div class="edition-progress-component">' +
            '<div class="d-flex justify-content-between small text-muted mb-1">' +
                '<span>' + escapeHtml(label || 'Progression') + '</span><strong>' + formatNumber.format(normalizedValue) + ' %</strong>' +
            '</div>' +
            '<div class="edition-progress-track">' +
                '<div class="edition-progress-fill" style="width:' + bar + '%;"></div>' +
                markerHtml +
            '</div>' +
        '</div>';
    }

    function semesterMarkerPosition(position, semestre) {
        const normalized = Math.min(100, Math.max(0, Number(position || 0)));
        if (Number(semestre) === 1) {
            return normalized / 2;
        }
        if (Number(semestre) === 2) {
            return 50 + (normalized / 2);
        }
        return normalized;
    }

    function semesterFillStyle(value, semestre) {
        const normalized = Math.min(100, Math.max(0, Number(value || 0)));
        if (Number(semestre) === 1) {
            return 'left:0;width:' + (normalized / 2) + '%;';
        }
        if (Number(semestre) === 2) {
            return 'left:50%;width:' + (normalized / 2) + '%;';
        }
        return 'left:0;width:' + normalized + '%;';
    }

	    function progressionContextTitle(row) {
	        return formatNumber.format(row.masse_horaire || 0) + ' h prévues\n' +
	            formatNumber.format(row.heures_realisees || 0) + ' h réalisées\n' +
	            formatNumber.format(row.taux_realisation || 0) + ' % de réalisation';
	    }

	    function progressBarWithTooltipsHtml(value, markers, label, semestre, contextTitle) {
	        const normalizedValue = Math.max(0, Number(value || 0));
	        const bar = Math.min(100, normalizedValue);
	        const fillStyle = semesterFillStyle(bar, semestre);
        const markerHtml = (markers || []).map(function(marker) {
            const position = semesterMarkerPosition(marker.position, semestre);
            const tooltip = '<span class="edition-progress-tooltip">' +
                '<strong>Date : ' + escapeHtml(marker.date || '-') + '</strong>' +
                '<span>Contrôle continu</span>' +
                '<span>Séquence : ' + escapeHtml(marker.sequence || '-') + '</span>' +
                '<span>Séance n°' + escapeHtml(marker.numero_seance || '-') + '</span>' +
                '<span>Progression : ' + escapeHtml(marker.progression || '-') + '</span>' +
            '</span>';
            return '<span class="edition-progress-marker" tabindex="0" style="left:' + position + '%;">▲' + tooltip + '</span>';
        }).join('');

	        return '<div class="edition-progress-component" title="' + escapeHtml(contextTitle || '') + '">' +
            '<div class="d-flex justify-content-between small text-muted mb-1">' +
                '<span>' + escapeHtml(label || 'Progression') + '</span><strong>' + formatNumber.format(normalizedValue) + ' %</strong>' +
            '</div>' +
            '<div class="edition-progress-track">' +
                '<div class="edition-progress-fill" style="' + fillStyle + '"></div>' +
                markerHtml +
            '</div>' +
        '</div>';
    }

	    function progressCell(row) {
	        return '<div class="edition-etats-progress">' + progressBarWithTooltipsHtml(row.progression, row.markers || [], 'Progression', row.semestre, progressionContextTitle(row)) + '</div>';
	    }

    function renderGlobalProgress(data) {
        const progress = data.global_progression || { progression: 0, markers: [] };
        globalProgressWrap.innerHTML = progressBarWithTooltipsHtml(progress.progression, progress.markers || [], 'Progression globale de la filière');
    }

    function renderCalendarSummary(summary) {
        const data = summary || {};
        calendarSummaryWrap.innerHTML =
            '<div class="edition-calendar-summary-card">' +
                '<h3>Période d\'examens</h3>' +
                '<div class="edition-calendar-summary-line"><strong>Semestre 1</strong><span>' + escapeHtml(data.examens_s1 || '-') + '</span></div>' +
                '<div class="edition-calendar-summary-line"><strong>Semestre 2</strong><span>' + escapeHtml(data.examens_s2 || '-') + '</span></div>' +
            '</div>' +
	            '<div class="edition-calendar-summary-card">' +
	                '<h3>Stage</h3>' +
	                '<div class="edition-calendar-summary-line"><strong>Date du stage</strong><span>' + escapeHtml(data.stage_date || '-') + '</span></div>' +
	                '<div class="edition-calendar-summary-line"><strong>Masse horaire</strong><span>' + escapeHtml(data.stage_masse_horaire || '-') + '</span></div>' +
	                '<div class="edition-calendar-summary-line"><strong>Encadrant</strong><span>' + escapeHtml(data.stage_encadrant || '-') + '</span></div>' +
	            '</div>';
    }

	    function renderSummary(summary) {
	        const data = summary || {};
	        summaryBody.innerHTML =
	            '<tr>' +
		                '<td colspan="5">' +
	                    '<div class="edition-summary-row">' +
	                        '<div class="edition-summary-item"><span>Masse horaire totale</span><strong>' + formatNumber.format(data.masse_horaire_totale || 0) + ' h</strong></div>' +
	                        '<div class="edition-summary-item"><span>Masse horaire réalisée</span><strong>' + formatNumber.format(data.masse_horaire_realisee || 0) + ' h</strong></div>' +
	                        '<div class="edition-summary-item"><span>Taux global</span><strong>' + formatNumber.format(data.taux_global || 0) + ' %</strong></div>' +
	                    '</div>' +
	                '</td>' +
	            '</tr>';
	    }

	    function renderRows(rows) {
	        if (!rows.length) {
	            rowsBody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4">Aucune unité trouvée pour ce contexte.</td></tr>';
	            if (window.EpimDataTables) {
	                window.EpimDataTables.refresh(document.getElementById('edition_etats_table_wrap'));
	            }
	            return;
	        }
	
	        rowsBody.innerHTML = rows.map(function(row) {
		            const unite = escapeHtml(row.unite);
		            const contextTitle = escapeHtml(progressionContextTitle(row));
		            return '<tr>' +
		                '<td><strong class="edition-unit-title" title="' + unite + '">' + unite + '</strong></td>' +
		                '<td class="text-center">' + progressCell(row) + '</td>' +
		                '<td class="text-center"><span class="edition-hours-value" title="' + contextTitle + '">' + formatNumber.format(row.heures_realisees || 0) + ' h</span></td>' +
		                '<td class="text-center">' + formatNumber.format(row.taux_realisation || 0) + ' %</td>' +
	                '<td class="text-center"><strong>' + formatNumber.format(row.controle_count || 0) + '</strong></td>' +
	            '</tr>';
	        }).join('');
	        if (window.EpimDataTables) {
	            window.EpimDataTables.refresh(document.getElementById('edition_etats_table_wrap'));
	        }
	    }

    function render(data, refreshFilters) {
        if (refreshFilters) {
            fillSelect(yearSelect, data.filters.annees_scolaires, data.filters.annee_scolaire_id);
            fillSelect(filiereSelect, data.filters.filieres, data.filters.filiere_id);
            fillSelect(formationSelect, data.filters.annees_formation || [], data.filters.annee_formation || 1);
        }
        if (planningTitle) {
            planningTitle.textContent = data.planning_title || 'Vue globale du suivi pédagogique';
        }
	        updateKpis(data.kpis);
	        renderGlobalProgress(data);
	        renderRows(data.units || []);
	        renderSummary(data.summary || {});
	        renderCalendarSummary(data.calendar_summary || {});
    }

    function buildFilterParams() {
        const params = new URLSearchParams();
        if (yearSelect.value) {
            params.set('annee_scolaire_id', yearSelect.value);
        }
        if (formationSelect.value) {
            params.set('annee_formation', formationSelect.value);
        }
        if (filiereSelect.value) {
            params.set('filiere_id', filiereSelect.value);
        }

        return params;
    }

    async function loadData(refreshFilters) {
        setState('Chargement', 'badge-epim-info', false);
        const params = buildFilterParams();
        params.set('ajax', '1');

        try {
            const response = await fetch('edition_etats.php?' + params.toString(), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            const payload = await response.json();
            if (!payload.success) {
                throw new Error(payload.message || 'Erreur');
            }
            render(payload.data, !!refreshFilters);
            setState('À jour', 'badge-epim-success', false);
        } catch (error) {
            setState('Erreur', 'badge-epim-danger', false);
            if (window.toastr) {
                toastr.error('Impossible de charger les états.');
            }
        }
    }

    async function exportExcel() {
        if (!excelExportButton) {
            return;
        }

        const params = buildFilterParams();
        if (!params.get('annee_scolaire_id') || !params.get('filiere_id')) {
            if (window.toastr) {
                toastr.warning('Veuillez sélectionner une année scolaire et une filière.');
            }
            return;
        }

        excelExportButton.disabled = true;
        setState('Export Excel', 'badge-epim-info', false);

        try {
            const response = await fetch('export_planning_excel.php?' + params.toString(), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const contentType = response.headers.get('content-type') || '';

            if (!response.ok || contentType.includes('application/json')) {
                let message = 'Impossible de générer le fichier Excel.';
                try {
                    const payload = await response.json();
                    message = payload.message || message;
                } catch (error) {}
                throw new Error(message);
            }

            const blob = await response.blob();
            const disposition = response.headers.get('content-disposition') || '';
            const match = disposition.match(/filename="([^"]+)"/i);
            const filename = match ? match[1] : 'planning_export.xlsx';
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            link.remove();
            URL.revokeObjectURL(url);

            setState('À jour', 'badge-epim-success', false);
        } catch (error) {
            setState('Erreur', 'badge-epim-danger', false);
            if (window.toastr) {
                toastr.error(error.message || 'Impossible de générer le fichier Excel.');
            }
        } finally {
            excelExportButton.disabled = false;
        }
    }

    yearSelect.addEventListener('change', function() { loadData(true); });
    formationSelect.addEventListener('change', function() { loadData(true); });
    filiereSelect.addEventListener('change', function() { loadData(true); });
    if (excelExportButton) {
        excelExportButton.addEventListener('click', exportExcel);
    }
    render(initialData, true);
    setState('À jour', 'badge-epim-success', false);
});
</script>

<?php include 'footer.php'; ?>
