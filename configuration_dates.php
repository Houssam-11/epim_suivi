<?php
declare(strict_types=1);

$isAjax = isset($_GET['ajax']) || isset($_POST['ajax']);

require_once __DIR__ . '/auth_check.php';
auth_require_role('directeur');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/annees_scolaires.php';
require_once __DIR__ . '/includes/filiere_helper.php';

function config_dates_ensure_tables(mysqli $conn): void
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
        CREATE TABLE IF NOT EXISTS configurations_dates_vacances_globales (
            id INT AUTO_INCREMENT PRIMARY KEY,
            configuration_id INT NOT NULL,
            nom VARCHAR(255) NULL,
            date_debut DATE NULL,
            date_fin DATE NULL,
            ordre INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_config_vacances_globales_configuration (configuration_id),
            CONSTRAINT fk_config_vacances_globales_configuration
                FOREIGN KEY (configuration_id)
                REFERENCES configurations_dates_academiques_globales(id)
                ON DELETE CASCADE
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

    config_dates_migrate_legacy_data($conn);
}

function config_dates_migrate_legacy_data(mysqli $conn): void
{
    filiere_ensure_columns($conn);

    $legacy = $conn->query("SHOW TABLES LIKE 'configurations_dates_academiques'");
    if ($legacy && $legacy->num_rows > 0) {
        $legacyColumns = $conn->query("
            SELECT column_name
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'configurations_dates_academiques'
        ");
        $columns = [];
        while ($legacyColumns && $column = $legacyColumns->fetch_assoc()) {
            $columns[$column['column_name']] = true;
        }

        $hasAcademicColumns = isset(
            $columns['annee_scolaire_id'],
            $columns['semestre1_debut'],
            $columns['semestre1_fin'],
            $columns['semestre2_debut'],
            $columns['semestre2_fin']
        );
        if ($hasAcademicColumns) {
            $conn->query("
                INSERT IGNORE INTO configurations_dates_academiques_globales (
                    annee_scolaire_id,
                    semestre1_debut,
                    semestre1_fin,
                    semestre2_debut,
                    semestre2_fin
                )
                SELECT
                    c.annee_scolaire_id,
                    MAX(c.semestre1_debut),
                    MAX(c.semestre1_fin),
                    MAX(c.semestre2_debut),
                    MAX(c.semestre2_fin)
                FROM configurations_dates_academiques c
                GROUP BY c.annee_scolaire_id
            ");
        }

        $hasExamColumns = isset(
            $columns['annee_scolaire_id'],
            $columns['filiere_id'],
            $columns['examen_semestre1'],
            $columns['examen_semestre2']
        );
        if ($hasExamColumns) {
            $conn->query("
                INSERT IGNORE INTO configurations_examens_annees_formation (
                    annee_scolaire_id,
                    annee_formation,
                    examen_semestre1_debut,
                    examen_semestre1_fin,
                    examen_semestre2_debut,
                    examen_semestre2_fin
                )
                SELECT
                    c.annee_scolaire_id,
                    COALESCE(f.annee_formation, 1),
                    MAX(c.examen_semestre1),
                    MAX(c.examen_semestre1),
                    MAX(c.examen_semestre2),
                    MAX(c.examen_semestre2)
                FROM configurations_dates_academiques c
                INNER JOIN filieres f ON f.id = c.filiere_id
                GROUP BY c.annee_scolaire_id, COALESCE(f.annee_formation, 1)
            ");
        }
    }

    $globalExamColumns = $conn->query("
        SELECT COUNT(*) AS total
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'configurations_dates_academiques_globales'
          AND column_name = 'examen_semestre1_debut'
    ");
    $hasGlobalExamColumns = $globalExamColumns && (int) (($globalExamColumns->fetch_assoc()['total'] ?? 0)) > 0;
    if ($hasGlobalExamColumns) {
        $conn->query("
            INSERT IGNORE INTO configurations_examens_annees_formation (
                annee_scolaire_id,
                annee_formation,
                examen_semestre1_debut,
                examen_semestre1_fin,
                examen_semestre2_debut,
                examen_semestre2_fin
            )
            SELECT
                g.annee_scolaire_id,
                formations.annee_formation,
                g.examen_semestre1_debut,
                g.examen_semestre1_fin,
                g.examen_semestre2_debut,
                g.examen_semestre2_fin
            FROM configurations_dates_academiques_globales g
            CROSS JOIN (
                SELECT DISTINCT COALESCE(annee_formation, 1) AS annee_formation
                FROM filieres
                WHERE COALESCE(annee_formation, 1) > 0
            ) formations
        ");
    }

    $vacancesLegacy = $conn->query("SHOW TABLES LIKE 'configurations_dates_vacances'");
    if (!$legacy || $legacy->num_rows === 0 || !$vacancesLegacy || $vacancesLegacy->num_rows === 0) {
        return;
    }

    $conn->query("
        INSERT INTO configurations_dates_vacances_globales (configuration_id, nom, date_debut, date_fin, ordre)
        SELECT g.id, v.nom, v.date_debut, v.date_fin, v.ordre
        FROM configurations_dates_vacances v
        INNER JOIN configurations_dates_academiques c ON c.id = v.configuration_id
        INNER JOIN configurations_dates_academiques_globales g ON g.annee_scolaire_id = c.annee_scolaire_id
        LEFT JOIN configurations_dates_vacances_globales existing
            ON existing.configuration_id = g.id
            AND COALESCE(existing.nom, '') = COALESCE(v.nom, '')
            AND COALESCE(existing.date_debut, '1000-01-01') = COALESCE(v.date_debut, '1000-01-01')
            AND COALESCE(existing.date_fin, '1000-01-01') = COALESCE(v.date_fin, '1000-01-01')
        WHERE existing.id IS NULL
    ");
}

function config_dates_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function config_dates_annees_formation(mysqli $conn): array
{
    $rows = filiere_annees_formation_presentes($conn);

    return array_values(array_filter($rows, static function (array $row): bool {
        return (int) ($row['id'] ?? 0) > 0;
    }));
}

function config_dates_selected_annee_formation(array $rows, $requested): int
{
    $requestedId = filter_var($requested, FILTER_VALIDATE_INT);
    if (!$requestedId && isset($_SESSION['configuration_dates_annee_formation'])) {
        $requestedId = filter_var($_SESSION['configuration_dates_annee_formation'], FILTER_VALIDATE_INT);
    }

    if ($requestedId) {
        foreach ($rows as $row) {
            if ((int) $row['id'] === (int) $requestedId) {
                $_SESSION['configuration_dates_annee_formation'] = (int) $requestedId;
                return (int) $requestedId;
            }
        }
    }

    $selected = isset($rows[0]['id']) ? (int) $rows[0]['id'] : 0;
    if ($selected > 0) {
        $_SESSION['configuration_dates_annee_formation'] = $selected;
    }

    return $selected;
}

function config_dates_empty_examens(): array
{
    return [
        'examen_semestre1_debut' => '',
        'examen_semestre1_fin' => '',
        'examen_semestre2_debut' => '',
        'examen_semestre2_fin' => '',
    ];
}

function config_dates_date_or_null($value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    $timestamp = strtotime($value);
    return $timestamp ? date('Y-m-d', $timestamp) : null;
}

function config_dates_get_or_create_academic_id(mysqli $conn, int $anneeId): int
{
    if ($anneeId <= 0) {
        return 0;
    }

    $stmt = $conn->prepare("
        INSERT INTO configurations_dates_academiques_globales (annee_scolaire_id)
        VALUES (?)
        ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)
    ");
    $stmt->bind_param('i', $anneeId);
    $stmt->execute();
    $id = (int) $conn->insert_id;
    $stmt->close();

    return $id;
}

function config_dates_get_or_create_examens_id(mysqli $conn, int $anneeId, int $anneeFormation): int
{
    if ($anneeId <= 0 || $anneeFormation <= 0) {
        return 0;
    }

    $stmt = $conn->prepare("
        INSERT INTO configurations_examens_annees_formation (annee_scolaire_id, annee_formation)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)
    ");
    $stmt->bind_param('ii', $anneeId, $anneeFormation);
    $stmt->execute();
    $id = (int) $conn->insert_id;
    $stmt->close();

    return $id;
}

function config_dates_data(mysqli $conn, $requestedYear = null, $requestedAnneeFormation = null): array
{
    $annees = annee_scolaire_options($conn);
    $anneesFormation = config_dates_annees_formation($conn);
    $anneeId = annee_scolaire_selected_id($conn, $requestedYear);
    $anneeFormation = config_dates_selected_annee_formation($anneesFormation, $requestedAnneeFormation);
    $academic = [
        'semestre1_debut' => '',
        'semestre1_fin' => '',
        'semestre2_debut' => '',
        'semestre2_fin' => '',
    ];
    $examens = config_dates_empty_examens();
    $vacances = [];

    if ($anneeId > 0) {
        $academicId = config_dates_get_or_create_academic_id($conn, $anneeId);
        $stmt = $conn->prepare("
            SELECT semestre1_debut, semestre1_fin, semestre2_debut, semestre2_fin
            FROM configurations_dates_academiques_globales
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $academicId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if ($row) {
            foreach ($academic as $key => $value) {
                $academic[$key] = (string) ($row[$key] ?? '');
            }
        }

        $stmt = $conn->prepare("
            SELECT nom, date_debut, date_fin
            FROM configurations_dates_vacances_globales
            WHERE configuration_id = ?
            ORDER BY ordre ASC, id ASC
        ");
        $stmt->bind_param('i', $academicId);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $vacances[] = [
                    'nom' => (string) ($row['nom'] ?? ''),
                    'date_debut' => (string) ($row['date_debut'] ?? ''),
                    'date_fin' => (string) ($row['date_fin'] ?? ''),
                ];
            }
        }
        $stmt->close();
    }

    if ($anneeId > 0 && $anneeFormation > 0) {
        $examensId = config_dates_get_or_create_examens_id($conn, $anneeId, $anneeFormation);
        $stmt = $conn->prepare("
            SELECT examen_semestre1_debut, examen_semestre1_fin,
                   examen_semestre2_debut, examen_semestre2_fin
            FROM configurations_examens_annees_formation
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $examensId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if ($row) {
            foreach ($examens as $key => $value) {
                $examens[$key] = (string) ($row[$key] ?? '');
            }
        }
    }

    return [
        'filters' => [
            'annees_scolaires' => $annees,
            'annees_formation' => $anneesFormation,
            'annee_scolaire_id' => $anneeId,
            'annee_formation' => $anneeFormation,
        ],
        'academic' => $academic,
        'examens' => $examens,
        'vacances' => $vacances,
    ];
}

function config_dates_save(mysqli $conn): array
{
    $anneeId = annee_scolaire_selected_id($conn, $_POST['annee_scolaire_id'] ?? null);
    $anneesFormation = config_dates_annees_formation($conn);
    $anneeFormation = config_dates_selected_annee_formation($anneesFormation, $_POST['annee_formation'] ?? null);

    if ($anneeId <= 0 || $anneeFormation <= 0) {
        return ['success' => false, 'message' => 'Veuillez sélectionner une année scolaire et une année de formation valides.'];
    }

    $academicValues = [];
    foreach (['semestre1_debut', 'semestre1_fin', 'semestre2_debut', 'semestre2_fin'] as $field) {
        $academicValues[$field] = config_dates_date_or_null($_POST[$field] ?? null);
    }

    $examValues = [];
    foreach (['examen_semestre1_debut', 'examen_semestre1_fin', 'examen_semestre2_debut', 'examen_semestre2_fin'] as $field) {
        $examValues[$field] = config_dates_date_or_null($_POST[$field] ?? null);
    }

    $vacanceNoms = $_POST['vacances_nom'] ?? [];
    $vacanceDebuts = $_POST['vacances_debut'] ?? [];
    $vacanceFins = $_POST['vacances_fin'] ?? [];
    $vacances = [];
    $maxRows = max(count($vacanceNoms), count($vacanceDebuts), count($vacanceFins));

    for ($index = 0; $index < $maxRows; $index++) {
        $nom = trim((string) ($vacanceNoms[$index] ?? ''));
        $dateDebut = config_dates_date_or_null($vacanceDebuts[$index] ?? null);
        $dateFin = config_dates_date_or_null($vacanceFins[$index] ?? null);

        if ($nom === '' && $dateDebut === null && $dateFin === null) {
            continue;
        }

        $vacances[] = [
            'nom' => $nom !== '' ? $nom : null,
            'date_debut' => $dateDebut,
            'date_fin' => $dateFin,
            'ordre' => count($vacances) + 1,
        ];
    }

    $conn->begin_transaction();
    try {
        $academicId = config_dates_get_or_create_academic_id($conn, $anneeId);
        $examensId = config_dates_get_or_create_examens_id($conn, $anneeId, $anneeFormation);

        $stmt = $conn->prepare("
            UPDATE configurations_dates_academiques_globales
            SET semestre1_debut = ?, semestre1_fin = ?,
                semestre2_debut = ?, semestre2_fin = ?
            WHERE id = ?
        ");
        $stmt->bind_param(
            'ssssi',
            $academicValues['semestre1_debut'],
            $academicValues['semestre1_fin'],
            $academicValues['semestre2_debut'],
            $academicValues['semestre2_fin'],
            $academicId
        );
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("
            UPDATE configurations_examens_annees_formation
            SET examen_semestre1_debut = ?, examen_semestre1_fin = ?,
                examen_semestre2_debut = ?, examen_semestre2_fin = ?
            WHERE id = ?
        ");
        $stmt->bind_param(
            'ssssi',
            $examValues['examen_semestre1_debut'],
            $examValues['examen_semestre1_fin'],
            $examValues['examen_semestre2_debut'],
            $examValues['examen_semestre2_fin'],
            $examensId
        );
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM configurations_dates_vacances_globales WHERE configuration_id = ?");
        $stmt->bind_param('i', $academicId);
        $stmt->execute();
        $stmt->close();

        if ($vacances) {
            $stmt = $conn->prepare("
                INSERT INTO configurations_dates_vacances_globales (configuration_id, nom, date_debut, date_fin, ordre)
                VALUES (?, ?, ?, ?, ?)
            ");
            foreach ($vacances as $vacance) {
                $stmt->bind_param(
                    'isssi',
                    $academicId,
                    $vacance['nom'],
                    $vacance['date_debut'],
                    $vacance['date_fin'],
                    $vacance['ordre']
                );
                $stmt->execute();
            }
            $stmt->close();
        }

        $conn->commit();
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }

    return [
        'success' => true,
        'message' => 'Configuration enregistrée.',
        'data' => config_dates_data($conn, $anneeId, $anneeFormation),
    ];
}

config_dates_ensure_tables($conn);

if ($isAjax) {
    try {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['config_action'] ?? '') === 'save_dates') {
            config_dates_json(config_dates_save($conn));
        }

        config_dates_json([
            'success' => true,
            'data' => config_dates_data($conn, $_GET['annee_scolaire_id'] ?? null, $_GET['annee_formation'] ?? null),
        ]);
    } catch (Throwable $exception) {
        if (function_exists('app_error_log')) {
            app_error_log(
                'system_error',
                $exception::class . ': ' . $exception->getMessage() . ' in ' . $exception->getFile() . ':' . $exception->getLine()
            );
        }
        config_dates_json([
            'success' => false,
            'message' => 'Erreur lors du traitement de la configuration.',
        ], 500);
    }
}

$pageData = config_dates_data($conn, $_GET['annee_scolaire_id'] ?? null, $_GET['annee_formation'] ?? null);

include 'page_directeur.php';
?>

<style>
.configuration-dates-page .config-dates-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
}

.configuration-dates-page .config-dates-subcard {
    border: 1px solid #e3e9f2;
    border-radius: 8px;
    background: #fff;
    padding: 16px;
}

.configuration-dates-page .config-dates-subtitle {
    color: #263238;
    font-size: 0.95rem;
    font-weight: 700;
    margin-bottom: 12px;
}

.configuration-dates-page .config-block-title {
    border-left: 4px solid #0d6efd;
    padding-left: 12px;
    margin: 10px 0 18px;
}

.configuration-dates-page .config-block-title h2 {
    color: #1f2d3d;
    font-size: 1.15rem;
    font-weight: 800;
    margin: 0;
}

.configuration-dates-page .config-block-title p {
    color: #6c757d;
    margin: 4px 0 0;
}

.configuration-dates-page .vacances-table-wrapper {
    overflow-x: auto;
}

.configuration-dates-page .vacances-table .form-control {
    min-width: 150px;
}

.configuration-dates-page .config-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}

@media (max-width: 768px) {
    .configuration-dates-page .config-dates-grid {
        grid-template-columns: 1fr;
    }

    .configuration-dates-page .config-actions {
        justify-content: stretch;
    }

    .configuration-dates-page .config-actions .btn {
        width: 100%;
    }
}
</style>

<div class="dashboard-page fade-in configuration-dates-page">
    <div class="page-header mb-4">
        <div>
            <h1 class="page-title"><i class="fas fa-calendar-alt mr-2"></i>Configuration globale des dates</h1>
            <p class="page-subtitle">Centralisation des semestres, examens et vacances.</p>
        </div>
    </div>

    <section class="epim-card no-hover p-4 mb-4">
        <div class="section-header mb-3">
            <div>
                <h2 class="section-title">Contexte</h2>
                <p class="section-subtitle mb-0">L'année scolaire pilote le calendrier académique.</p>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group col-md-6 mb-0">
                <label for="annee_scolaire_id">Année scolaire</label>
                <select class="form-control" id="annee_scolaire_id"></select>
            </div>
        </div>
    </section>

    <form id="configDatesForm">
        <div class="config-block-title">
            <h2>Calendrier académique</h2>
            <p>Informations communes à toute l'année scolaire sélectionnée.</p>
        </div>

        <section class="epim-card no-hover p-4 mb-4">
            <div class="section-header mb-3">
                <div>
                    <h2 class="section-title">Semestres</h2>
                    <p class="section-subtitle mb-0">Dates officielles des deux semestres pour l'année scolaire sélectionnée.</p>
                </div>
            </div>
            <div class="config-dates-grid">
                <div class="config-dates-subcard">
                    <div class="config-dates-subtitle">1er semestre</div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="semestre1_debut">Date début</label>
                            <input type="date" class="form-control" id="semestre1_debut" name="semestre1_debut">
                        </div>
                        <div class="form-group col-md-6">
                            <label for="semestre1_fin">Date fin</label>
                            <input type="date" class="form-control" id="semestre1_fin" name="semestre1_fin">
                        </div>
                    </div>
                </div>
                <div class="config-dates-subcard">
                    <div class="config-dates-subtitle">2ème semestre</div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="semestre2_debut">Date début</label>
                            <input type="date" class="form-control" id="semestre2_debut" name="semestre2_debut">
                        </div>
                        <div class="form-group col-md-6">
                            <label for="semestre2_fin">Date fin</label>
                            <input type="date" class="form-control" id="semestre2_fin" name="semestre2_fin">
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="epim-card no-hover p-4 mb-4">
            <div class="section-header mb-3">
                <div>
                    <h2 class="section-title">Périodes d'examens</h2>
                    <p class="section-subtitle mb-0">Une période d'examens est configurée pour chaque semestre et chaque année de formation.</p>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group col-md-6">
                    <label for="annee_formation">Année de formation</label>
                    <select class="form-control" id="annee_formation"></select>
                </div>
            </div>
            <div class="config-dates-grid">
                <div class="config-dates-subcard">
                    <div class="config-dates-subtitle">1er semestre</div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="examen_semestre1_debut">Date début</label>
                            <input type="date" class="form-control" id="examen_semestre1_debut" name="examen_semestre1_debut">
                        </div>
                        <div class="form-group col-md-6">
                            <label for="examen_semestre1_fin">Date fin</label>
                            <input type="date" class="form-control" id="examen_semestre1_fin" name="examen_semestre1_fin">
                        </div>
                    </div>
                </div>
                <div class="config-dates-subcard">
                    <div class="config-dates-subtitle">2ème semestre</div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="examen_semestre2_debut">Date début</label>
                            <input type="date" class="form-control" id="examen_semestre2_debut" name="examen_semestre2_debut">
                        </div>
                        <div class="form-group col-md-6">
                            <label for="examen_semestre2_fin">Date fin</label>
                            <input type="date" class="form-control" id="examen_semestre2_fin" name="examen_semestre2_fin">
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="epim-card no-hover p-4 mb-4">
            <div class="section-header mb-3">
                <div>
                    <h2 class="section-title">Vacances</h2>
                    <p class="section-subtitle mb-0">Ajoutez autant de périodes que nécessaire pour l'année scolaire sélectionnée.</p>
                </div>
                <button type="button" class="btn btn-outline-epim" id="addVacationRow">
                    <i class="fas fa-plus mr-1"></i>Ajouter une vacance
                </button>
            </div>
            <div class="vacances-table-wrapper">
                <table class="table epim-table table-borderless vacances-table mb-0">
                    <thead>
                        <tr>
                            <th>Nom de la vacance</th>
                            <th>Date début</th>
                            <th>Date fin</th>
                            <th class="text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody id="vacancesRows"></tbody>
                </table>
            </div>
        </section>

        <div class="config-actions mb-4">
            <button type="submit" class="btn btn-epim-primary" id="saveConfigDates">
                <i class="fas fa-save mr-1"></i>Enregistrer
            </button>
        </div>
    </form>
</div>

<script>
const configDatesState = <?php echo json_encode($pageData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;

(function () {
    const yearSelect = document.getElementById('annee_scolaire_id');
    const formationSelect = document.getElementById('annee_formation');
    const form = document.getElementById('configDatesForm');
    const vacationsBody = document.getElementById('vacancesRows');
    const addVacationButton = document.getElementById('addVacationRow');
    const saveButton = document.getElementById('saveConfigDates');
    const academicFields = [
        'semestre1_debut',
        'semestre1_fin',
        'semestre2_debut',
        'semestre2_fin'
    ];
    const examFields = [
        'examen_semestre1_debut',
        'examen_semestre1_fin',
        'examen_semestre2_debut',
        'examen_semestre2_fin'
    ];

    function notify(type, message) {
        if (window.toastr && typeof toastr[type] === 'function') {
            toastr[type](message);
            return;
        }
        if (type === 'error') {
            console.error(message);
        }
    }

    function fillSelect(select, rows, selectedId) {
        select.innerHTML = '';
        rows.forEach(function (row) {
            const option = document.createElement('option');
            option.value = row.id;
            option.textContent = row.display_label || row.label;
            option.selected = Number(row.id) === Number(selectedId);
            select.appendChild(option);
        });
    }

    function vacationRow(row) {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><input type="text" class="form-control vacances-nom" name="vacances_nom[]" value=""></td>
            <td><input type="date" class="form-control vacances-debut" name="vacances_debut[]" value=""></td>
            <td><input type="date" class="form-control vacances-fin" name="vacances_fin[]" value=""></td>
            <td class="text-right">
                <button type="button" class="btn btn-sm btn-outline-danger-epim remove-vacation-row" title="Supprimer cette période">
                    <i class="fas fa-trash"></i>
                </button>
            </td>
        `;
        tr.querySelector('.vacances-nom').value = row.nom || '';
        tr.querySelector('.vacances-debut').value = row.date_debut || '';
        tr.querySelector('.vacances-fin').value = row.date_fin || '';
        return tr;
    }

    function renderVacations(rows) {
        vacationsBody.innerHTML = '';
        const vacations = rows && rows.length ? rows : [{}];
        vacations.forEach(function (row) {
            vacationsBody.appendChild(vacationRow(row));
        });
    }

    function renderAcademic(data) {
        academicFields.forEach(function (field) {
            const input = document.getElementById(field);
            if (input) {
                input.value = data.academic[field] || '';
            }
        });
        renderVacations(data.vacances || []);
    }

    function renderExamens(data) {
        examFields.forEach(function (field) {
            const input = document.getElementById(field);
            if (input) {
                input.value = data.examens[field] || '';
            }
        });
    }

    function renderData(data, mode) {
        fillSelect(yearSelect, data.filters.annees_scolaires || [], data.filters.annee_scolaire_id);
        fillSelect(formationSelect, data.filters.annees_formation || [], data.filters.annee_formation);

        if (mode !== 'examens') {
            renderAcademic(data);
        }
        renderExamens(data);
    }

    async function loadData(mode) {
        const url = new URL('configuration_dates.php', window.location.href);
        url.searchParams.set('ajax', '1');
        url.searchParams.set('annee_scolaire_id', yearSelect.value || '');
        url.searchParams.set('annee_formation', formationSelect.value || '');

        const response = await fetch(url.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        if (!response.ok) {
            throw new Error('HTTP ' + response.status);
        }
        const payload = await response.json();
        if (!payload.success) {
            throw new Error(payload.message || 'Chargement impossible.');
        }
        renderData(payload.data, mode);
    }

    yearSelect.addEventListener('change', function () {
        loadData('all').catch(function () {
            notify('error', 'Impossible de charger la configuration.');
        });
    });

    formationSelect.addEventListener('change', function () {
        loadData('examens').catch(function () {
            notify('error', "Impossible de charger les périodes d'examens.");
        });
    });

    addVacationButton.addEventListener('click', function () {
        vacationsBody.appendChild(vacationRow({}));
    });

    vacationsBody.addEventListener('click', function (event) {
        const button = event.target.closest('.remove-vacation-row');
        if (!button) {
            return;
        }

        const rows = vacationsBody.querySelectorAll('tr');
        if (rows.length <= 1) {
            rows[0].querySelectorAll('input').forEach(function (input) {
                input.value = '';
            });
            return;
        }

        button.closest('tr').remove();
    });

    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        saveButton.disabled = true;

        const formData = new FormData(form);
        formData.append('ajax', '1');
        formData.append('config_action', 'save_dates');
        formData.append('annee_scolaire_id', yearSelect.value || '');
        formData.append('annee_formation', formationSelect.value || '');

        try {
            const response = await fetch('configuration_dates.php?ajax=1', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const payload = await response.json();
            if (!response.ok || !payload.success) {
                throw new Error(payload.message || 'Enregistrement impossible.');
            }

            renderData(payload.data, 'all');
            notify('success', payload.message || 'Configuration enregistrée.');
        } catch (error) {
            notify('error', error.message || 'Impossible d enregistrer la configuration.');
        } finally {
            saveButton.disabled = false;
        }
    });

    renderData(configDatesState, 'all');
})();
</script>
