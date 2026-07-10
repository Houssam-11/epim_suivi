<?php
include 'page_directeur.php';
require_once __DIR__ . '/includes/filiere_helper.php';
require_once __DIR__ . '/includes/unite_helper.php';

filiere_ensure_columns($conn);
unite_ensure_columns($conn);

$anneesFormation = filiere_annees_formation_options();
$anneesUniteFormation = unite_annees_formation_options();
$filiere_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$message = $_GET['message'] ?? null;
$unit_archive_filter = $_GET['unit_archive_filter'] ?? 'active';
if (!in_array($unit_archive_filter, ['active', 'archived', 'all'], true)) {
    $unit_archive_filter = 'active';
}
$unit_semestre_filter = $_GET['unit_semestre_filter'] ?? 'all';
if (!in_array($unit_semestre_filter, ['all', '1', '2'], true)) {
    $unit_semestre_filter = 'all';
}
$unit_annee_filter = unite_normalize_annee_formation($_GET['unit_annee_filter'] ?? 0);

$sql_filiere = "SELECT * FROM filieres WHERE id = ?";
$stmt_filiere = $conn->prepare($sql_filiere);
$stmt_filiere->bind_param("i", $filiere_id);
$stmt_filiere->execute();
$filiere = $stmt_filiere->get_result()->fetch_assoc();

if (!$filiere) {
    echo '<div class="alert alert-danger">Filière introuvable.</div>';
    include 'footer.php';
    exit();
}

$filiere_archived = (int) ($filiere['is_archived'] ?? 0) === 1;

$sql_unites = "SELECT uf.*, fo.nom AS formateur_nom
               FROM unites_de_formation uf
               LEFT JOIN formateurs fo ON uf.formateur_id = fo.id
               WHERE uf.filiere_id = ?
               ORDER BY uf.intitule";
$stmt_unites = $conn->prepare($sql_unites);
$stmt_unites->bind_param("i", $filiere_id);
$stmt_unites->execute();
$result_unites = $stmt_unites->get_result();

$sql_formateurs = "SELECT id, nom FROM formateurs ORDER BY nom";
$result_formateurs = $conn->query($sql_formateurs);
?>

<div class="container-fluid fade-in">
    <div class="page-header d-flex flex-column flex-md-row justify-content-between align-items-md-end">
        <div>
            <h1 class="page-title">Modifier la filière</h1>
            <p class="page-subtitle">
                <?php echo htmlspecialchars($filiere['nom'], ENT_QUOTES, 'UTF-8'); ?>
                <?php if ($filiere_archived): ?>
                    <span class="badge-epim-danger ml-2">Archivée</span>
                <?php endif; ?>
            </p>
        </div>
        <div class="d-flex flex-wrap mt-3 mt-md-0" style="gap:8px;">
            <a href="liste_filieres.php" class="btn btn-outline-epim">
                <i class="fas fa-arrow-left mr-1"></i>Retour
            </a>
            <form action="archive_filiere.php" method="POST" class="mb-0" onsubmit="return confirmFiliereArchive(<?php echo $filiere_archived ? 'true' : 'false'; ?>);">
                <input type="hidden" name="id" value="<?php echo (int) $filiere['id']; ?>">
                <input type="hidden" name="archive_action" value="<?php echo $filiere_archived ? 'reactivate' : 'archive'; ?>">
                <?php if ($filiere_archived): ?>
                    <button type="submit" class="btn btn-epim-primary">
                        <i class="fas fa-undo mr-1"></i>Réactiver la filière
                    </button>
                <?php else: ?>
                    <button type="submit" class="btn btn-outline-danger-epim">
                        <i class="fas fa-archive mr-1"></i>Archiver cette filière
                    </button>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <?php if ($filiere_archived): ?>
        <div class="alert alert-warning">
            Cette filière est archivée. Elle reste consultable, mais les modifications, ajouts et imports sont désactivés jusqu'à sa réactivation.
        </div>
    <?php endif; ?>

    <div class="epim-card no-hover p-4 mb-4">
        <div class="section-header">
            <div>
                <h2>Informations générales</h2>
                <p>Modifier les informations principales de la filière.</p>
            </div>
        </div>
        <form action="modifier_filiere_action.php" method="POST">
            <input type="hidden" name="id" value="<?php echo (int) $filiere['id']; ?>">
            <div class="form-row">
                <div class="form-group col-lg-5">
                    <label for="nom">Nom de la filière</label>
                    <input type="text" id="nom" name="nom" class="form-control" value="<?php echo htmlspecialchars($filiere['nom'], ENT_QUOTES, 'UTF-8'); ?>" required <?php echo $filiere_archived ? 'disabled' : ''; ?>>
                </div>
                <div class="form-group col-lg-3">
                    <label for="niveau">Niveau</label>
                    <select id="niveau" name="niveau" class="form-control" <?php echo $filiere_archived ? 'disabled' : ''; ?>>
                        <option value="Technicien" <?php echo $filiere['niveau'] === 'Technicien' ? 'selected' : ''; ?>>Technicien</option>
                        <option value="Technicien Spécialisé" <?php echo $filiere['niveau'] === 'Technicien Spécialisé' ? 'selected' : ''; ?>>Technicien Spécialisé</option>
                    </select>
                </div>
                <div class="form-group col-lg-4">
                    <label for="annee_formation">Durée de la formation</label>
                    <select id="annee_formation" name="annee_formation" class="form-control" required <?php echo $filiere_archived ? 'disabled' : ''; ?>>
                        <option value="">Sélectionner...</option>
                        <?php foreach ($anneesFormation as $anneeFormationOption): ?>
                            <option value="<?php echo (int) $anneeFormationOption; ?>" <?php echo filiere_normalize_annee_formation($filiere['annee_formation'] ?? 1) === (int) $anneeFormationOption ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(filiere_duree_formation_label($anneeFormationOption), ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <button type="submit" class="btn btn-epim-primary" <?php echo $filiere_archived ? 'disabled' : ''; ?>>
                <i class="fas fa-save mr-1"></i>Enregistrer les modifications
            </button>
        </form>
    </div>

    <div class="epim-card no-hover p-4 mb-4">
        <div class="section-header">
            <div>
                <h2>Ajouter une unité de formation</h2>
                <p>Associer une nouvelle unité à cette filière.</p>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group col-lg-6">
                <label for="unite_nom">Nom de l'unité</label>
                <input type="text" id="unite_nom" class="form-control" required <?php echo $filiere_archived ? 'disabled' : ''; ?>>
            </div>
            <div class="form-group col-lg-6">
                <label for="formateur_id">Formateur</label>
                <select id="formateur_id" class="form-control" required <?php echo $filiere_archived ? 'disabled' : ''; ?>>
                    <option value="" disabled selected>Choisissez un formateur</option>
                    <?php while ($formateur = $result_formateurs->fetch_assoc()): ?>
                        <option value="<?php echo (int) $formateur['id']; ?>"><?php echo htmlspecialchars($formateur['nom'], ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="form-group col-lg-12">
                <label for="objectif_general">Objectif général</label>
                <textarea id="objectif_general" class="form-control" rows="3" required <?php echo $filiere_archived ? 'disabled' : ''; ?>></textarea>
            </div>
            <div class="form-group col-lg-6">
                <label for="heures_defaut">Heures par séance par défaut</label>
                <input type="number" id="heures_defaut" class="form-control" required <?php echo $filiere_archived ? 'disabled' : ''; ?>>
            </div>
            <div class="form-group col-lg-6">
                <label for="masse_horaire">Masse horaire</label>
                <input type="number" id="masse_horaire" class="form-control" required <?php echo $filiere_archived ? 'disabled' : ''; ?>>
            </div>
            <div class="form-group col-lg-6">
                <label for="semestre">Semestre</label>
                <select id="semestre" class="form-control" required <?php echo $filiere_archived ? 'disabled' : ''; ?>>
                    <option value="" disabled selected>Choisissez un semestre</option>
                    <option value="1">1er semestre</option>
                    <option value="2">2ème semestre</option>
                </select>
            </div>
            <div class="form-group col-lg-6">
                <label for="unite_annee_formation">Année de formation</label>
                <select id="unite_annee_formation" class="form-control" required <?php echo $filiere_archived ? 'disabled' : ''; ?>>
                    <option value="" disabled selected>Choisissez une année</option>
                    <?php foreach ($anneesUniteFormation as $anneeOption): ?>
                        <option value="<?php echo (int) $anneeOption; ?>">
                            <?php echo htmlspecialchars(unite_annee_formation_label($anneeOption), ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group col-lg-6">
                <label for="type_unite">Type d'unité</label>
                <select id="type_unite" class="form-control" required <?php echo $filiere_archived ? 'disabled' : ''; ?>>
                    <?php foreach (unite_type_options() as $typeValue => $typeLabel): ?>
                        <option value="<?php echo htmlspecialchars($typeValue, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $typeValue === TYPE_UNITE_PEDAGOGIQUE ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <button type="button" class="btn btn-epim-primary" id="ajouter_unite" <?php echo $filiere_archived ? 'disabled' : ''; ?>>
            <i class="fas fa-plus mr-1"></i>Ajouter l'unité
        </button>
    </div>

    <div class="epim-card no-hover p-4 mb-4">
        <div class="section-header">
            <div>
                <h2>Importer des unités</h2>
                <p>Importer un fichier Excel contenant les unités de formation.</p>
            </div>
        </div>
        <form id="import_unites_form" enctype="multipart/form-data">
            <input type="hidden" name="filiere_id" value="<?php echo (int) $filiere_id; ?>">
            <div class="form-row align-items-end">
                <div class="form-group col-lg-6">
                    <label for="file">Fichier Excel</label>
                    <input id="file" type="file" name="file" class="form-control" accept=".xlsx,.xls,.csv" required <?php echo $filiere_archived ? 'disabled' : ''; ?>>
                </div>
                <div class="form-group col-lg-6 d-flex flex-wrap" style="gap:8px;">
                    <a href="import_unites.php?action=template" class="btn btn-outline-epim">
                        <i class="fas fa-download mr-1"></i>Télécharger le modèle
                    </a>
                    <button type="button" class="btn btn-epim-accent" id="preview_unites" <?php echo $filiere_archived ? 'disabled' : ''; ?>>
                        <i class="fas fa-eye mr-1"></i>Prévisualiser
                    </button>
                    <button type="button" class="btn btn-epim-primary" id="confirm_import_unites" disabled>
                        <i class="fas fa-file-import mr-1"></i>Importer
                    </button>
                </div>
            </div>
        </form>
        <div id="import_unites_messages" class="mt-3"></div>
        <div id="import_unites_preview" class="table-responsive mt-3" hidden>
            <table class="table epim-table table-borderless mb-0">
                <thead>
                    <tr>
                        <th>Nom</th>
                        <th>Formateur</th>
                        <th>Objectif général</th>
                        <th>Heures par séance</th>
                        <th>Masse horaire</th>
                        <th>Année</th>
                        <th>Semestre</th>
                        <th>Type</th>
                        <th>Statut</th>
                    </tr>
                </thead>
                <tbody id="import_unites_preview_body"></tbody>
            </table>
        </div>
    </div>

    <div class="epim-card no-hover p-3">
        <div class="section-header px-1">
            <div>
                <h2>Unités de formation</h2>
                <p>Liste des unités associées à la filière.</p>
            </div>
        </div>
        <form method="GET" class="mb-3" id="unitArchiveFilterForm">
            <input type="hidden" name="id" value="<?php echo (int) $filiere_id; ?>">
            <div class="d-flex flex-wrap align-items-center" style="gap:12px;">
                <span class="font-weight-bold">Statut :</span>
                <div class="custom-control custom-radio">
                    <input type="radio" id="unit_archive_filter_active" name="unit_archive_filter" value="active" class="custom-control-input" <?php echo $unit_archive_filter === 'active' ? 'checked' : ''; ?>>
                    <label class="custom-control-label" for="unit_archive_filter_active">Actives</label>
                </div>
                <div class="custom-control custom-radio">
                    <input type="radio" id="unit_archive_filter_archived" name="unit_archive_filter" value="archived" class="custom-control-input" <?php echo $unit_archive_filter === 'archived' ? 'checked' : ''; ?>>
                    <label class="custom-control-label" for="unit_archive_filter_archived">Archivées</label>
                </div>
                <div class="custom-control custom-radio">
                    <input type="radio" id="unit_archive_filter_all" name="unit_archive_filter" value="all" class="custom-control-input" <?php echo $unit_archive_filter === 'all' ? 'checked' : ''; ?>>
                    <label class="custom-control-label" for="unit_archive_filter_all">Toutes</label>
                </div>
                <div class="ml-lg-3 d-flex align-items-center" style="gap:8px;">
                    <label for="unit_semestre_filter" class="font-weight-bold mb-0">Semestre :</label>
                    <select id="unit_semestre_filter" class="form-control form-control-sm" style="width:auto;">
                        <option value="all" <?php echo $unit_semestre_filter === 'all' ? 'selected' : ''; ?>>Tous</option>
                        <option value="1" <?php echo $unit_semestre_filter === '1' ? 'selected' : ''; ?>>1er semestre</option>
                        <option value="2" <?php echo $unit_semestre_filter === '2' ? 'selected' : ''; ?>>2ème semestre</option>
                    </select>
                </div>
                <div class="ml-lg-3 d-flex align-items-center" style="gap:8px;">
                    <label for="unit_annee_filter" class="font-weight-bold mb-0">Année de formation :</label>
                    <select id="unit_annee_filter" class="form-control form-control-sm" style="width:auto;">
                        <option value="0" <?php echo $unit_annee_filter === 0 ? 'selected' : ''; ?>>Toutes</option>
                        <?php foreach ($anneesUniteFormation as $anneeOption): ?>
                            <option value="<?php echo (int) $anneeOption; ?>" <?php echo $unit_annee_filter === (int) $anneeOption ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(unite_annee_formation_label($anneeOption), ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </form>
        <div class="table-responsive epim-data-table unites-table-scroll" id="unites_table_scroll">
            <table class="table epim-table epim-unites-table table-borderless mb-0">
                <colgroup>
                    <col style="width: 16%;">
                    <col style="width: 20%;">
                    <col style="width: 13%;">
                    <col style="width: 10%;">
                    <col style="width: 9%;">
                    <col style="width: 9%;">
                    <col style="width: 9%;">
                    <col style="width: 8%;">
                    <col style="width: 6%;">
                </colgroup>
                <thead>
                    <tr>
                        <th>Intitulé</th>
                        <th>Objectif général</th>
                        <th>Formateur</th>
                        <th>Heures par séance</th>
                        <th>Masse horaire</th>
                        <th>Année</th>
                        <th>Semestre</th>
                        <th>Type</th>
                        <th class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody id="unites_liste">
                    <?php if ($result_unites && $result_unites->num_rows > 0): ?>
                        <?php while ($unite = $result_unites->fetch_assoc()): ?>
                            <tr class="unite-row" data-archive-status="<?php echo (int) ($unite['is_archived'] ?? 0) === 1 ? 'archived' : 'active'; ?>" data-semestre="<?php echo unite_normalize_semestre($unite['semestre'] ?? 1); ?>" data-annee-formation="<?php echo unite_normalize_annee_formation($unite['annee_formation'] ?? 2); ?>">
                                <td>
                                    <?php echo htmlspecialchars($unite['intitule'], ENT_QUOTES, 'UTF-8'); ?>
                                    <?php if ((int) ($unite['is_archived'] ?? 0) === 1): ?>
                                        <span class="badge-epim-danger ml-2">Archivée</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($unite['objectif_general'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($unite['formateur_nom'] ?: '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><span class="badge-epim-info"><?php echo htmlspecialchars($unite['heures_par_seance_defaut'], ENT_QUOTES, 'UTF-8'); ?> h</span></td>
                                <td><span class="badge-epim-orange"><?php echo htmlspecialchars($unite['masse_horaire'], ENT_QUOTES, 'UTF-8'); ?> h</span></td>
                                <td><span class="badge-epim-info"><?php echo htmlspecialchars(unite_annee_formation_label($unite['annee_formation'] ?? 2), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td><span class="badge-epim-info"><?php echo htmlspecialchars(unite_semestre_label($unite['semestre'] ?? 1), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td><span class="badge-epim-info"><?php echo htmlspecialchars(unite_type_label($unite['type_unite'] ?? TYPE_UNITE_PEDAGOGIQUE), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td class="text-center">
                                    <a href="gerer_unite.php?id=<?php echo (int) $unite['id']; ?>" class="btn btn-epim-primary btn-sm">
                                        <i class="fas fa-cog mr-1"></i>Gérer
                                    </a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">Aucune unité de formation enregistrée.</td>
                        </tr>
                    <?php endif; ?>
                    <tr id="unites_empty_filter" hidden>
                        <td colspan="9" class="text-center text-muted py-4">Aucune unité ne correspond à ce filtre.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function confirmFiliereArchive(isArchived) {
    if (isArchived) {
        return confirm("Réactiver cette filière ?\n\nLa filière redeviendra immédiatement modifiable.");
    }

    return confirm(
        "Cette opération archivera cette filière.\n\n" +
        "Toutes les unités, séquences, objectifs,\n" +
        "descriptions, sujets et séances seront conservés.\n\n" +
        "La filière ne pourra plus être modifiée tant qu'elle\n" +
        "ne sera pas réactivée.\n\n" +
        "Continuer ?"
    );
}

$(function() {
    var filiereArchived = <?php echo $filiere_archived ? 'true' : 'false'; ?>;

    <?php if ($message === 'archive_reussie'): ?>
        toastr.success('La filière a été archivée.');
    <?php elseif ($message === 'reactivation_reussie'): ?>
        toastr.success('La filière a été réactivée.');
    <?php endif; ?>

    function escapeHtml(value) {
        return $('<div>').text(value || '').html();
    }

	    function applyUnitArchiveFilter(resetPage) {
	        var value = $('input[name="unit_archive_filter"]:checked').val() || 'active';
	        var semestre = $('#unit_semestre_filter').val() || 'all';
	        var anneeFormation = $('#unit_annee_filter').val() || '0';
	
	        $('#unites_liste tr.unite-row').each(function() {
	            var rowStatus = $(this).data('archive-status');
	            var rowSemestre = String($(this).data('semestre') || '1');
	            var rowAnneeFormation = String($(this).data('annee-formation') || '2');
	            var filtered = !(value === 'all' || rowStatus === value)
                    || !(semestre === 'all' || rowSemestre === semestre)
                    || !(anneeFormation === '0' || rowAnneeFormation === anneeFormation);
	            $(this).toggleClass('unit-filter-hidden', filtered);
	            $(this).toggleClass('epim-table-filter-hidden', filtered);
	            $(this).toggle(!filtered);
	        });

        var params = new URLSearchParams(window.location.search);
        params.set('unit_archive_filter', value);
        params.set('unit_semestre_filter', semestre);
        if (anneeFormation === '0') {
            params.delete('unit_annee_filter');
        } else {
            params.set('unit_annee_filter', anneeFormation);
        }
        history.replaceState(null, '', window.location.pathname + '?' + params.toString());

	        if (window.EpimDataTables) {
	            window.EpimDataTables.refresh(document.getElementById('unites_table_scroll'), {
	                rowSelector: 'tr.unite-row:not(.unit-filter-hidden)'
	            });
	        }
	    }

    function statusBadge(row) {
        if (row.status_type === 'new') {
            return '<span class="badge-epim-success">Nouvelle unité</span>';
        }
        if (row.status_type === 'existing') {
            return '<span class="badge-epim-warning">Déjà existante</span>';
        }
        return '<span class="badge-epim-danger">Erreur</span>';
    }

    function renderImportMessages(html) {
        $('#import_unites_messages').html(html || '');
    }

    function renderImportPreview(rows) {
        var body = $('#import_unites_preview_body');
        body.empty();

        rows.forEach(function(row) {
            var details = row.errors && row.errors.length
                ? '<div class="small text-danger mt-1">' + escapeHtml(row.errors.join(' ')) + '</div>'
                : '';

            body.append(
                '<tr>' +
                '<td>' + escapeHtml(row.nom) + '</td>' +
                '<td>' + escapeHtml(row.formateur) + '</td>' +
                '<td>' + escapeHtml(row.objectif_general) + '</td>' +
                '<td><span class="badge-epim-info">' + escapeHtml(row.heures_defaut) + ' h</span></td>' +
                '<td><span class="badge-epim-orange">' + escapeHtml(row.masse_horaire) + ' h</span></td>' +
                '<td><span class="badge-epim-info">' + escapeHtml(row.annee_formation_label || '-') + '</span></td>' +
                '<td><span class="badge-epim-info">' + (String(row.semestre) === '2' ? '2ème semestre' : '1er semestre') + '</span></td>' +
                '<td><span class="badge-epim-info">' + (String(row.type_unite) === 'stage' ? 'Stage' : 'Unité pédagogique') + '</span></td>' +
                '<td>' + statusBadge(row) + details + '</td>' +
                '</tr>'
            );
        });

        $('#import_unites_preview').prop('hidden', rows.length === 0);
    }

    $('input[name="unit_archive_filter"]').on('change', function() {
        applyUnitArchiveFilter(true);
    });
    $('#unit_semestre_filter').on('change', function() {
        applyUnitArchiveFilter(true);
    });
    $('#unit_annee_filter').on('change', function() {
        applyUnitArchiveFilter(true);
    });
    applyUnitArchiveFilter(false);

    $('#preview_unites').on('click', function() {
        if (filiereArchived) {
            toastr.warning('Cette filière est archivée. Import désactivé.');
            return;
        }
        var fileInput = $('#file')[0];
        if (!fileInput.files.length) {
            toastr.error('Veuillez sélectionner un fichier Excel.');
            return;
        }

        var formData = new FormData($('#import_unites_form')[0]);
        formData.append('action', 'preview');

        $('#confirm_import_unites').prop('disabled', true);
        renderImportMessages('<div class="alert alert-info mb-0">Analyse du fichier en cours...</div>');

        $.ajax({
            url: 'import_unites.php',
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(response) {
                if (!response.success) {
                    renderImportMessages('<div class="alert alert-danger mb-0">' + escapeHtml(response.message || 'Fichier invalide.') + '</div>');
                    return;
                }

                renderImportPreview(response.rows || []);
                var summary = response.summary || {};
                var messages = '';

                if (response.errors && response.errors.length) {
                    messages += '<div class="alert alert-danger"><strong>Erreurs :</strong><br>' + escapeHtml(response.errors.join(' ')) + '</div>';
                }

                messages += '<div class="alert ' + (response.can_import ? 'alert-success' : 'alert-warning') + ' mb-0">' +
                    'Lignes lues : ' + (summary.read || 0) +
                    ' | Nouvelles : ' + (summary.new || 0) +
                    ' | Déjà existantes : ' + (summary.existing || 0) +
                    ' | Erreurs : ' + (summary.errors || 0) +
                    '</div>';

                renderImportMessages(messages);
                $('#confirm_import_unites').prop('disabled', !response.can_import || !(summary.new > 0));
            },
            error: function(xhr) {
                var message = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Impossible de lire le fichier.';
                renderImportMessages('<div class="alert alert-danger mb-0">' + escapeHtml(message) + '</div>');
            }
        });
    });

    $('#confirm_import_unites').on('click', function() {
        if (filiereArchived) {
            toastr.warning('Cette filière est archivée. Import désactivé.');
            return;
        }
        var formData = new FormData();
        formData.append('action', 'import');
        formData.append('filiere_id', '<?php echo (int) $filiere_id; ?>');

        $(this).prop('disabled', true);
        $.ajax({
            url: 'import_unites.php',
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(response) {
                var summary = response.summary || {};
                if (response.success) {
                    renderImportMessages(
                        '<div class="alert alert-success mb-0">' +
                        'Import terminé. Lignes lues : ' + (summary.read || 0) +
                        ' | Importées : ' + (summary.imported || 0) +
                        ' | Ignorées : ' + (summary.ignored || 0) +
                        ' | En erreur : ' + (summary.errors || 0) +
                        '</div>'
                    );
                    toastr.success('Unités importées avec succès.');
                } else {
                    renderImportMessages('<div class="alert alert-danger mb-0">' + escapeHtml(response.message || 'Import impossible.') + '</div>');
                }
            },
            error: function(xhr) {
                var message = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Erreur lors de l\'import.';
                renderImportMessages('<div class="alert alert-danger mb-0">' + escapeHtml(message) + '</div>');
            }
        });
    });

    $('#ajouter_unite').on('click', function() {
        if (filiereArchived) {
            toastr.warning('Cette filière est archivée. Ajout désactivé.');
            return;
        }
        var uniteNom = $('#unite_nom').val();
        var objectif = $('#objectif_general').val();
        var heuresDefaut = $('#heures_defaut').val();
        var masseHoraire = $('#masse_horaire').val();
        var semestre = $('#semestre').val();
        var anneeFormation = $('#unite_annee_formation').val();
        var typeUnite = $('#type_unite').val();
        var formateurId = $('#formateur_id').val();
        var formateurNom = $('#formateur_id option:selected').text();

        if (uniteNom.trim() === '' || objectif.trim() === '' || !formateurId || !heuresDefaut || !masseHoraire || !semestre || !anneeFormation || !typeUnite) {
            toastr.error('Tous les champs doivent être remplis.');
            return;
        }

        $.ajax({
            url: 'ajouter_unite.php',
            method: 'POST',
            dataType: 'json',
            data: {
                filiere_id: <?php echo (int) $filiere_id; ?>,
                nom: uniteNom,
                objectif_general: objectif,
                heures_defaut: heuresDefaut,
                masse_horaire: masseHoraire,
                formateur_id: formateurId,
                semestre: semestre,
                annee_formation: anneeFormation,
                type_unite: typeUnite
            },
            success: function(response) {
                if (response.success) {
                    $('#unites_liste tr').filter(function() {
                        return $(this).find('td[colspan]').length > 0;
                    }).remove();

                    var actionCell = response.id
                        ? '<a href="gerer_unite.php?id=' + response.id + '" class="btn btn-epim-primary btn-sm"><i class="fas fa-cog mr-1"></i>Gérer</a>'
                        : '<span class="text-muted">Disponible après actualisation</span>';

                    $('#unites_liste').append(
                        '<tr class="unite-row" data-archive-status="active" data-semestre="' + semestre + '" data-annee-formation="' + anneeFormation + '">' +
                        '<td>' + $('<div>').text(uniteNom).html() + '</td>' +
                        '<td>' + $('<div>').text(objectif).html() + '</td>' +
                        '<td>' + $('<div>').text(formateurNom).html() + '</td>' +
                        '<td><span class="badge-epim-info">' + heuresDefaut + ' h</span></td>' +
                        '<td><span class="badge-epim-orange">' + masseHoraire + ' h</span></td>' +
                        '<td><span class="badge-epim-info">' + (anneeFormation === '1' ? '1ère année' : anneeFormation + 'ème année') + '</span></td>' +
                        '<td><span class="badge-epim-info">' + (semestre === '2' ? '2ème semestre' : '1er semestre') + '</span></td>' +
                        '<td><span class="badge-epim-info">' + (typeUnite === 'stage' ? 'Stage' : 'Unité pédagogique') + '</span></td>' +
                        '<td class="text-center">' + actionCell + '</td>' +
                        '</tr>'
	                    );
	                    applyUnitArchiveFilter(false);
	                    $('#unite_nom, #objectif_general, #heures_defaut, #masse_horaire').val('');
                    $('#semestre').val('');
                    $('#unite_annee_formation').val('');
                    $('#type_unite').val('pedagogique');
                    $('#formateur_id').val('');
                    toastr.success('Unité ajoutée avec succès.');
                } else {
                    toastr.error('Erreur lors de l\'ajout de l\'unité.');
                }
            },
            error: function() {
                toastr.error('Une erreur est survenue lors de la communication avec le serveur.');
            }
        });
    });
});
</script>

<?php include 'footer.php'; ?>


