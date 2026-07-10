<?php
$is_ajax = isset($_GET['ajax']) && $_GET['ajax'] === '1';

if ($is_ajax) {
    require_once __DIR__ . '/auth_check.php';
    auth_require_role('formateur');
    include 'db.php';
} else {
    include 'page_formateur.php';
}
require_once __DIR__ . '/annees_scolaires.php';
require_once __DIR__ . '/includes/unite_helper.php';

unite_ensure_columns($conn);

$session_user_id = (int) $_SESSION['id'];
$formateur_id = $session_user_id;
$stmt_formateur = $conn->prepare("SELECT id FROM formateurs WHERE utilisateur_id = ? OR id = ? LIMIT 1");
if ($stmt_formateur) {
    $stmt_formateur->bind_param("ii", $session_user_id, $session_user_id);
    $stmt_formateur->execute();
    $stmt_formateur->bind_result($resolved_formateur_id);
    if ($stmt_formateur->fetch()) {
        $formateur_id = (int) $resolved_formateur_id;
    }
    $stmt_formateur->close();
}

// Récupérer l'ID de l'unité de formation (optionnel — peut être absent si accès via sidebar)
$unite_id  = filter_input(INPUT_GET, 'unite_id', FILTER_VALIDATE_INT) ?: null;
$unite_nom = isset($_GET['unite_nom']) ? urldecode($_GET['unite_nom']) : '';
$controle_filter = $_GET['controle_continu'] ?? '';
$controle_filter = in_array($controle_filter, ['avec', 'sans'], true) ? $controle_filter : '';
$annees_scolaires = annee_scolaire_options($conn);
$annee_scolaire_id = annee_scolaire_selected_id($conn, $_GET['annee_scolaire_id'] ?? null);
$annee_status = annee_scolaire_status($conn, $annee_scolaire_id);
$annee_temp_reactivated = annee_scolaire_is_temp_reactivated($annee_scolaire_id);
$annee_editable = annee_scolaire_is_editable_for_current_user($conn, $annee_scolaire_id);

// Récupérer toutes les unités du formateur pour le filtre dropdown
$sql_unites = "SELECT uf.id, uf.intitule, COALESCE(uf.semestre, 1) AS semestre
               FROM unites_de_formation uf
               WHERE uf.formateur_id = ? AND COALESCE(uf.is_archived, 0) = 0
               ORDER BY uf.intitule";
$stmt_unites = $conn->prepare($sql_unites);
$stmt_unites->bind_param("i", $formateur_id);
$stmt_unites->execute();
$result_unites = $stmt_unites->get_result();
$unites = [];
while ($u = $result_unites->fetch_assoc()) {
    $unites[] = $u;
}
$stmt_unites->close();

// Si aucun unite_id passé en paramètre, on utilise la première unité du formateur (ou toutes)
if (!$unite_id && count($unites) > 0) {
    $unite_id  = $unites[0]['id'];
    $unite_nom = $unites[0]['intitule'];
}

if ($unite_id) {
    foreach ($unites as $u) {
        if ((int) $u['id'] === (int) $unite_id) {
            $unite_nom = $u['intitule'];
            break;
        }
    }
}

// Récupérer les séances de l'unité sélectionnée (ou toutes si pas de filtre possible)
$seances = [];
if ($unite_id) {
    $sql = "SELECT sp.id, sp.date_seance, sp.objectif_pedagogique, sp.heures_reelles, sp.controle_continu,
                   s.valide_par_directeur, s.commentaire_directeur
            FROM seances_pedagogiques sp
            LEFT JOIN suivi_pedagogique s     ON sp.id = s.seance_id
            INNER JOIN sequences_pedagogiques sq ON sp.sequence_id = sq.id
            INNER JOIN unites_de_formation un    ON sq.unite_id = un.id
            WHERE un.id = ? AND un.formateur_id = ? AND sp.annee_scolaire_id = ?";
    if ($controle_filter === 'avec') {
        $sql .= " AND sp.controle_continu = 1";
    } elseif ($controle_filter === 'sans') {
        $sql .= " AND sp.controle_continu = 0";
    }
    $sql .= " ORDER BY sp.date_seance DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iii", $unite_id, $formateur_id, $annee_scolaire_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $seances[] = $row;
    }
    $stmt->close();
}

function gestion_seances_add_button_html(?int $unite_id, string $unite_nom, bool $annee_editable, int $annee_scolaire_id): string
{
    if (!$unite_id) {
        return '';
    }

    if (!$annee_editable) {
        return '<span class="badge-epim-orange add-session-archive-badge">Année archivée</span>';
    }

    $href = 'ajouter_seance.php?unite_id=' . (int) $unite_id
        . '&unite_nom=' . urlencode($unite_nom)
        . '&annee_scolaire_id=' . (int) $annee_scolaire_id;

    return '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" class="btn btn-epim-primary btn-sm add-session-btn">'
        . '<i class="fas fa-plus"></i><span>Ajouter une séance</span></a>';
}

function gestion_seances_actions_html(?int $unite_id, string $unite_nom, bool $annee_editable, string $annee_status, bool $annee_temp_reactivated, int $annee_scolaire_id): string
{
    $html = gestion_seances_add_button_html($unite_id, $unite_nom, $annee_editable, $annee_scolaire_id);
    if ($annee_status === 'archivee') {
        $action = $annee_temp_reactivated ? 'archive_temp' : 'reactivate_temp';
        $class = $annee_temp_reactivated ? 'btn-outline-epim' : 'btn-epim-accent';
        $icon = $annee_temp_reactivated ? 'fa-lock' : 'fa-unlock';
        $label = $annee_temp_reactivated ? 'Réarchiver' : 'Réactiver temporairement';
        $html .= ' <button type="button" class="btn ' . $class . ' btn-sm ml-2" id="toggleTempYear" data-action="' . $action . '">'
            . '<i class="fas ' . $icon . ' mr-1"></i>' . $label . '</button>';
    }

    return $html;
}

function gestion_seances_table_html(?int $unite_id, array $seances, string $unite_nom, bool $annee_editable, int $annee_scolaire_id): string
{
    ob_start();
    ?>
    <?php if (!$unite_id): ?>
        <div class="text-center py-5 text-muted">
            <i class="fas fa-info-circle fa-2x mb-2"></i>
            <p>Aucune unité de formation ne vous est assignée.</p>
        </div>
    <?php elseif (count($seances) === 0): ?>
        <div class="text-center py-5 text-muted">
            <i class="fas fa-calendar-plus fa-2x mb-2"></i>
            <p>Aucune séance enregistrée pour cette unité.</p>
            <?php if ($annee_editable): ?>
                <a href="ajouter_seance.php?unite_id=<?php echo (int) $unite_id; ?>&unite_nom=<?php echo urlencode($unite_nom); ?>&annee_scolaire_id=<?php echo (int) $annee_scolaire_id; ?>" class="btn btn-epim-primary btn-sm add-session-btn mt-2">
                    <i class="fas fa-plus"></i><span>Ajouter la première séance</span>
                </a>
            <?php else: ?>
                <span class="badge-epim-orange">Année archivée</span>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="table-responsive epim-data-table">
            <table class="table epim-table table-borderless mb-0">
                <thead>
                    <tr>
                        <th>Date de la séance</th>
                        <th>Objectif pédagogique</th>
                        <th>Contrôle continu</th>
                        <th>Heures réalisées</th>
                        <th>Validité</th>
                        <th>Commentaire du directeur</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($seances as $row): ?>
                        <tr>
                            <td><?php echo date('d/m/Y H:i', strtotime($row['date_seance'])); ?></td>
                            <td><?php echo htmlspecialchars($row['objectif_pedagogique'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <?php if ((int) $row['controle_continu'] === 1): ?>
                                    <span class="badge-epim-danger"><i class="fas fa-clipboard-check mr-1"></i>Contrôle</span>
                                <?php else: ?>
                                    <span class="text-muted">Non</span>
                                <?php endif; ?>
                            </td>
                            <td><strong><?php echo htmlspecialchars((string) $row['heures_reelles'], ENT_QUOTES, 'UTF-8'); ?> h</strong></td>
                            <td>
                                <?php if ((int) $row['valide_par_directeur'] === 1): ?>
                                    <span class="badge-epim-success"><i class="fas fa-check-circle mr-1"></i>Validée</span>
                                <?php else: ?>
                                    <span class="badge-epim-orange"><i class="fas fa-clock mr-1"></i>En attente</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo !empty($row['commentaire_directeur']) ? htmlspecialchars($row['commentaire_directeur'], ENT_QUOTES, 'UTF-8') : '<span class="text-muted">-</span>'; ?></td>
                            <td class="text-center sessions-actions">
                                <?php if ($annee_editable): ?>
                                    <a href="modifier_seance.php?seance_id=<?php echo (int) $row['id']; ?>&annee_scolaire_id=<?php echo (int) $annee_scolaire_id; ?>" class="btn btn-epim-accent btn-sm mr-1">
                                        <i class="fas fa-pen mr-1"></i>Modifier
                                    </a>
                                    <button type="button"
                                       class="btn btn-sm btn-session-delete"
                                       data-seance-id="<?php echo (int) $row['id']; ?>">
                                        <i class="fas fa-trash mr-1"></i>Supprimer
                                    </button>
                                <?php else: ?>
                                    <span class="text-muted">Archive</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?php
    return (string) ob_get_clean();
}

if ($is_ajax) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success' => true,
        'table_html' => gestion_seances_table_html($unite_id, $seances, $unite_nom, $annee_editable, $annee_scolaire_id),
        'add_action_html' => gestion_seances_actions_html($unite_id, $unite_nom, $annee_editable, $annee_status, $annee_temp_reactivated, $annee_scolaire_id),
        'annee_editable' => $annee_editable,
        'annee_status' => $annee_status,
        'annee_temp_reactivated' => $annee_temp_reactivated,
    ], JSON_UNESCAPED_UNICODE);
    exit();
}
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Séances - EPIM</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <link rel="stylesheet" href="assets/css/theme.css">
    <style>
        .sessions-filter-actions {
            display: flex;
            align-items: center;
            justify-content: flex-start;
            padding-top: 4px;
        }

        .add-session-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            white-space: nowrap;
            min-width: max-content;
            padding: 8px 14px;
            font-size: 0.86rem;
            line-height: 1.15;
        }

        .add-session-archive-badge {
            display: inline-flex;
            align-items: center;
            min-height: 36px;
            white-space: nowrap;
        }

        #sessionsTableWrap.epim-loading {
            opacity: 0.55;
            pointer-events: none;
        }

        .sessions-actions {
            white-space: nowrap;
        }

        .btn-session-delete {
            border: 1.5px solid #E74C3C;
            color: #E74C3C;
            border-radius: 50px;
            font-weight: 600;
            padding: 6px 14px;
        }

        @media (max-width: 991.98px) {
            .sessions-filter-actions {
                align-items: center;
            }
        }
    </style>
</head>

<body>
<div class="container-fluid fade-in">
    <div class="page-header">
        <h2><i class="fas fa-calendar-check text-primary mr-2"></i>Gestion des Séances Pédagogiques</h2>
        <p>Sélectionnez une unité de formation pour visualiser et gérer ses séances.</p>
    </div>

    <!-- Filtre par unité de formation -->
    <div class="epim-card p-3 mb-4">
        <div class="row align-items-end">
            <div class="col-lg-4 col-md-6">
                <label class="mb-1"><i class="fas fa-layer-group mr-1 text-primary"></i> <strong>Unité de formation</strong></label>
                <select class="form-control" id="unite_filter" onchange="changeUnite(this)">
                    <?php foreach ($unites as $u): ?>
                        <option value="<?php echo (int)$u['id']; ?>"
                                data-nom="<?php echo htmlspecialchars($u['intitule'], ENT_QUOTES, 'UTF-8'); ?>"
                                <?php echo $unite_id == $u['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($u['intitule'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                    <?php if (count($unites) === 0): ?>
                        <option value="">Aucune unité assignée</option>
                    <?php endif; ?>
                </select>
            </div>
            <div class="col-lg-4 col-md-6 mt-3 mt-md-0">
                <label class="mb-1"><i class="fas fa-clipboard-check mr-1 text-primary"></i> <strong>Contrôle continu</strong></label>
                <select class="form-control" id="controle_filter" onchange="changeControle(this)">
                    <option value="" <?php echo $controle_filter === '' ? 'selected' : ''; ?>>Toutes les séances</option>
                    <option value="avec" <?php echo $controle_filter === 'avec' ? 'selected' : ''; ?>>Avec contrôle continu</option>
                    <option value="sans" <?php echo $controle_filter === 'sans' ? 'selected' : ''; ?>>Sans contrôle continu</option>
                </select>
            </div>
            <div class="col-lg-4 col-md-6 mt-3 mt-lg-0">
                <label class="mb-1"><i class="fas fa-calendar-alt mr-1 text-primary"></i> <strong>Année scolaire</strong></label>
                <select class="form-control" id="annee_filter" onchange="changeAnnee(this)">
                    <?php foreach ($annees_scolaires as $annee): ?>
                        <option value="<?php echo (int) $annee['id']; ?>" <?php echo $annee_scolaire_id === (int) $annee['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($annee['label'] . ' (' . $annee['statut_label'] . ')', ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 mt-3 sessions-filter-actions" id="addSessionAction">
                <?php echo gestion_seances_actions_html($unite_id, $unite_nom, $annee_editable, $annee_status, $annee_temp_reactivated, $annee_scolaire_id); ?>
            </div>
        </div>
    </div>

    <!-- Tableau des séances -->
    <div class="epim-card p-3" id="sessionsTableWrap">
        <?php echo gestion_seances_table_html($unite_id, $seances, $unite_nom, $annee_editable, $annee_scolaire_id); ?>
    </div>
</div>

<script>
const sessionsTableWrap = document.getElementById('sessionsTableWrap');
const addSessionAction = document.getElementById('addSessionAction');

function buildSeanceParams(includeAjax = true) {
    const unite = document.getElementById('unite_filter');
    const controle = document.getElementById('controle_filter');
    const annee = document.getElementById('annee_filter');
    const params = new URLSearchParams();

    if (includeAjax) {
        params.set('ajax', '1');
    }

    if (unite && unite.value) {
        const option = unite.options[unite.selectedIndex];
        params.set('unite_id', unite.value);
        params.set('unite_nom', option ? (option.dataset.nom || option.textContent.trim()) : '');
    }

    if (controle && controle.value) {
        params.set('controle_continu', controle.value);
    }

    if (annee && annee.value) {
        params.set('annee_scolaire_id', annee.value);
    }

    return params;
}

function updateSeancesUrl() {
    const params = buildSeanceParams(false);
    const query = params.toString();
    history.replaceState(null, '', 'gestion_seances.php' + (query ? '?' + query : ''));
}

async function loadSeances() {
    sessionsTableWrap.classList.add('epim-loading');

    try {
        const response = await fetch('gestion_seances.php?' + buildSeanceParams(true).toString(), {
            headers: { 'Accept': 'application/json' }
        });
        const data = await response.json();

        if (!response.ok || !data.success) {
            throw new Error(data.message || 'Erreur de chargement');
        }

        sessionsTableWrap.innerHTML = data.table_html;
        addSessionAction.innerHTML = data.add_action_html;
        updateSeancesUrl();
    } catch (error) {
        sessionsTableWrap.innerHTML = '<div class="text-center text-danger py-4">Données indisponibles, réessayez plus tard.</div>';
    } finally {
        sessionsTableWrap.classList.remove('epim-loading');
    }
}

function showSeanceMessage(type, message) {
    if (window.toastr && typeof window.toastr[type] === 'function') {
        window.toastr[type](message);
        return;
    }

    alert(message);
}

document.addEventListener('click', async function(event) {
    const deleteButton = event.target.closest('.btn-session-delete');
    if (deleteButton) {
        event.preventDefault();

        const seanceId = deleteButton.dataset.seanceId;
        if (!seanceId) {
            showSeanceMessage('error', 'Séance invalide.');
            return;
        }

        const confirmed = confirm(
            "Supprimer cette séance ?\n\n" +
            "Cette opération supprimera définitivement cette séance.\n\n" +
            "Cette action est irréversible."
        );
        if (!confirmed) {
            return;
        }

        const scrollX = window.scrollX;
        const scrollY = window.scrollY;
        const formData = new FormData();
        formData.append('seance_id', seanceId);

        deleteButton.disabled = true;
        try {
            const response = await fetch('supprimer_seance.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Suppression impossible.');
            }

            showSeanceMessage('success', data.message || 'Séance supprimée avec succès.');
            await loadSeances();
            window.scrollTo(scrollX, scrollY);
        } catch (error) {
            deleteButton.disabled = false;
            showSeanceMessage('error', error.message || 'Suppression impossible.');
        }
        return;
    }

    const button = event.target.closest('#toggleTempYear');
    if (!button) {
        return;
    }

    const annee = document.getElementById('annee_filter');
    if (!annee || !annee.value) {
        return;
    }

    if (button.dataset.action === 'reactivate_temp' && !confirm("Réactiver temporairement cette année scolaire pour compléter vos séances oubliées ?")) {
        return;
    }
    if (button.dataset.action === 'archive_temp' && !confirm("Réarchiver cette année scolaire pour votre session ?")) {
        return;
    }

    const formData = new FormData();
    formData.append('annee_scolaire_id', annee.value);
    formData.append('action', button.dataset.action);

    const response = await fetch('annee_scolaire_temp_action.php', {
        method: 'POST',
        body: formData,
        headers: { 'Accept': 'application/json' }
    });
    const data = await response.json();
    if (!response.ok || !data.success) {
        alert(data.message || 'Action impossible.');
        return;
    }

    loadSeances();
});

function changeUnite() {
    loadSeances();
}

function changeControle() {
    loadSeances();
}

function changeAnnee() {
    loadSeances();
}
</script>

<?php include 'footer.php'; ?>
