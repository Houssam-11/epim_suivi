<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/annees_scolaires.php';
require_once __DIR__ . '/includes/unite_helper.php';

$is_ajax = isset($_GET['ajax']) || isset($_POST['ajax']) || isset($_POST['validation_action']);

if ($is_ajax) {
    header('Content-Type: application/json; charset=UTF-8');
    auth_require_role('directeur');

    include 'db.php';
} else {
    include 'page_directeur.php';
}

unite_ensure_columns($conn);

const VALIDATION_COMMENT_OTHER = '__other__';

function validation_comments(string $action): array
{
    $comments = [
        'valider' => [
            'Séance conforme au programme.',
            'Objectifs pédagogiques atteints.',
            'Travail satisfaisant.',
            'Validation accordée.',
            'Séance réalisée conformément aux attentes.',
            'Progression pédagogique cohérente.',
        ],
        'refuser' => [
            'Informations incomplètes.',
            'Description insuffisante.',
            'Objectifs non cohérents.',
            'Correction demandée.',
            'Séance à revoir.',
            'Validation refusée en attente de correction.',
        ],
    ];

    return $comments[$action] ?? [];
}

function validation_int($value): int
{
    $value = filter_var($value, FILTER_VALIDATE_INT);
    return $value && $value > 0 ? (int) $value : 0;
}

function validation_column_exists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = ?
          AND column_name = ?
    ");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();

    return (int) $count > 0;
}

function validation_has_validator(mysqli $conn): bool
{
    static $hasValidator = null;
    if ($hasValidator === null) {
        $hasValidator = validation_column_exists($conn, 'suivi_pedagogique', 'validateur_id');
    }
    return $hasValidator;
}

function validation_filters(): array
{
    $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $source = $requestMethod === 'POST' ? $_POST : $_GET;
    return [
        'filiere_id' => validation_int($source['filiere_id'] ?? null),
        'unite_id' => validation_int($source['unite_id'] ?? null),
        'sequence_id' => validation_int($source['sequence_id'] ?? null),
        'annee_scolaire_id' => validation_int($source['annee_scolaire_id'] ?? null),
        'show_validated' => isset($source['show_validated']) && $source['show_validated'] !== '0',
        'page' => max(1, validation_int($source['page'] ?? 1)),
    ];
}

function validation_where(array $filters, bool $include_visibility = true): string
{
    $where = '';

    if ($include_visibility && !$filters['show_validated']) {
        $where .= " AND (s.valide_par_directeur = 0 OR s.valide_par_directeur IS NULL)";
    }
    if ($filters['filiere_id']) {
        $where .= " AND f.id = " . (int) $filters['filiere_id'];
    }
    if ($filters['unite_id']) {
        $where .= " AND uf.id = " . (int) $filters['unite_id'];
    }
    if ($filters['sequence_id']) {
        $where .= " AND seq.id = " . (int) $filters['sequence_id'];
    }
    if ($filters['annee_scolaire_id']) {
        $where .= " AND sp.annee_scolaire_id = " . (int) $filters['annee_scolaire_id'];
    }

    return $where;
}

function validation_base_join(): string
{
    return " FROM seances_pedagogiques sp
             LEFT JOIN suivi_pedagogique s ON sp.id = s.seance_id
             LEFT JOIN sequences_pedagogiques seq ON sp.sequence_id = seq.id
             LEFT JOIN unites_de_formation uf ON seq.unite_id = uf.id
             LEFT JOIN filieres f ON uf.filiere_id = f.id
             WHERE 1 = 1";
}

function validation_count(mysqli $conn, string $where): int
{
    $result = $conn->query("SELECT COUNT(*) AS total" . validation_base_join() . $where);
    $row = $result ? $result->fetch_assoc() : ['total' => 0];
    return (int) $row['total'];
}

function validation_status_label(array $row): string
{
    if ((int) ($row['valide_par_directeur'] ?? 0) === 1) {
        return 'validée';
    }

    $comment = trim((string) ($row['commentaire_directeur'] ?? ''));
    return $comment !== '' ? 'refusée' : 'en_attente';
}

function validation_badge(string $status): string
{
    return match ($status) {
        'validée' => '<span class="badge-epim-success"><i class="fas fa-check-circle mr-1"></i>Validée</span>',
        'refusée' => '<span class="badge-epim-danger"><i class="fas fa-times-circle mr-1"></i>Refusée</span>',
        default => '<span class="badge-epim-warning"><i class="fas fa-clock mr-1"></i>En attente</span>',
    };
}

function validation_comment_select(string $scope, int $id): string
{
    ob_start();
    ?>
    <div class="validation-comment-box" data-scope="<?php echo htmlspecialchars($scope, ENT_QUOTES, 'UTF-8'); ?>" data-id="<?php echo (int) $id; ?>">
        <select class="form-control form-control-sm validation-comment-choice">
            <option value="">Choisir un commentaire</option>
            <optgroup label="Validation">
                <?php foreach (validation_comments('valider') as $comment): ?>
                    <option value="<?php echo htmlspecialchars($comment, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($comment, ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </optgroup>
            <optgroup label="Refus">
                <?php foreach (validation_comments('refuser') as $comment): ?>
                    <option value="<?php echo htmlspecialchars($comment, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($comment, ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </optgroup>
            <option value="<?php echo VALIDATION_COMMENT_OTHER; ?>">Autre commentaire</option>
        </select>
        <textarea class="form-control form-control-sm validation-custom-comment mt-2" rows="2" placeholder="Saisir un autre commentaire..." hidden></textarea>
    </div>
    <?php
    return ob_get_clean();
}

function validation_year_context_html(mysqli $conn, int $anneeId): string
{
    $annee = annee_scolaire_period($conn, $anneeId);
    if (!$annee) {
        return '<div class="alert alert-warning mb-4">Année scolaire introuvable.</div>';
    }

    $status = annee_scolaire_normalize_status($annee['statut'] ?? 'archivee');
    $editable = annee_scolaire_is_editable_for_current_user($conn, $anneeId);
    if ($status === 'active') {
        $label = 'Active';
        $class = 'badge-epim-success';
        $message = 'Les actions de validation sont disponibles.';
    } elseif ($editable) {
        $label = 'Réactivée';
        $class = 'badge-epim-orange';
        $message = 'Les actions de validation sont disponibles pour cette année réactivée.';
    } else {
        $label = 'Archivée - Lecture seule';
        $class = 'badge-epim-info';
        $message = 'Consultation uniquement : les validations sont désactivées.';
    }

    ob_start();
    ?>
    <div class="alert alert-light border validation-year-context mb-4">
        <strong>Année scolaire :</strong>
        <?php echo htmlspecialchars((string) $annee['libelle'], ENT_QUOTES, 'UTF-8'); ?>
        <span class="<?php echo $class; ?> ml-2"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></span>
        <span class="text-muted ml-2"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></span>
    </div>
    <?php
    return (string) ob_get_clean();
}

function validation_rows_html(mysqli_result $result, bool $annee_editable): string
{
    ob_start();
    if ($result && $result->num_rows > 0):
        while ($row = $result->fetch_assoc()):
            $status = validation_status_label($row);
            ?>
            <tr>
                <td><?php echo htmlspecialchars($row['unite'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo date('d/m/Y H:i', strtotime($row['date_seance'])); ?></td>
                <td><?php echo htmlspecialchars($row['objectif_pedagogique'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><strong><?php echo htmlspecialchars($row['heures_reelles'], ENT_QUOTES, 'UTF-8'); ?> h</strong></td>
                <td><?php echo number_format(max(0, (float) $row['taux_avancement']), 2, ',', ' '); ?> %</td>
                <td><?php echo validation_badge($status); ?></td>
                <td>
                    <?php if ($annee_editable): ?>
                        <?php echo validation_comment_select('seance', (int) $row['id']); ?>
                    <?php endif; ?>
                    <?php if (trim((string) $row['commentaire_directeur']) !== ''): ?>
                        <div class="small text-muted mt-1">Actuel : <?php echo htmlspecialchars($row['commentaire_directeur'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php elseif (!$annee_editable): ?>
                        <span class="text-muted">Consultation uniquement</span>
                    <?php endif; ?>
                </td>
                <td class="text-center action-cell">
                    <?php if ($annee_editable): ?>
                        <button type="button" class="btn btn-link p-0 mr-2 validation-action" data-scope="seance" data-action="valider" data-id="<?php echo (int) $row['id']; ?>" title="Valider la séance" aria-label="Valider la séance">
                            <i class="fas fa-check-circle text-success"></i>
                        </button>
                        <button type="button" class="btn btn-link p-0 validation-action" data-scope="seance" data-action="refuser" data-id="<?php echo (int) $row['id']; ?>" title="Refuser la séance" aria-label="Refuser la séance">
                            <i class="fas fa-times-circle text-danger"></i>
                        </button>
                    <?php else: ?>
                        <span class="text-muted">-</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endwhile;
    else: ?>
        <tr>
            <td colspan="8" class="text-center text-muted py-4">Aucune séance trouvée.</td>
        </tr>
    <?php endif;
    return ob_get_clean();
}

function validation_module_status(int $total, int $validated, int $refused, int $pending): array
{
    if ($total > 0 && $validated === $total) {
        return ['status' => 'validé', 'label' => 'Module validé', 'badge' => 'badge-epim-success'];
    }
    if ($total > 0 && $refused === $total) {
        return ['status' => 'refusé', 'label' => 'Module refusé', 'badge' => 'badge-epim-danger'];
    }
    if ($total > 0 && $pending === $total) {
        return ['status' => 'attente', 'label' => 'Module en attente', 'badge' => 'badge-epim-warning'];
    }
    return ['status' => 'cours', 'label' => 'Module en cours de traitement', 'badge' => 'badge-epim-info'];
}

function validation_modules_html(mysqli $conn, array $filters, bool $annee_editable): string
{
    $where = '';
    $having = !$filters['show_validated'] ? 'HAVING refused_count > 0 OR pending_count > 0' : '';
    if ($filters['filiere_id']) {
        $where .= " AND f.id = " . (int) $filters['filiere_id'];
    }
    if ($filters['unite_id']) {
        $where .= " AND uf.id = " . (int) $filters['unite_id'];
    }
    if ($filters['sequence_id']) {
        $where .= " AND uf.id IN (
            SELECT uf2.id
            FROM unites_de_formation uf2
            INNER JOIN sequences_pedagogiques seq2 ON seq2.unite_id = uf2.id
            WHERE seq2.id = " . (int) $filters['sequence_id'] . "
        )";
    }
    if ($filters['annee_scolaire_id']) {
        $where .= " AND sp.annee_scolaire_id = " . (int) $filters['annee_scolaire_id'];
    }

    $sql = "
        SELECT uf.id, uf.intitule,
               COUNT(sp.id) AS total,
               SUM(CASE WHEN COALESCE(s.valide_par_directeur, 0) = 1 THEN 1 ELSE 0 END) AS validated_count,
               SUM(CASE WHEN COALESCE(s.valide_par_directeur, 0) = 0 AND TRIM(COALESCE(s.commentaire_directeur, '')) <> '' THEN 1 ELSE 0 END) AS refused_count,
               SUM(CASE WHEN COALESCE(s.valide_par_directeur, 0) = 0 AND TRIM(COALESCE(s.commentaire_directeur, '')) = '' THEN 1 ELSE 0 END) AS pending_count
        FROM unites_de_formation uf
        LEFT JOIN filieres f ON f.id = uf.filiere_id
        INNER JOIN sequences_pedagogiques seq ON seq.unite_id = uf.id
        INNER JOIN seances_pedagogiques sp ON sp.sequence_id = seq.id
        LEFT JOIN suivi_pedagogique s ON s.seance_id = sp.id
        WHERE 1 = 1 $where
        GROUP BY uf.id, uf.intitule
        $having
        ORDER BY uf.intitule
    ";

    $result = $conn->query($sql);
    ob_start();
    if ($result && $result->num_rows > 0): ?>
        <div class="module-validation-grid">
            <?php while ($module = $result->fetch_assoc()):
                $total = (int) $module['total'];
                $validated = (int) $module['validated_count'];
                $refused = (int) $module['refused_count'];
                $pending = (int) $module['pending_count'];
                $status = validation_module_status($total, $validated, $refused, $pending);
                ?>
                <article class="epim-card no-hover module-validation-card">
                    <div class="module-card-header">
                        <div>
                            <h3><?php echo htmlspecialchars($module['intitule'], ENT_QUOTES, 'UTF-8'); ?></h3>
                            <p><?php echo $total; ?> séance<?php echo $total > 1 ? 's' : ''; ?></p>
                        </div>
                        <span class="<?php echo $status['badge']; ?>"><?php echo htmlspecialchars($status['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                    <div class="module-card-counts">
                        <span><?php echo $validated; ?> validée<?php echo $validated > 1 ? 's' : ''; ?></span>
                        <span><?php echo $refused; ?> refusée<?php echo $refused > 1 ? 's' : ''; ?></span>
                        <span><?php echo $pending; ?> en attente</span>
                    </div>
                    <?php if ($annee_editable): ?>
                        <?php echo validation_comment_select('module', (int) $module['id']); ?>
                        <div class="module-card-actions">
                            <button type="button" class="btn btn-epim-primary btn-sm validation-action" data-scope="module" data-action="valider" data-id="<?php echo (int) $module['id']; ?>" title="Valider toutes les séances de ce module" aria-label="Valider toutes les séances de ce module">
                                <i class="fas fa-check mr-1"></i>Valider le module
                            </button>
                            <button type="button" class="btn btn-outline-danger-epim btn-sm validation-action" data-scope="module" data-action="refuser" data-id="<?php echo (int) $module['id']; ?>" title="Refuser toutes les séances de ce module" aria-label="Refuser toutes les séances de ce module">
                                <i class="fas fa-times mr-1"></i>Refuser le module
                            </button>
                        </div>
                    <?php else: ?>
                        <div class="text-muted small mt-3">Consultation uniquement pour cette année scolaire.</div>
                    <?php endif; ?>
                </article>
            <?php endwhile; ?>
        </div>
    <?php else: ?>
        <div class="text-center text-muted py-4">Aucun module à traiter pour ces filtres.</div>
    <?php endif;
    return ob_get_clean();
}

function validation_pagination_html(int $page, int $total_pages): string
{
    $total_pages = max(1, $total_pages);
    $page = min(max(1, $page), $total_pages);
    $window = 5;
    $start = max(1, $page - 2);
    $end = min($total_pages, $start + $window - 1);
    $start = max(1, $end - $window + 1);

    ob_start();
    ?>
        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
            <?php if ($page <= 1): ?>
                <span class="page-link" aria-disabled="true">&lsaquo;</span>
            <?php else: ?>
                <a class="page-link validation-page-link" href="#" data-page="<?php echo $page - 1; ?>" title="Page précédente" aria-label="Page précédente">&lsaquo;</a>
            <?php endif; ?>
        </li>
    <?php
    if ($start > 1): ?>
        <li class="page-item">
            <a class="page-link validation-page-link" href="#" data-page="1" title="Page 1" aria-label="Aller à la page 1">1</a>
        </li>
        <?php if ($start > 2): ?>
            <li class="page-item disabled"><span class="page-link" aria-disabled="true">...</span></li>
        <?php endif; ?>
    <?php endif;

    for ($page_num = $start; $page_num <= $end; $page_num++): ?>
        <li class="page-item <?php echo $page === $page_num ? 'active' : ''; ?>">
            <a class="page-link validation-page-link" href="#" data-page="<?php echo $page_num; ?>" title="Page <?php echo $page_num; ?>" aria-label="Aller à la page <?php echo $page_num; ?>"><?php echo $page_num; ?></a>
        </li>
    <?php endfor;

    if ($end < $total_pages): ?>
        <?php if ($end < $total_pages - 1): ?>
            <li class="page-item disabled"><span class="page-link" aria-disabled="true">...</span></li>
        <?php endif; ?>
        <li class="page-item">
            <a class="page-link validation-page-link" href="#" data-page="<?php echo $total_pages; ?>" title="Page <?php echo $total_pages; ?>" aria-label="Aller à la page <?php echo $total_pages; ?>"><?php echo $total_pages; ?></a>
        </li>
    <?php endif; ?>

        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
            <?php if ($page >= $total_pages): ?>
                <span class="page-link" aria-disabled="true">&rsaquo;</span>
            <?php else: ?>
                <a class="page-link validation-page-link" href="#" data-page="<?php echo $page + 1; ?>" title="Page suivante" aria-label="Page suivante">&rsaquo;</a>
            <?php endif; ?>
        </li>
    <?php
    return ob_get_clean();
}

function validation_comment_from_request(string $action): string
{
    $choice = trim((string) ($_POST['commentaire_predefini'] ?? ''));
    $custom = trim((string) ($_POST['commentaire_custom'] ?? ''));

    if ($choice === VALIDATION_COMMENT_OTHER) {
        return $custom;
    }
    if ($choice !== '') {
        return $choice;
    }

    $fallbacks = validation_comments($action);
    return $fallbacks[0] ?? ($action === 'valider' ? 'Validation accordée.' : 'Correction demandée.');
}

function validation_apply_to_sessions(mysqli $conn, array $sessionIds, int $status, string $comment, ?int $validatorId): int
{
    if (!$sessionIds) {
        return 0;
    }

    $hasValidator = validation_has_validator($conn);
    $select = $conn->prepare("SELECT id FROM suivi_pedagogique WHERE seance_id = ? LIMIT 1");
    $updateSql = $hasValidator
        ? "UPDATE suivi_pedagogique SET valide_par_directeur = ?, commentaire_directeur = ?, date_validation = NOW(), validateur_id = ? WHERE seance_id = ?"
        : "UPDATE suivi_pedagogique SET valide_par_directeur = ?, commentaire_directeur = ?, date_validation = NOW() WHERE seance_id = ?";
    $insertSql = $hasValidator
        ? "INSERT INTO suivi_pedagogique (seance_id, heures_cumulees, taux_realisation, commentaire_directeur, valide_par_directeur, date_validation, validateur_id) VALUES (?, 0, 0, ?, ?, NOW(), ?)"
        : "INSERT INTO suivi_pedagogique (seance_id, heures_cumulees, taux_realisation, commentaire_directeur, valide_par_directeur, date_validation) VALUES (?, 0, 0, ?, ?, NOW())";
    $update = $conn->prepare($updateSql);
    $insert = $conn->prepare($insertSql);

    if (!$select || !$update || !$insert) {
        throw new RuntimeException('Impossible de préparer la validation.');
    }

    $affected = 0;
    foreach ($sessionIds as $sessionId) {
        $sessionId = (int) $sessionId;
        $select->bind_param('i', $sessionId);
        $select->execute();
        $select->store_result();
        $exists = $select->num_rows > 0;
        $select->free_result();

        if ($exists) {
            if ($hasValidator) {
                $update->bind_param('isii', $status, $comment, $validatorId, $sessionId);
            } else {
                $update->bind_param('isi', $status, $comment, $sessionId);
            }
            $update->execute();
        } else {
            if ($hasValidator) {
                $insert->bind_param('isii', $sessionId, $comment, $status, $validatorId);
            } else {
                $insert->bind_param('isi', $sessionId, $comment, $status);
            }
            $insert->execute();
        }
        $affected++;
    }

    $select->close();
    $update->close();
    $insert->close();

    return $affected;
}

function validation_handle_action(mysqli $conn): void
{
    $scope = $_POST['scope'] ?? '';
    $action = $_POST['validation_action'] ?? '';
    $id = validation_int($_POST['target_id'] ?? null);

    if (!in_array($scope, ['seance', 'module'], true) || !in_array($action, ['valider', 'refuser'], true) || !$id) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Action invalide.'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $comment = validation_comment_from_request($action);
    if ($comment === '') {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Veuillez choisir ou saisir un commentaire.'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $status = $action === 'valider' ? 1 : 0;
    $validatorId = isset($_SESSION['id']) ? (int) $_SESSION['id'] : null;
    $sessionIds = [];

    if ($scope === 'seance') {
        $stmt = $conn->prepare("
            SELECT sp.id
            FROM seances_pedagogiques sp
            INNER JOIN annees_scolaires a ON a.id = sp.annee_scolaire_id
            WHERE sp.id = ? AND a.statut <> 'archivee'
        ");
        $stmt->bind_param('i', $id);
    } else {
        $stmt = $conn->prepare("
            SELECT sp.id
            FROM seances_pedagogiques sp
            INNER JOIN sequences_pedagogiques seq ON seq.id = sp.sequence_id
            INNER JOIN annees_scolaires a ON a.id = sp.annee_scolaire_id
            WHERE seq.unite_id = ? AND a.statut <> 'archivee'
        ");
        $stmt->bind_param('i', $id);
    }

    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Impossible de traiter la demande.'], JSON_UNESCAPED_UNICODE);
        exit();
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $sessionIds[] = (int) $row['id'];
    }
    $stmt->close();

    if (!$sessionIds) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Aucune séance trouvée.'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    try {
        $conn->begin_transaction();
        $affected = validation_apply_to_sessions($conn, $sessionIds, $status, $comment, $validatorId);
        $conn->commit();
    } catch (Throwable $exception) {
        $conn->rollback();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Erreur lors de la validation.'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    echo json_encode([
        'success' => true,
        'message' => $scope === 'module'
            ? ($action === 'valider' ? 'Module validé avec succès.' : 'Module refusé avec succès.')
            : ($action === 'valider' ? 'Séance validée avec succès.' : 'Séance refusée avec succès.'),
        'affected' => $affected,
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

function validation_handle_action_safe(mysqli $conn): void
{
    $scope = $_POST['scope'] ?? '';
    $action = $_POST['validation_action'] ?? '';
    $id = validation_int($_POST['target_id'] ?? null);

    if (!in_array($scope, ['seance', 'module'], true) || !in_array($action, ['valider', 'refuser'], true) || !$id) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Action invalide.'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $comment = validation_comment_from_request($action);
    if ($comment === '') {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Veuillez choisir ou saisir un commentaire.'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $status = $action === 'valider' ? 1 : 0;
    $validatorId = isset($_SESSION['id']) ? (int) $_SESSION['id'] : null;
    $sessionIds = [];

    if ($scope === 'seance') {
        $stmt = $conn->prepare("
            SELECT sp.id
            FROM seances_pedagogiques sp
            INNER JOIN annees_scolaires a ON a.id = sp.annee_scolaire_id
            WHERE sp.id = ? AND a.statut <> 'archivee'
        ");
    } else {
        $stmt = $conn->prepare("
            SELECT sp.id
            FROM seances_pedagogiques sp
            INNER JOIN sequences_pedagogiques seq ON seq.id = sp.sequence_id
            INNER JOIN annees_scolaires a ON a.id = sp.annee_scolaire_id
            WHERE seq.unite_id = ? AND a.statut <> 'archivee'
        ");
    }

    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Impossible de traiter la demande.'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $sessionIds[] = (int) $row['id'];
    }
    $stmt->close();

    if (!$sessionIds) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Aucune séance trouvée.'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    try {
        $conn->begin_transaction();
        $affected = validation_apply_to_sessions($conn, $sessionIds, $status, $comment, $validatorId);
        $conn->commit();
    } catch (Throwable $exception) {
        $conn->rollback();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Erreur lors de la validation.'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    echo json_encode([
        'success' => true,
        'message' => $scope === 'module'
            ? ($action === 'valider' ? 'Module validé avec succès.' : 'Module refusé avec succès.')
            : ($action === 'valider' ? 'Séance validée avec succès.' : 'Séance refusée avec succès.'),
        'affected' => $affected,
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

if (isset($_POST['validation_action'])) {
    validation_handle_action_safe($conn);
}

$filters = validation_filters();
$filters['annee_scolaire_id'] = annee_scolaire_selected_id($conn, $filters['annee_scolaire_id']);
$annee_editable = annee_scolaire_is_editable_for_current_user($conn, $filters['annee_scolaire_id']);
$annee_context_html = validation_year_context_html($conn, $filters['annee_scolaire_id']);
$results_per_page = 10;
$offset = ($filters['page'] - 1) * $results_per_page;

$visible_where = validation_where($filters, true);
$stats_where = validation_where($filters, false);

$total_results = validation_count($conn, $visible_where);
$total_pages = max(1, (int) ceil($total_results / $results_per_page));
if ($filters['page'] > $total_pages) {
    $filters['page'] = $total_pages;
    $offset = ($filters['page'] - 1) * $results_per_page;
}

$sql = "SELECT sp.id, sp.date_seance, sp.objectif_pedagogique, sp.heures_reelles,
               s.valide_par_directeur, s.commentaire_directeur, uf.intitule AS unite,
               CASE
                   WHEN COALESCE(uf.masse_horaire, 0) > 0 THEN
                       (
                           SELECT COALESCE(SUM(GREATEST(sp2.heures_reelles, 0)), 0)
                           FROM seances_pedagogiques sp2
                           INNER JOIN sequences_pedagogiques seq2 ON sp2.sequence_id = seq2.id
                           WHERE seq2.unite_id = uf.id
                             AND sp2.annee_scolaire_id = sp.annee_scolaire_id
                       ) / uf.masse_horaire * 100
                   ELSE 0
               END AS taux_avancement"
     . validation_base_join()
     . $visible_where
     . " ORDER BY sp.date_seance DESC LIMIT $results_per_page OFFSET $offset";
$result = $conn->query($sql);

$stats = [
    'total' => validation_count($conn, $stats_where),
    'attente' => validation_count($conn, $stats_where . " AND COALESCE(s.valide_par_directeur, 0) = 0 AND TRIM(COALESCE(s.commentaire_directeur, '')) = ''"),
    'validees' => validation_count($conn, $stats_where . " AND COALESCE(s.valide_par_directeur, 0) = 1"),
    'refusees' => validation_count($conn, $stats_where . " AND COALESCE(s.valide_par_directeur, 0) = 0 AND TRIM(COALESCE(s.commentaire_directeur, '')) <> ''"),
];

$rows_html = validation_rows_html($result, $annee_editable);
$modules_html = validation_modules_html($conn, $filters, $annee_editable);
$pagination_html = validation_pagination_html($filters['page'], $total_pages);

if ($is_ajax) {
    echo json_encode([
        'success' => true,
        'rows_html' => $rows_html,
        'modules_html' => $modules_html,
        'annee_context_html' => $annee_context_html,
        'pagination_html' => $pagination_html,
        'stats' => $stats,
        'page' => $filters['page'],
        'total_pages' => $total_pages,
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

$result_filieres = $conn->query("SELECT id, nom FROM filieres ORDER BY nom");
$result_unites = $conn->query("SELECT id, intitule, COALESCE(semestre, 1) AS semestre FROM unites_de_formation ORDER BY intitule");
$result_sequences = $conn->query("SELECT id, intitule FROM sequences_pedagogiques ORDER BY intitule");
$annees_scolaires = annee_scolaire_options($conn);
?>

<style>
    .module-validation-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
        gap: 16px;
    }

    .module-validation-scroll {
        max-height: 520px;
        overflow-y: auto;
        overflow-x: hidden;
        padding-right: 4px;
        scrollbar-gutter: stable;
    }

    .validation-table-scroll {
        max-height: 620px;
        overflow-x: auto;
        overflow-y: auto;
        scrollbar-gutter: stable;
    }

    .validation-table-scroll .validation-table {
        min-width: 1180px;
        margin-bottom: 0;
        border-collapse: separate;
        border-spacing: 0;
        overflow: visible;
    }

    .validation-table-scroll .validation-table thead th {
        position: sticky;
        top: 0;
        z-index: 5;
        color: #fff;
        background: linear-gradient(90deg, var(--epim-blue) 0%, #1064c4 100%);
        box-shadow: 0 1px 0 rgba(15, 23, 42, 0.08);
    }

    #validationPagination {
        flex-wrap: wrap;
        gap: 4px;
    }

    .validation-stats-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 12px;
    }

    .validation-stats-grid .stat-card {
        min-height: 92px;
        padding: 14px 16px;
        border-radius: 10px;
    }

    .validation-stats-grid .stat-card i {
        font-size: 1.15rem;
        margin-bottom: 6px;
    }

    .validation-stats-grid .stat-value {
        font-size: 1.65rem;
        line-height: 1.1;
        margin-bottom: 2px;
    }

    .validation-stats-grid .stat-label {
        font-size: .82rem;
        line-height: 1.2;
        white-space: normal;
    }

    .module-validation-card {
        padding: 16px;
    }

    .module-card-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 12px;
    }

    .module-card-header h3 {
        margin: 0;
        font-size: 1rem;
        font-weight: 700;
    }

    .module-card-header p,
    .module-card-counts {
        color: var(--epim-text-muted, #6b7280);
        font-size: .88rem;
    }

    .module-card-counts {
        display: flex;
        flex-wrap: wrap;
        gap: 8px 12px;
        margin: 12px 0;
    }

    .module-card-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 12px;
    }

    .validation-comment-box select,
    .validation-comment-box textarea {
        min-width: 220px;
    }

    .action-cell .validation-action {
        font-size: 1.2rem;
    }

    @media (max-width: 576px) {
        .validation-stats-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .module-card-header {
            flex-direction: column;
        }

        .validation-comment-box select,
        .validation-comment-box textarea {
            min-width: 180px;
        }
    }
</style>

<div class="container-fluid fade-in">
    <div class="page-header">
        <h1 class="page-title">Gestion des séances</h1>
        <p class="page-subtitle">Valider les séances individuellement ou traiter un module complet.</p>
    </div>

    <div id="validationYearContext">
        <?php echo $annee_context_html; ?>
    </div>

    <section class="validation-stats-grid mb-4" id="validationStats">
        <article class="stat-card bg-blue">
            <i class="fas fa-calendar-alt"></i>
            <div class="stat-value" data-stat="total"><?php echo (int) $stats['total']; ?></div>
            <div class="stat-label">Séances filtrées</div>
        </article>
        <article class="stat-card bg-orange">
            <i class="fas fa-hourglass-half"></i>
            <div class="stat-value" data-stat="attente"><?php echo (int) $stats['attente']; ?></div>
            <div class="stat-label">En attente</div>
        </article>
        <article class="stat-card bg-dark">
            <i class="fas fa-check-circle"></i>
            <div class="stat-value" data-stat="validees"><?php echo (int) $stats['validees']; ?></div>
            <div class="stat-label">Validées</div>
        </article>
        <article class="stat-card bg-blue">
            <i class="fas fa-times-circle"></i>
            <div class="stat-value" data-stat="refusees"><?php echo (int) $stats['refusees']; ?></div>
            <div class="stat-label">Refusées</div>
        </article>
    </section>

    <div class="epim-card no-hover p-4 mb-4">
        <div class="section-header">
            <span class="badge-epim-info" id="validationState" hidden>À jour</span>
        </div>
        <form id="validationFilters">
            <div class="form-row">
                <div class="form-group col-lg-4">
                    <label for="filiere_id">Filière</label>
                    <select class="form-control" id="filiere_id" name="filiere_id">
                        <option value="">Toutes les filières</option>
                        <?php while ($row_filieres = $result_filieres->fetch_assoc()): ?>
                            <option value="<?php echo (int) $row_filieres['id']; ?>" <?php echo $filters['filiere_id'] === (int) $row_filieres['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($row_filieres['nom'], ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-group col-lg-4">
                    <label for="unite_id">Unité de formation</label>
                    <select class="form-control" id="unite_id" name="unite_id">
                        <option value="">Toutes les unités</option>
                        <?php while ($row_unites = $result_unites->fetch_assoc()): ?>
                            <option value="<?php echo (int) $row_unites['id']; ?>" <?php echo $filters['unite_id'] === (int) $row_unites['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($row_unites['intitule'], ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-group col-lg-4">
                    <label for="sequence_id">Séquence</label>
                    <select class="form-control" id="sequence_id" name="sequence_id">
                        <option value="">Toutes les séquences</option>
                        <?php while ($row_sequences = $result_sequences->fetch_assoc()): ?>
                            <option value="<?php echo (int) $row_sequences['id']; ?>" <?php echo $filters['sequence_id'] === (int) $row_sequences['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($row_sequences['intitule'] ?: ('Séquence #' . $row_sequences['id']), ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-group col-lg-4">
                    <label for="annee_scolaire_id">Année scolaire</label>
                    <select class="form-control" id="annee_scolaire_id" name="annee_scolaire_id">
                        <?php foreach ($annees_scolaires as $annee): ?>
                            <option value="<?php echo (int) $annee['id']; ?>" data-label="<?php echo htmlspecialchars($annee['label'], ENT_QUOTES, 'UTF-8'); ?>" data-statut="<?php echo htmlspecialchars($annee['statut'], ENT_QUOTES, 'UTF-8'); ?>" <?php echo $filters['annee_scolaire_id'] === (int) $annee['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($annee['label'] . ' (' . $annee['statut_label'] . ')', ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group col-lg-4 d-flex align-items-end" style="gap:8px;">
                    <button type="button" class="btn btn-outline-danger-epim" id="validationArchiveYearBtn" hidden>
                        <i class="fas fa-archive mr-1"></i>Archiver
                    </button>
                    <button type="button" class="btn btn-epim-primary" id="validationReactivateYearBtn" hidden>
                        <i class="fas fa-undo mr-1"></i>Réactiver
                    </button>
                </div>
                <div class="form-group col-lg-4 d-flex align-items-center pt-lg-3">
                    <div class="custom-control custom-checkbox">
                        <input type="checkbox" class="custom-control-input" id="show_validated" name="show_validated" value="1" <?php echo $filters['show_validated'] ? 'checked' : ''; ?>>
                        <label class="custom-control-label" for="show_validated">Afficher les séances validées/refusées</label>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <section class="epim-card no-hover p-3 mb-4">
        <div class="section-header px-1">
            <div>
                <h2>Validation groupée par module</h2>
                <p>Un module correspond à une unité de formation.</p>
            </div>
        </div>
        <div id="moduleValidationList" class="module-validation-scroll">
            <?php echo $modules_html; ?>
        </div>
    </section>

    <div class="epim-card no-hover p-3">
        <div class="validation-table-scroll">
            <table class="table epim-table table-borderless validation-table mb-0">
                <thead>
                    <tr>
                        <th>Unité de formation</th>
                        <th>Date de la séance</th>
                        <th>Objectif pédagogique</th>
                        <th>Heures réalisées</th>
                        <th>Taux d'avancement</th>
                        <th>Validation</th>
                        <th>Commentaire</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody id="validationRows">
                    <?php echo $rows_html; ?>
                </tbody>
            </table>
        </div>
    </div>

    <nav aria-label="Pagination des séances" class="mt-3">
        <ul class="pagination" id="validationPagination">
            <?php echo $pagination_html; ?>
        </ul>
    </nav>
</div>

<script>
$(function() {
    var currentPage = <?php echo (int) $filters['page']; ?>;
    var pendingRequest = null;
    var archiveYearBtn = $('#validationArchiveYearBtn');
    var reactivateYearBtn = $('#validationReactivateYearBtn');

    function setValidationState(text, className) {
        $('#validationState').attr('class', className).text(text);
    }

    function collectFilters(page) {
        var params = new URLSearchParams();
        params.set('ajax', '1');
        params.set('page', page || 1);

        ['filiere_id', 'unite_id', 'sequence_id', 'annee_scolaire_id'].forEach(function(name) {
            var value = $('#' + name).val();
            if (value) {
                params.set(name, value);
            }
        });

        if ($('#show_validated').is(':checked')) {
            params.set('show_validated', '1');
        }

        return params;
    }

    function updateStats(stats) {
        Object.keys(stats).forEach(function(key) {
            $('[data-stat="' + key + '"]').text(stats[key]);
        });
    }

    function statusLabel(status) {
        if (status === 'active') {
            return 'Active';
        }
        if (status === 'preparee') {
            return 'Réactivée';
        }
        return 'Archivée';
    }

    function updateYearActionButtons() {
        var option = $('#annee_scolaire_id option:selected');
        var status = option.data('statut') || '';
        archiveYearBtn.prop('hidden', !option.length || status === 'archivee');
        reactivateYearBtn.prop('hidden', !option.length || status !== 'archivee');
    }

    function setSelectedYearStatus(status) {
        var option = $('#annee_scolaire_id option:selected');
        var label = option.data('label') || option.text().replace(/\s*\(.*\)\s*$/, '');
        option.data('statut', status).attr('data-statut', status);
        option.text(label + ' (' + statusLabel(status) + ')');
        updateYearActionButtons();
    }

    function changeSelectedYearStatus(action) {
        var yearId = $('#annee_scolaire_id').val();
        if (!yearId) {
            toastr.error('Veuillez sélectionner une année scolaire.');
            return;
        }

        var message = action === 'archive'
            ? "Archiver cette année scolaire ?\n\nElle restera consultable, mais les validations seront désactivées."
            : "Réactiver cette année scolaire ?\n\nLes actions de validation seront à nouveau disponibles.";
        if (!confirm(message)) {
            return;
        }

        var formData = new FormData();
        formData.append('academic_action', action);
        formData.append('annee_scolaire_id', yearId);

        archiveYearBtn.prop('disabled', true);
        reactivateYearBtn.prop('disabled', true);
        $.ajax({
            url: 'dashboard_directeur_data.php',
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(response) {
                if (!response.success) {
                    toastr.error(response.message || 'Action impossible.');
                    return;
                }
                toastr.success(response.message || 'Statut mis à jour.');
                setSelectedYearStatus(action === 'archive' ? 'archivee' : 'preparee');
                loadValidation(1);
            },
            error: function(xhr) {
                var message = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Action impossible.';
                toastr.error(message);
            },
            complete: function() {
                archiveYearBtn.prop('disabled', false);
                reactivateYearBtn.prop('disabled', false);
            }
        });
    }

    function loadValidation(page) {
        currentPage = page || 1;
        setValidationState('Chargement', 'badge-epim-info');
        $('#validationRows, #moduleValidationList').addClass('epim-loading');

        if (pendingRequest) {
            pendingRequest.abort();
        }

        pendingRequest = $.ajax({
            url: 'validation_seances.php?' + collectFilters(currentPage).toString(),
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                if (!response.success) {
                    setValidationState('Erreur', 'badge-epim-danger');
                    return;
                }

                $('#validationRows').html(response.rows_html);
                $('#moduleValidationList').html(response.modules_html);
                $('#validationYearContext').html(response.annee_context_html);
                $('#validationPagination').html(response.pagination_html);
                updateStats(response.stats);
                currentPage = response.page;
                updateYearActionButtons();
                setValidationState('À jour', 'badge-epim-success');
            },
            error: function(xhr, status) {
                if (status !== 'abort') {
                    setValidationState('Erreur', 'badge-epim-danger');
                }
            },
            complete: function() {
                $('#validationRows, #moduleValidationList').removeClass('epim-loading');
                pendingRequest = null;
            }
        });
    }

    function selectedComment(box) {
        var choice = box.find('.validation-comment-choice').val();
        if (choice === '<?php echo VALIDATION_COMMENT_OTHER; ?>') {
            return {
                choice: choice,
                custom: box.find('.validation-custom-comment').val().trim()
            };
        }
        return { choice: choice, custom: '' };
    }

    $(document).on('change', '.validation-comment-choice', function() {
        var box = $(this).closest('.validation-comment-box');
        var isOther = $(this).val() === '<?php echo VALIDATION_COMMENT_OTHER; ?>';
        box.find('.validation-custom-comment').prop('hidden', !isOther);
        if (isOther) {
            box.find('.validation-custom-comment').focus();
        }
    });

    $(document).on('click', '.validation-action', function() {
        var button = $(this);
        var scope = button.data('scope');
        var action = button.data('action');
        var id = button.data('id');
        var box = $('.validation-comment-box[data-scope="' + scope + '"][data-id="' + id + '"]');
        var comment = selectedComment(box);

        if (!comment.choice || (comment.choice === '<?php echo VALIDATION_COMMENT_OTHER; ?>' && !comment.custom)) {
            toastr.error('Veuillez choisir ou saisir un commentaire.');
            return;
        }

        button.prop('disabled', true);
        $.ajax({
            url: 'validation_seances.php',
            method: 'POST',
            dataType: 'json',
            data: {
                validation_action: action,
                scope: scope,
                target_id: id,
                commentaire_predefini: comment.choice,
                commentaire_custom: comment.custom
            },
            success: function(response) {
                if (response.success) {
                    toastr.success(response.message || 'Mise à jour effectuée.');
                    loadValidation(currentPage);
                } else {
                    toastr.error(response.message || 'Erreur lors de la mise à jour.');
                }
            },
            error: function(xhr) {
                var message = 'Erreur lors de la mise à jour.';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    message = xhr.responseJSON.message;
                }
                toastr.error(message);
            },
            complete: function() {
                button.prop('disabled', false);
            }
        });
    });

    $('#filiere_id').on('change', function() {
        var filiereId = $(this).val();
        $('#sequence_id').html('<option value="">Toutes les séquences</option>');

        if (filiereId) {
            $.ajax({
                type: 'POST',
                url: 'get_unites.php',
                data: { filiere_id: filiereId },
                success: function(html) {
                    html = html.replace(/<option value="">.*?<\/option>/, '<option value="">Toutes les unités</option>');
                    $('#unite_id').html(html);
                    loadValidation(1);
                }
            });
        } else {
            $('#unite_id').html('<option value="">Toutes les unités</option>');
            loadValidation(1);
        }
    });

    $('#unite_id').on('change', function() {
        var uniteId = $(this).val();

        if (uniteId) {
            $.ajax({
                type: 'POST',
                url: 'get_sequences.php',
                data: { unite_id: uniteId },
                success: function(html) {
                    html = html.replace(/<option value="">.*?<\/option>/, '<option value="">Toutes les séquences</option>');
                    $('#sequence_id').html(html);
                    loadValidation(1);
                }
            });
        } else {
            $('#sequence_id').html('<option value="">Toutes les séquences</option>');
            loadValidation(1);
        }
    });

    $('#sequence_id, #annee_scolaire_id, #show_validated').on('change', function() {
        updateYearActionButtons();
        loadValidation(1);
    });

    archiveYearBtn.on('click', function() {
        changeSelectedYearStatus('archive');
    });

    reactivateYearBtn.on('click', function() {
        changeSelectedYearStatus('reactivate');
    });

    $(document).on('click', '.validation-page-link', function(event) {
        event.preventDefault();
        loadValidation(parseInt($(this).data('page'), 10) || 1);
    });

    updateYearActionButtons();
});
</script>

<?php include 'footer.php'; ?>
