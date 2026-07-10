<?php
declare(strict_types=1);

$isAjax = isset($_GET['ajax']) && $_GET['ajax'] === '1';

if ($isAjax) {
    require_once __DIR__ . '/auth_check.php';
    auth_require_role('formateur');
    include 'db.php';
} else {
    include 'page_formateur.php';
}

require_once __DIR__ . '/annees_scolaires.php';
require_once __DIR__ . '/includes/unite_helper.php';

unite_ensure_columns($conn);

function formateur_bind_params(mysqli_stmt $stmt, string $types, array &$params): void
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

function formateur_fetch_all(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    formateur_bind_params($stmt, $types, $params);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();

    return $rows;
}

function formateur_scalar(mysqli $conn, string $sql, string $types = '', array $params = []): int
{
    $row = formateur_fetch_all($conn, $sql, $types, $params)[0] ?? [];
    return (int) ($row['total'] ?? 0);
}

function formateur_resolve_id(mysqli $conn): int
{
    $sessionUserId = (int) ($_SESSION['id'] ?? 0);
    $formateurId = $sessionUserId;
    $rows = formateur_fetch_all(
        $conn,
        "SELECT id FROM formateurs WHERE utilisateur_id = ? OR id = ? LIMIT 1",
        'ii',
        [$sessionUserId, $sessionUserId]
    );

    if ($rows) {
        $formateurId = (int) $rows[0]['id'];
    }

    return $formateurId;
}

function formateur_status_badge(array $row): string
{
    if ((int) ($row['valide_par_directeur'] ?? 0) === 1) {
        return '<span class="badge-epim-success">Validée</span>';
    }

    return trim((string) ($row['commentaire_directeur'] ?? '')) !== ''
        ? '<span class="badge-epim-danger">Refusée</span>'
        : '<span class="badge-epim-warning">En attente</span>';
}

function formateur_recent_rows_html(array $recentSessions): string
{
    if (!$recentSessions) {
        return '<tr><td colspan="5" class="text-center text-muted py-4">Aucune séance enregistrée.</td></tr>';
    }

    $html = '';
    foreach ($recentSessions as $session) {
        $date = $session['date_seance'] ? date('d/m/Y H:i', strtotime((string) $session['date_seance'])) : '-';
        $html .= '<tr>';
        $html .= '<td>' . htmlspecialchars($date, ENT_QUOTES, 'UTF-8') . '</td>';
        $html .= '<td>' . htmlspecialchars((string) $session['filiere'], ENT_QUOTES, 'UTF-8') . '</td>';
        $html .= '<td>' . htmlspecialchars((string) $session['unite'], ENT_QUOTES, 'UTF-8') . '</td>';
        $html .= '<td>' . htmlspecialchars((string) ($session['sequence_intitule'] ?: '-'), ENT_QUOTES, 'UTF-8') . '</td>';
        $html .= '<td>' . formateur_status_badge($session) . '</td>';
        $html .= '</tr>';
    }

    return $html;
}

function formateur_units_for_year(mysqli $conn, int $formateurId, int $anneeId, string $anneeStatus): array
{
    if ($anneeStatus === 'archivee') {
        return formateur_fetch_all(
            $conn,
            "SELECT uf.id, uf.intitule, uf.masse_horaire, COALESCE(uf.semestre, 1) AS semestre, COALESCE(uf.is_archived, 0) AS is_archived, f.nom AS filiere
             FROM unites_de_formation uf
             INNER JOIN filieres f ON f.id = uf.filiere_id
             WHERE uf.formateur_id = ?
               AND EXISTS (
                    SELECT 1
                    FROM sequences_pedagogiques seqh
                    INNER JOIN seances_pedagogiques sph ON sph.sequence_id = seqh.id
                    WHERE seqh.unite_id = uf.id AND sph.annee_scolaire_id = ?
               )
             ORDER BY uf.intitule",
            'ii',
            [$formateurId, $anneeId]
        );
    }

    return formateur_fetch_all(
        $conn,
        "SELECT uf.id, uf.intitule, uf.masse_horaire, COALESCE(uf.semestre, 1) AS semestre, COALESCE(uf.is_archived, 0) AS is_archived, f.nom AS filiere
         FROM unites_de_formation uf
         INNER JOIN filieres f ON f.id = uf.filiere_id
         WHERE uf.formateur_id = ? AND COALESCE(uf.is_archived, 0) = 0
         ORDER BY uf.intitule",
        'i',
        [$formateurId]
    );
}

function formateur_pick_unit(array $units, $requestedUnitId): int
{
    $requested = filter_var($requestedUnitId, FILTER_VALIDATE_INT);
    foreach ($units as $unit) {
        if ($requested && (int) $unit['id'] === (int) $requested) {
            return (int) $unit['id'];
        }
    }

    return $units ? (int) $units[0]['id'] : 0;
}

function formateur_unit_stats(mysqli $conn, array $units, int $anneeId): array
{
    $payload = [];
    foreach ($units as $unit) {
        $unitId = (int) $unit['id'];
        $stats = formateur_fetch_all(
            $conn,
            "SELECT
                    COUNT(DISTINCT seq.id) AS sequence_count,
                    COUNT(DISTINCT sp.id) AS seance_count,
                    COALESCE(SUM(sp.heures_reelles), 0) AS heures_realisees,
                    SUM(CASE WHEN COALESCE(s.valide_par_directeur, 0) = 1 THEN 1 ELSE 0 END) AS seances_validees,
                    SUM(CASE WHEN COALESCE(s.valide_par_directeur, 0) = 0 AND TRIM(COALESCE(s.commentaire_directeur, '')) = '' THEN 1 ELSE 0 END) AS seances_attente
             FROM unites_de_formation uf
             LEFT JOIN sequences_pedagogiques seq ON seq.unite_id = uf.id AND COALESCE(seq.is_archived, 0) = 0
             LEFT JOIN seances_pedagogiques sp ON sp.sequence_id = seq.id AND sp.annee_scolaire_id = ?
             LEFT JOIN suivi_pedagogique s ON s.seance_id = sp.id
             WHERE uf.id = ?",
            'ii',
            [$anneeId, $unitId]
        )[0] ?? [];

        $masse = (float) ($unit['masse_horaire'] ?? 0);
        $realise = (float) ($stats['heures_realisees'] ?? 0);
        $taux = $masse > 0 ? max(0, ($realise / $masse) * 100) : 0;

        $payload[] = [
            'id' => $unitId,
            'intitule' => (string) $unit['intitule'],
            'filiere' => (string) $unit['filiere'],
            'semestre' => unite_normalize_semestre($unit['semestre'] ?? 1),
            'semestre_label' => unite_semestre_label($unit['semestre'] ?? 1),
            'is_archived' => (int) ($unit['is_archived'] ?? 0),
            'masse_horaire' => $masse,
            'heures_realisees' => $realise,
            'taux' => $taux,
            'bar' => min(100, $taux),
            'sequence_count' => (int) ($stats['sequence_count'] ?? 0),
            'seance_count' => (int) ($stats['seance_count'] ?? 0),
            'seances_validees' => (int) ($stats['seances_validees'] ?? 0),
            'seances_attente' => (int) ($stats['seances_attente'] ?? 0),
        ];
    }

    return $payload;
}

function formateur_dashboard_data(mysqli $conn, int $formateurId, $requestedYearId = null, $requestedUnitId = null): array
{
    $anneeId = annee_scolaire_selected_id($conn, $requestedYearId);
    $annee = annee_scolaire_period($conn, $anneeId) ?: [];
    $anneeStatus = annee_scolaire_normalize_status($annee['statut'] ?? 'archivee');
    $anneeLabel = (string) ($annee['libelle'] ?? 'Année scolaire');

    $units = formateur_units_for_year($conn, $formateurId, $anneeId, $anneeStatus);
    $selectedUnitId = formateur_pick_unit($units, $requestedUnitId);

    if ($selectedUnitId === 0) {
        return [
            'annee_scolaire_id' => $anneeId,
            'annee_label' => $anneeLabel,
            'annee_status' => $anneeStatus,
            'annees_scolaires' => annee_scolaire_options($conn),
            'selected_unit_id' => 0,
            'units' => [],
            'kpis' => [
                'filieres' => 0,
                'unites' => 0,
                'sequences' => 0,
                'seances' => 0,
                'validees' => 0,
                'attente' => 0,
            ],
            'recent_html' => formateur_recent_rows_html([]),
        ];
    }

    $unitSql = $selectedUnitId > 0 ? ' AND uf.id = ?' : '';

    $baseParams = [$formateurId];
    $baseTypes = 'i';
    if ($selectedUnitId > 0) {
        $baseParams[] = $selectedUnitId;
        $baseTypes .= 'i';
    }

    $kpis = [
        'filieres' => formateur_scalar(
            $conn,
            "SELECT COUNT(DISTINCT uf.filiere_id) AS total
             FROM unites_de_formation uf
             WHERE uf.formateur_id = ?" . $unitSql,
            $baseTypes,
            $baseParams
        ),
        'unites' => formateur_scalar(
            $conn,
            "SELECT COUNT(*) AS total
             FROM unites_de_formation uf
             WHERE uf.formateur_id = ?" . $unitSql,
            $baseTypes,
            $baseParams
        ),
        'sequences' => formateur_scalar(
            $conn,
            "SELECT COUNT(*) AS total
             FROM sequences_pedagogiques seq
             INNER JOIN unites_de_formation uf ON uf.id = seq.unite_id
             WHERE uf.formateur_id = ? AND COALESCE(seq.is_archived, 0) = 0" . $unitSql,
            $baseTypes,
            $baseParams
        ),
        'seances' => formateur_scalar(
            $conn,
            "SELECT COUNT(*) AS total
             FROM seances_pedagogiques sp
             INNER JOIN sequences_pedagogiques seq ON seq.id = sp.sequence_id
             INNER JOIN unites_de_formation uf ON uf.id = seq.unite_id
             WHERE uf.formateur_id = ? AND sp.annee_scolaire_id = ?" . $unitSql,
            $baseTypes . 'i',
            array_merge([$formateurId, $anneeId], $selectedUnitId > 0 ? [$selectedUnitId] : [])
        ),
        'validees' => formateur_scalar(
            $conn,
            "SELECT COUNT(*) AS total
             FROM seances_pedagogiques sp
             INNER JOIN sequences_pedagogiques seq ON seq.id = sp.sequence_id
             INNER JOIN unites_de_formation uf ON uf.id = seq.unite_id
             LEFT JOIN suivi_pedagogique s ON s.seance_id = sp.id
             WHERE uf.formateur_id = ? AND sp.annee_scolaire_id = ? AND COALESCE(s.valide_par_directeur, 0) = 1" . $unitSql,
            $baseTypes . 'i',
            array_merge([$formateurId, $anneeId], $selectedUnitId > 0 ? [$selectedUnitId] : [])
        ),
        'attente' => formateur_scalar(
            $conn,
            "SELECT COUNT(*) AS total
             FROM seances_pedagogiques sp
             INNER JOIN sequences_pedagogiques seq ON seq.id = sp.sequence_id
             INNER JOIN unites_de_formation uf ON uf.id = seq.unite_id
             LEFT JOIN suivi_pedagogique s ON s.seance_id = sp.id
             WHERE uf.formateur_id = ?
               AND sp.annee_scolaire_id = ?
               AND COALESCE(s.valide_par_directeur, 0) = 0
               AND TRIM(COALESCE(s.commentaire_directeur, '')) = ''" . $unitSql,
            $baseTypes . 'i',
            array_merge([$formateurId, $anneeId], $selectedUnitId > 0 ? [$selectedUnitId] : [])
        ),
    ];

    $recentUnitSql = $selectedUnitId > 0 ? ' AND uf.id = ?' : '';
    $recentTypes = 'ii' . ($selectedUnitId > 0 ? 'i' : '');
    $recentParams = [$formateurId, $anneeId];
    if ($selectedUnitId > 0) {
        $recentParams[] = $selectedUnitId;
    }
    $recentSessions = formateur_fetch_all(
        $conn,
        "SELECT sp.date_seance, f.nom AS filiere, uf.intitule AS unite, seq.intitule AS sequence_intitule,
                COALESCE(s.valide_par_directeur, 0) AS valide_par_directeur,
                COALESCE(s.commentaire_directeur, '') AS commentaire_directeur
         FROM seances_pedagogiques sp
         INNER JOIN sequences_pedagogiques seq ON seq.id = sp.sequence_id
         INNER JOIN unites_de_formation uf ON uf.id = seq.unite_id
         INNER JOIN filieres f ON f.id = uf.filiere_id
         LEFT JOIN suivi_pedagogique s ON s.seance_id = sp.id
         WHERE uf.formateur_id = ? AND sp.annee_scolaire_id = ?" . $recentUnitSql . "
         ORDER BY sp.date_seance DESC, sp.id DESC
         LIMIT 8",
        $recentTypes,
        $recentParams
    );

    return [
        'annee_scolaire_id' => $anneeId,
        'annee_label' => $anneeLabel,
        'annee_status' => $anneeStatus,
        'annees_scolaires' => annee_scolaire_options($conn),
        'selected_unit_id' => $selectedUnitId,
        'units' => formateur_unit_stats($conn, $units, $anneeId),
        'kpis' => $kpis,
        'recent_html' => formateur_recent_rows_html($recentSessions),
    ];
}

$formateurId = formateur_resolve_id($conn);
$dashboardData = formateur_dashboard_data(
    $conn,
    $formateurId,
    $_GET['annee_scolaire_id'] ?? null,
    $_GET['unite_id'] ?? null
);

if ($isAjax) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => true, 'data' => $dashboardData], JSON_UNESCAPED_UNICODE);
    exit();
}

$kpis = $dashboardData['kpis'];
$unitsPayload = $dashboardData['units'];
$anneesScolaires = $dashboardData['annees_scolaires'];
?>

<div class="container-fluid fade-in">
    <div class="page-header">
        <h1 class="page-title">Tableau de bord formateur</h1>
        <p class="page-subtitle">
            Bienvenue, <?php echo htmlspecialchars($_SESSION['nom'] ?? '', ENT_QUOTES, 'UTF-8'); ?>.
            Année scolaire : <strong id="formateurYearLabel"><?php echo htmlspecialchars($dashboardData['annee_label'], ENT_QUOTES, 'UTF-8'); ?></strong>
        </p>
    </div>

    <section class="director-kpi-grid formateur-kpi-grid mb-4">
        <article class="stat-card bg-blue director-kpi-card">
            <i class="fas fa-layer-group"></i>
            <div class="stat-value" data-kpi="filieres"><?php echo (int) $kpis['filieres']; ?></div>
            <div class="stat-label">Filières</div>
        </article>
        <article class="stat-card bg-orange director-kpi-card">
            <i class="fas fa-book"></i>
            <div class="stat-value" data-kpi="unites"><?php echo (int) $kpis['unites']; ?></div>
            <div class="stat-label">Unités affectées</div>
        </article>
        <article class="stat-card bg-dark director-kpi-card">
            <i class="fas fa-stream"></i>
            <div class="stat-value" data-kpi="sequences"><?php echo (int) $kpis['sequences']; ?></div>
            <div class="stat-label">Séquences disponibles</div>
        </article>
        <article class="stat-card bg-blue director-kpi-card">
            <i class="fas fa-calendar-check"></i>
            <div class="stat-value" data-kpi="seances"><?php echo (int) $kpis['seances']; ?></div>
            <div class="stat-label">Séances réalisées</div>
        </article>
        <article class="stat-card bg-orange director-kpi-card">
            <i class="fas fa-check-circle"></i>
            <div class="stat-value" data-kpi="validees"><?php echo (int) $kpis['validees']; ?></div>
            <div class="stat-label">Séances validées</div>
        </article>
        <article class="stat-card bg-dark director-kpi-card">
            <i class="fas fa-hourglass-half"></i>
            <div class="stat-value" data-kpi="attente"><?php echo (int) $kpis['attente']; ?></div>
            <div class="stat-label">En attente</div>
        </article>
    </section>

    <section class="epim-card no-hover p-4 mb-4">
        <div class="section-header">
            <div>
                <h2>Progression pédagogique</h2>
                <p>Vue synthétique selon l'année scolaire et l'unité sélectionnées.</p>
            </div>
        </div>

        <div class="form-row align-items-end mb-3">
            <div class="form-group col-lg-6">
                <label for="formateurYearFilter">Année scolaire</label>
                <select class="form-control" id="formateurYearFilter">
                    <?php foreach ($anneesScolaires as $annee): ?>
                        <option value="<?php echo (int) $annee['id']; ?>" <?php echo (int) $annee['id'] === (int) $dashboardData['annee_scolaire_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($annee['display_label'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group col-lg-6">
                <label for="unitProgressFilter">Unité</label>
                <select class="form-control" id="unitProgressFilter"></select>
            </div>
        </div>

        <div id="progressEmptyState" class="text-center text-muted py-4" hidden>Aucune unité disponible pour cette année scolaire.</div>

        <div class="epim-card no-hover p-3" id="progressUnitCard">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <div>
                    <h3 class="h6 mb-1" id="progressUnitName"></h3>
                    <div class="small text-muted" id="progressUnitFiliere"></div>
                </div>
                <span class="badge-epim-info" id="progressUnitRate"></span>
            </div>
            <div class="progress mb-2" style="height:10px;">
                <div class="progress-bar" id="progressUnitBar" role="progressbar" style="width: 0%;"></div>
            </div>
            <div class="d-flex flex-wrap justify-content-between small text-muted" style="gap:8px;">
                <span id="progressUnitHours"></span>
                <span id="progressUnitSessions"></span>
                <span id="progressUnitValidated"></span>
                <span id="progressUnitPending"></span>
            </div>
        </div>
    </section>

    <section class="epim-card no-hover p-3">
        <div class="section-header px-1">
            <div>
                <h2>Dernières séances</h2>
                <p>Dernières séances enregistrées dans le contexte sélectionné.</p>
            </div>
        </div>
        <div class="table-responsive epim-data-table">
            <table class="table epim-table table-borderless mb-0">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Filière</th>
                        <th>Unité</th>
                        <th>Séquence</th>
                        <th>Statut</th>
                    </tr>
                </thead>
                <tbody id="formateurRecentRows">
                    <?php echo $dashboardData['recent_html']; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<style>
    .formateur-kpi-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    @media (max-width: 991.98px) {
        .formateur-kpi-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 575.98px) {
        .formateur-kpi-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    let dashboardData = <?php echo json_encode($dashboardData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
    const yearSelect = document.getElementById('formateurYearFilter');
    const unitSelect = document.getElementById('unitProgressFilter');
    const emptyState = document.getElementById('progressEmptyState');
    const progressCard = document.getElementById('progressUnitCard');
    const formatNumber = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 2 });

    function updateKpis(kpis) {
        Object.keys(kpis || {}).forEach(function(key) {
            const node = document.querySelector('[data-kpi="' + key + '"]');
            if (node) {
                node.textContent = kpis[key];
            }
        });
    }

    function renderUnitOptions(data) {
        unitSelect.innerHTML = '';
        (data.units || []).forEach(function(unit) {
            const option = document.createElement('option');
            option.value = unit.id;
            option.textContent = unit.intitule + (unit.is_archived ? ' (Archivée)' : '');
            if (String(unit.id) === String(data.selected_unit_id)) {
                option.selected = true;
            }
            unitSelect.appendChild(option);
        });
    }

    function selectedUnit(data) {
        const units = data.units || [];
        return units.find(function(unit) {
            return String(unit.id) === String(data.selected_unit_id);
        }) || units[0] || null;
    }

    function updateUnitProgress(data) {
        const unit = selectedUnit(data);
        const hasUnit = !!unit;
        emptyState.hidden = hasUnit;
        progressCard.hidden = !hasUnit;
        unitSelect.disabled = !hasUnit;

        if (!hasUnit) {
            return;
        }

        document.getElementById('progressUnitName').textContent = unit.intitule;
        document.getElementById('progressUnitFiliere').textContent = unit.filiere;
        document.getElementById('progressUnitRate').textContent = formatNumber.format(unit.taux) + ' %';
        document.getElementById('progressUnitBar').style.width = Math.min(100, unit.bar) + '%';
        document.getElementById('progressUnitHours').textContent = formatNumber.format(unit.heures_realisees) + ' h / ' + formatNumber.format(unit.masse_horaire) + ' h';
        document.getElementById('progressUnitSessions').textContent = unit.seance_count + ' séance' + (unit.seance_count > 1 ? 's' : '');
        document.getElementById('progressUnitValidated').textContent = unit.seances_validees + ' validée' + (unit.seances_validees > 1 ? 's' : '');
        document.getElementById('progressUnitPending').textContent = unit.seances_attente + ' en attente';
    }

    function renderDashboard(data) {
        dashboardData = data;
        document.getElementById('formateurYearLabel').textContent = data.annee_label;
        updateKpis(data.kpis);
        renderUnitOptions(data);
        updateUnitProgress(data);
        document.getElementById('formateurRecentRows').innerHTML = data.recent_html;
    }

    function fetchDashboard(unitId) {
        const params = new URLSearchParams();
        params.set('ajax', '1');
        params.set('annee_scolaire_id', yearSelect.value);
        if (unitId) {
            params.set('unite_id', unitId);
        }

        fetch('tableau_bord_formateur.php?' + params.toString(), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function(response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.json();
            })
            .then(function(payload) {
                if (!payload.success) {
                    throw new Error(payload.message || 'Erreur');
                }
                renderDashboard(payload.data);
            })
            .catch(function() {
                if (window.toastr) {
                    toastr.error('Impossible de charger les données du tableau de bord.');
                }
            });
    }

    yearSelect.addEventListener('change', function() {
        fetchDashboard('');
    });

    unitSelect.addEventListener('change', function() {
        fetchDashboard(unitSelect.value);
    });

    renderDashboard(dashboardData);
});
</script>

<?php include 'footer.php'; ?>
