<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/annees_scolaires.php';
require_once __DIR__ . '/includes/unite_helper.php';

header('Content-Type: application/json; charset=UTF-8');
auth_require_role('directeur');

if (!isset($_SESSION['nom']) || ($_SESSION['role'] ?? '') !== 'directeur') {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Accès non autorisé.'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

include 'db.php';
unite_ensure_columns($conn);

function dashboard_positive_int($value)
{
    $value = filter_var($value, FILTER_VALIDATE_INT);
    return $value && $value > 0 ? $value : null;
}

function dashboard_bind(mysqli_stmt $stmt, string $types, array $params): void
{
    if ($types !== '' && $params) {
        $stmt->bind_param($types, ...$params);
    }
}

function dashboard_fetch_all(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($conn->error);
    }

    dashboard_bind($stmt, $types, $params);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();

    return $rows;
}

function dashboard_scalar(mysqli $conn, string $sql, string $types = '', array $params = [], $default = 0)
{
    $rows = dashboard_fetch_all($conn, $sql, $types, $params);
    if (!$rows) {
        return $default;
    }

    $row = $rows[0];
    return reset($row);
}

function dashboard_options(array $rows, string $labelColumn): array
{
    return array_map(function ($row) use ($labelColumn) {
        $label = trim((string) ($row[$labelColumn] ?? ''));
        if ($label === '') {
            $label = 'Séquence #' . (int) $row['id'];
        }

        return [
            'id' => (int) $row['id'],
            'label' => $label
        ];
    }, $rows);
}

function dashboard_date_label($date): string
{
    if (!$date) {
        return '-';
    }

    $timestamp = strtotime($date);
    return $timestamp ? date('d/m/Y H:i', $timestamp) : '-';
}

function dashboard_status(array $row): string
{
    if ((int) ($row['valide_par_directeur'] ?? 0) === 1) {
        return 'Validée';
    }

    $comment = trim((string) ($row['commentaire_directeur'] ?? ''));
    return $comment !== '' ? 'Refusée' : 'En attente';
}

try {
    $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($requestMethod === 'POST' && isset($_POST['academic_action'])) {
        $action = $_POST['academic_action'];
        $selectedYearId = dashboard_positive_int($_POST['annee_scolaire_id'] ?? null);

        if ($action === 'activate' && $selectedYearId) {
            $conn->begin_transaction();
            $stmt = $conn->prepare("SELECT date_debut FROM annees_scolaires WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $selectedYearId);
            $stmt->execute();
            $selectedYear = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$selectedYear) {
                throw new RuntimeException('Année scolaire introuvable.');
            }

            $selectedStartDate = $selectedYear['date_debut'];
            $stmt = $conn->prepare("
                UPDATE annees_scolaires
                SET statut = CASE
                        WHEN id = ? THEN 'active'
                        WHEN date_debut < ? THEN 'archivee'
                        ELSE 'preparee'
                    END,
                    active = CASE WHEN id = ? THEN 1 ELSE 0 END
            ");
            $stmt->bind_param('isi', $selectedYearId, $selectedStartDate, $selectedYearId);
            $stmt->execute();
            $stmt->close();
            $conn->commit();
            setCurrentWorkingAcademicYear($conn, $selectedYearId);
            annee_scolaire_mark_director_temp_reactivated($selectedYearId, false);
            echo json_encode(['success' => true, 'message' => 'Année scolaire activée.'], JSON_UNESCAPED_UNICODE);
            exit();
        }

        if ($action === 'open_next') {
            $row = dashboard_fetch_all($conn, "SELECT date_debut FROM annees_scolaires ORDER BY date_debut DESC LIMIT 1")[0] ?? null;
            $startYear = $row ? ((int) date('Y', strtotime($row['date_debut'])) + 1) : ((int) date('Y'));
            $label = $startYear . '-' . ($startYear + 1);
            $dateDebut = $startYear . '-10-01';
            $dateFin = ($startYear + 1) . '-07-31';

            $stmt = $conn->prepare("
                INSERT INTO annees_scolaires (libelle, date_debut, date_fin, active, statut)
                VALUES (?, ?, ?, 0, 'preparee')
                ON DUPLICATE KEY UPDATE date_debut = VALUES(date_debut), date_fin = VALUES(date_fin), statut = IF(statut = 'archivee', 'preparee', statut)
            ");
            $stmt->bind_param('sss', $label, $dateDebut, $dateFin);
            $stmt->execute();
            $stmt->close();
            echo json_encode(['success' => true, 'message' => 'Nouvelle année scolaire ouverte.'], JSON_UNESCAPED_UNICODE);
            exit();
        }

        if (in_array($action, ['archive', 'reactivate'], true) && $selectedYearId) {
            $previousStatus = annee_scolaire_status($conn, $selectedYearId);
            $newStatus = $action === 'archive' ? 'archivee' : 'preparee';
            $stmt = $conn->prepare("
                UPDATE annees_scolaires
                SET statut = ?, active = 0
                WHERE id = ?
            ");
            $stmt->bind_param('si', $newStatus, $selectedYearId);
            $stmt->execute();
            $stmt->close();

            setCurrentWorkingAcademicYear($conn, $selectedYearId);
            annee_scolaire_mark_director_temp_reactivated($selectedYearId, $action === 'reactivate' && $previousStatus === 'archivee');

            echo json_encode([
                'success' => true,
                'message' => $action === 'archive' ? 'Année scolaire archivée.' : 'Année scolaire réactivée.',
            ], JSON_UNESCAPED_UNICODE);
            exit();
        }

        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Action année scolaire invalide.'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $filiereId = dashboard_positive_int($_GET['filiere_id'] ?? null);
    $uniteId = dashboard_positive_int($_GET['unite_id'] ?? null);
    $sequenceId = dashboard_positive_int($_GET['sequence_id'] ?? null);
    $anneeScolaireId = annee_scolaire_selected_id($conn, $_GET['annee_scolaire_id'] ?? null);

    $filterSql = '';
    $filterTypes = '';
    $filterParams = [];

    if ($filiereId) {
        $filterSql .= ' AND f.id = ?';
        $filterTypes .= 'i';
        $filterParams[] = $filiereId;
    }
    if ($uniteId) {
        $filterSql .= ' AND uf.id = ?';
        $filterTypes .= 'i';
        $filterParams[] = $uniteId;
    }
    if ($sequenceId) {
        $filterSql .= ' AND seq.id = ?';
        $filterTypes .= 'i';
        $filterParams[] = $sequenceId;
    }
    if ($anneeScolaireId) {
        $filterSql .= ' AND sp.annee_scolaire_id = ?';
        $filterTypes .= 'i';
        $filterParams[] = $anneeScolaireId;
    }

    $baseJoin = " FROM seances_pedagogiques sp
                  LEFT JOIN suivi_pedagogique s ON sp.id = s.seance_id
                  LEFT JOIN sequences_pedagogiques seq ON sp.sequence_id = seq.id
                  LEFT JOIN unites_de_formation uf ON seq.unite_id = uf.id
                  LEFT JOIN filieres f ON uf.filiere_id = f.id
                  LEFT JOIN formateurs fo ON uf.formateur_id = fo.id
                  WHERE 1 = 1";

    $uniteOptionSql = "SELECT uf.id, uf.intitule, COALESCE(uf.semestre, 1) AS semestre
                       FROM unites_de_formation uf
                       WHERE (? IS NULL OR uf.filiere_id = ?)
                       ORDER BY uf.intitule";

    $sequenceOptionSql = "SELECT seq.id, seq.intitule
                          FROM sequences_pedagogiques seq
                          LEFT JOIN unites_de_formation uf ON seq.unite_id = uf.id
                          WHERE (? IS NULL OR seq.unite_id = ?)
                            AND (? IS NULL OR uf.filiere_id = ?)
                          ORDER BY seq.intitule";

    $filieres = dashboard_options(
        dashboard_fetch_all($conn, "SELECT id, nom FROM filieres ORDER BY nom"),
        'nom'
    );

    $unites = dashboard_options(
        dashboard_fetch_all($conn, $uniteOptionSql, 'ii', [$filiereId, $filiereId]),
        'intitule'
    );

    $sequences = ($uniteId || $filiereId)
        ? dashboard_options(
            dashboard_fetch_all($conn, $sequenceOptionSql, 'iiii', [$uniteId, $uniteId, $filiereId, $filiereId]),
            'intitule'
        )
        : [];

    $validatedCondition = "COALESCE(s.valide_par_directeur, 0) = 1";
    $pendingCondition = "COALESCE(s.valide_par_directeur, 0) = 0
                         AND TRIM(COALESCE(s.commentaire_directeur, '')) = ''";

    $totalSessions = (int) dashboard_scalar(
        $conn,
        "SELECT COUNT(*)" . $baseJoin . $filterSql,
        $filterTypes,
        $filterParams
    );

    $pendingSessions = (int) dashboard_scalar(
        $conn,
        "SELECT COUNT(*)" . $baseJoin . $filterSql . " AND " . $pendingCondition,
        $filterTypes,
        $filterParams
    );

    $averageProgress = (float) dashboard_scalar(
        $conn,
        "SELECT COALESCE(AVG(
            CASE
                WHEN uf.masse_horaire > 0 AND s.heures_cumulees IS NOT NULL
                    THEN (s.heures_cumulees / uf.masse_horaire) * 100
                ELSE s.taux_realisation
            END
        ), 0)" . $baseJoin . $filterSql,
        $filterTypes,
        $filterParams,
        0
    );

    $generatedSheets = (int) dashboard_scalar(
        $conn,
        "SELECT COUNT(DISTINCT uf.id)" . $baseJoin . $filterSql . " AND " . $validatedCondition,
        $filterTypes,
        $filterParams
    );

    $activeFiliereRows = dashboard_fetch_all(
        $conn,
        "SELECT COALESCE(f.nom, '-') AS nom, COUNT(*) AS total" .
        $baseJoin . $filterSql .
        " GROUP BY f.id, f.nom
          ORDER BY total DESC, f.nom ASC
          LIMIT 1",
        $filterTypes,
        $filterParams
    );
    $activeFiliere = $activeFiliereRows[0]['nom'] ?? '-';

    $lastSessionDate = dashboard_scalar(
        $conn,
        "SELECT MAX(sp.date_seance)" . $baseJoin . $filterSql,
        $filterTypes,
        $filterParams,
        null
    );

    $recentRows = dashboard_fetch_all(
        $conn,
        "SELECT sp.date_seance,
                COALESCE(fo.nom, '-') AS formateur,
                COALESCE(f.nom, '-') AS filiere,
                COALESCE(uf.intitule, '-') AS unite,
                s.valide_par_directeur,
                s.commentaire_directeur" .
        $baseJoin . $filterSql .
        " ORDER BY sp.date_seance DESC, sp.id DESC
          LIMIT 8",
        $filterTypes,
        $filterParams
    );

    $recentSessions = array_map(function ($row) {
        return [
            'date' => dashboard_date_label($row['date_seance']),
            'formateur' => $row['formateur'],
            'filiere' => $row['filiere'],
            'unite' => $row['unite'],
            'statut' => dashboard_status($row)
        ];
    }, $recentRows);

    echo json_encode([
        'success' => true,
        'filters' => [
            'filieres' => $filieres,
            'unites' => $unites,
            'sequences' => $sequences,
            'annees_scolaires' => annee_scolaire_options($conn),
            'annee_scolaire_id' => $anneeScolaireId
        ],
        'kpis' => [
            'filieres' => (int) dashboard_scalar($conn, "SELECT COUNT(DISTINCT f.id)" . $baseJoin . $filterSql, $filterTypes, $filterParams),
            'formateurs' => (int) dashboard_scalar($conn, "SELECT COUNT(DISTINCT fo.id)" . $baseJoin . $filterSql, $filterTypes, $filterParams),
            'seances_total' => $totalSessions,
            'seances_attente' => $pendingSessions,
            'taux_moyen' => number_format($averageProgress, 2, ',', ' ') . ' %',
            'filiere_active' => $activeFiliere,
            'fiches_generees' => $generatedSheets
        ],
        'recent_sessions' => $recentSessions
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    error_log('Dashboard directeur: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Impossible de charger le tableau de bord.'
    ], JSON_UNESCAPED_UNICODE);
}
