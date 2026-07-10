<?php
include 'page_directeur.php';
require_once __DIR__ . '/includes/filiere_helper.php';

filiere_ensure_columns($conn);

$anneesFormation = filiere_annees_formation_options();
$anneesFormationFiltre = filiere_annees_formation_presentes($conn);

$message = $_GET['message'] ?? null;
$error = '';
$archive_filter = $_GET['archive_filter'] ?? 'active';
if (!in_array($archive_filter, ['active', 'archived', 'all'], true)) {
    $archive_filter = 'active';
}
$annee_formation_filter = filiere_normalize_annee_formation($_GET['annee_formation_filter'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ajouter_filiere') {
    $nom = trim((string) ($_POST['nom'] ?? ''));
    $niveau = filiere_normalize_niveau((string) ($_POST['niveau'] ?? ''));
    $anneeFormation = filiere_normalize_annee_formation($_POST['annee_formation'] ?? null);
    $secteur = trim((string) ($_POST['secteur'] ?? ''));

    if ($nom === '') {
        $error = 'Le nom de la filière est obligatoire.';
    } elseif ($anneeFormation <= 0) {
        $error = "La durée de la formation est obligatoire.";
    } elseif (filiere_name_exists($conn, $nom)) {
        $error = 'Une filière avec ce nom existe déjà.';
    } else {
        $stmt = $conn->prepare("INSERT INTO filieres (nom, niveau, annee_formation, secteur, secteur_id) VALUES (?, ?, ?, ?, NULL)");
        $stmt->bind_param('ssis', $nom, $niveau, $anneeFormation, $secteur);
        if ($stmt->execute()) {
            $stmt->close();
            header('Location: liste_filieres.php?message=ajout_reussi');
            exit();
        }
        $stmt->close();
        $error = "Erreur lors de l'ajout de la filière.";
    }
}

$sql_filieres = "
    SELECT f.id, f.nom, f.niveau, COALESCE(f.annee_formation, 1) AS annee_formation,
           COALESCE(NULLIF(f.secteur, ''), s.nom, '-') AS secteur_nom,
           COALESCE(f.is_archived, 0) AS is_archived,
           COUNT(DISTINCT uf.id) AS nombre_unites,
           COUNT(DISTINCT sp.id) AS nombre_seances
    FROM filieres f
    LEFT JOIN secteurs s ON f.secteur_id = s.id
    LEFT JOIN unites_de_formation uf ON uf.filiere_id = f.id
    LEFT JOIN sequences_pedagogiques seq ON seq.unite_id = uf.id
    LEFT JOIN seances_pedagogiques sp ON sp.sequence_id = seq.id
    GROUP BY f.id, f.nom, f.niveau, f.annee_formation, f.secteur, f.is_archived, s.nom
    ORDER BY f.nom
";
$result_filieres = $conn->query($sql_filieres);
?>

<div class="container-fluid fade-in">
    <div class="page-header d-flex flex-column flex-md-row justify-content-between align-items-md-end">
        <div>
            <h1 class="page-title">Gestion des filières</h1>
            <p class="page-subtitle">Premier niveau de configuration pédagogique de la plateforme.</p>
        </div>
        <button type="button" class="btn btn-epim-primary mt-3 mt-md-0" id="toggleAddFiliere">
            <i class="fas fa-plus mr-1"></i>Ajouter une filière
        </button>
    </div>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <div class="epim-card no-hover p-4 mb-4" id="addFiliereCard" <?php echo $error === '' ? 'hidden' : ''; ?>>
        <div class="section-header">
            <div>
                <h2>Ajouter une filière</h2>
                <p>Créer une nouvelle filière avant de configurer ses unités.</p>
            </div>
        </div>
        <form method="POST" autocomplete="off">
            <input type="hidden" name="action" value="ajouter_filiere">
            <div class="form-row">
                <div class="form-group col-lg-4">
                    <label for="nom">Nom de la filière</label>
                    <input type="text" class="form-control" id="nom" name="nom" required>
                </div>
                <div class="form-group col-lg-3">
                    <label for="niveau">Niveau</label>
                    <select class="form-control" id="niveau" name="niveau" required>
                        <option value="Technicien">Technicien</option>
                        <option value="Technicien Specialisé">Technicien Spécialisé</option>
                    </select>
                </div>
                <div class="form-group col-lg-3">
                    <label for="annee_formation">Durée de la formation</label>
                    <select class="form-control" id="annee_formation" name="annee_formation" required>
                        <option value="">Sélectionner...</option>
                        <?php foreach ($anneesFormation as $anneeFormationOption): ?>
                            <option value="<?php echo (int) $anneeFormationOption; ?>">
                                <?php echo htmlspecialchars(filiere_duree_formation_label($anneeFormationOption), ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group col-lg-2">
                    <label for="secteur">Secteur</label>
                    <input type="text" class="form-control" id="secteur" name="secteur" placeholder="Ex. Informatique, Gestion, Design">
                </div>
            </div>
            <button type="submit" class="btn btn-epim-primary">
                <i class="fas fa-save mr-1"></i>Enregistrer
            </button>
        </form>
    </div>

    <div class="epim-card no-hover p-4 mb-4">
        <div class="section-header">
            <div>
                <h2>Importer des filières</h2>
                <p>Importer un fichier Excel ou CSV contenant les filières à créer.</p>
            </div>
        </div>
        <form id="import_filieres_form" enctype="multipart/form-data">
            <div class="form-row align-items-end">
                <div class="form-group col-lg-6">
                    <label for="filiere_file">Fichier Excel / CSV</label>
                    <input id="filiere_file" type="file" name="file" class="form-control" accept=".xlsx,.xls,.csv" required>
                </div>
                <div class="form-group col-lg-6 d-flex flex-wrap" style="gap:8px;">
                    <a href="import_filieres.php?action=template" class="btn btn-outline-epim">
                        <i class="fas fa-download mr-1"></i>Télécharger le modèle
                    </a>
                    <button type="button" class="btn btn-epim-accent" id="preview_filieres">
                        <i class="fas fa-eye mr-1"></i>Prévisualiser
                    </button>
                    <button type="button" class="btn btn-epim-primary" id="confirm_import_filieres" disabled>
                        <i class="fas fa-file-import mr-1"></i>Importer
                    </button>
                </div>
            </div>
        </form>
        <div id="import_filieres_messages" class="mt-3"></div>
        <div id="import_filieres_preview" class="table-responsive mt-3" hidden>
            <table class="table epim-table table-borderless mb-0">
                <thead>
                    <tr>
                        <th>Nom</th>
                        <th>Niveau</th>
                        <th>Durée</th>
                        <th>Secteur</th>
                        <th>Statut</th>
                    </tr>
                </thead>
                <tbody id="import_filieres_preview_body"></tbody>
            </table>
        </div>
    </div>

    <div class="epim-card no-hover p-3">
        <form method="GET" class="mb-3" id="archiveFilterForm">
            <div class="d-flex flex-wrap align-items-center" style="gap:12px;">
                <span class="font-weight-bold">Statut :</span>
                <div class="custom-control custom-radio">
                    <input type="radio" id="archive_filter_all" name="archive_filter" value="all" class="custom-control-input" <?php echo $archive_filter === 'all' ? 'checked' : ''; ?>>
                    <label class="custom-control-label" for="archive_filter_all">Toutes</label>
                </div>
                <div class="custom-control custom-radio">
                    <input type="radio" id="archive_filter_active" name="archive_filter" value="active" class="custom-control-input" <?php echo $archive_filter === 'active' ? 'checked' : ''; ?>>
                    <label class="custom-control-label" for="archive_filter_active">Actives</label>
                </div>
                <div class="custom-control custom-radio">
                    <input type="radio" id="archive_filter_archived" name="archive_filter" value="archived" class="custom-control-input" <?php echo $archive_filter === 'archived' ? 'checked' : ''; ?>>
                    <label class="custom-control-label" for="archive_filter_archived">Archivées</label>
                </div>
                <div class="form-group mb-0 ml-md-3">
                    <label for="annee_formation_filter" class="font-weight-bold mb-1">Durée de la formation</label>
                    <select id="annee_formation_filter" name="annee_formation_filter" class="form-control form-control-sm">
                        <?php foreach ($anneesFormationFiltre as $anneeFormationOption): ?>
                            <?php $anneeFormationValue = (int) ($anneeFormationOption['id'] ?? 0); ?>
                            <option value="<?php echo $anneeFormationValue; ?>" <?php echo $annee_formation_filter === $anneeFormationValue ? 'selected' : ''; ?>>
                                <?php
                                    $dureeOptionValue = (int) ($anneeFormationOption['id'] ?? 0);
                                    $dureeOptionLabel = $dureeOptionValue > 0
                                        ? filiere_duree_formation_label($dureeOptionValue)
                                        : ($anneeFormationOption['label'] ?? 'Toutes');
                                    echo htmlspecialchars($dureeOptionLabel, ENT_QUOTES, 'UTF-8');
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </form>
        <div class="table-responsive epim-data-table" id="filieres_table_wrap">
            <table class="table epim-table table-borderless mb-0">
                <thead>
                    <tr>
                        <th>Nom</th>
                        <th>Niveau</th>
                        <th>Durée</th>
                        <th>Secteur</th>
                        <th>Nombre d'unités</th>
                        <th>Nombre total de séances</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result_filieres && $result_filieres->num_rows > 0): ?>
                        <?php while ($filiere = $result_filieres->fetch_assoc()): ?>
                            <tr class="filiere-row" data-archive-status="<?php echo (int) $filiere['is_archived'] === 1 ? 'archived' : 'active'; ?>" data-annee-formation="<?php echo (int) ($filiere['annee_formation'] ?? 1); ?>">
                                <td>
                                    <?php echo htmlspecialchars($filiere['nom'], ENT_QUOTES, 'UTF-8'); ?>
                                    <?php if ((int) $filiere['is_archived'] === 1): ?>
                                        <span class="badge-epim-danger ml-2">Archivée</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge-epim-info"><?php echo htmlspecialchars(filiere_niveau_label($filiere['niveau']), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td><?php echo htmlspecialchars(filiere_duree_formation_label($filiere['annee_formation'] ?? 1), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($filiere['secteur_nom'] ?: '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><span class="badge-epim-orange"><?php echo (int) $filiere['nombre_unites']; ?></span></td>
                                <td><span class="badge-epim-info"><?php echo (int) $filiere['nombre_seances']; ?></span></td>
                                <td class="text-center">
                                    <a href="modifier_filiere.php?id=<?php echo (int) $filiere['id']; ?>" class="btn btn-epim-primary btn-sm">
                                        <i class="fas fa-cog mr-1"></i>Gérer
                                    </a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">Aucune filière enregistrée.</td>
                        </tr>
                    <?php endif; ?>
                    <tr id="filieres_empty_filter" hidden>
                        <td colspan="7" class="text-center text-muted py-4">Aucune filière ne correspond à ce filtre.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
$(function() {
    function escapeHtml(value) {
        return $('<div>').text(value || '').html();
    }

    function applyFiliereFilters() {
        var archiveValue = $('input[name="archive_filter"]:checked').val() || 'active';
        var anneeFormationValue = $('#annee_formation_filter').val() || '0';
        var visibleCount = 0;

        $('.filiere-row').each(function() {
            var rowStatus = $(this).data('archive-status');
            var rowAnneeFormation = String($(this).data('annee-formation') || '0');
            var showArchive = archiveValue === 'all' || rowStatus === archiveValue;
            var showAnneeFormation = anneeFormationValue === '0' || rowAnneeFormation === anneeFormationValue;
            var show = showArchive && showAnneeFormation;
            $(this).toggleClass('epim-table-filter-hidden', !show);
            $(this).toggle(show);
            if (show) {
                visibleCount++;
            }
        });

        $('#filieres_empty_filter').prop('hidden', visibleCount > 0 || $('.filiere-row').length === 0);

        var params = new URLSearchParams(window.location.search);
        params.set('archive_filter', archiveValue);
        if (anneeFormationValue === '0') {
            params.delete('annee_formation_filter');
        } else {
            params.set('annee_formation_filter', anneeFormationValue);
        }
        history.replaceState(null, '', window.location.pathname + '?' + params.toString());
        if (window.EpimDataTables) {
            window.EpimDataTables.refresh(document.getElementById('filieres_table_wrap'), {
                rowSelector: 'tr.filiere-row:not(.epim-table-filter-hidden)'
            });
        }
    }

    $('input[name="archive_filter"], #annee_formation_filter').on('change', applyFiliereFilters);
    applyFiliereFilters();

    function statusBadge(row) {
        if (row.status_type === 'new') {
            return '<span class="badge-epim-success">Nouvelle filière</span>';
        }
        if (row.status_type === 'existing') {
            return '<span class="badge-epim-warning">Déjà existante</span>';
        }
        return '<span class="badge-epim-danger">Erreur</span>';
    }

    function renderImportMessages(html) {
        $('#import_filieres_messages').html(html || '');
    }

    function renderImportPreview(rows) {
        var body = $('#import_filieres_preview_body');
        body.empty();

        rows.forEach(function(row) {
            var details = row.errors && row.errors.length
                ? '<div class="small text-danger mt-1">' + row.errors.map(escapeHtml).join('<br>') + '</div>'
                : '';

            body.append(
                '<tr>' +
                '<td>' + escapeHtml(row.nom) + '</td>' +
                '<td>' + escapeHtml(row.niveau_label || row.niveau) + '</td>' +
                '<td>' + escapeHtml(row.duree_formation_label || row.annee_formation_label || '-') + '</td>' +
                '<td>' + escapeHtml(row.secteur || '-') + '</td>' +
                '<td>' + statusBadge(row) + details + '</td>' +
                '</tr>'
            );
        });

        $('#import_filieres_preview').prop('hidden', rows.length === 0);
    }

    $('#toggleAddFiliere').on('click', function() {
        $('#addFiliereCard').prop('hidden', function(_, hidden) {
            return !hidden;
        });
    });

    $('#preview_filieres').on('click', function() {
        var fileInput = $('#filiere_file')[0];
        if (!fileInput.files.length) {
            toastr.error('Veuillez sélectionner un fichier Excel ou CSV.');
            return;
        }

        var formData = new FormData($('#import_filieres_form')[0]);
        formData.append('action', 'preview');

        $('#confirm_import_filieres').prop('disabled', true);
        renderImportMessages('<div class="alert alert-info mb-0">Analyse du fichier en cours...</div>');

        $.ajax({
            url: 'import_filieres.php',
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
                    messages += '<div class="alert alert-danger">' + response.errors.map(escapeHtml).join('<br>') + '</div>';
                }

                messages += '<div class="alert ' + (response.can_import ? 'alert-success' : 'alert-warning') + ' mb-0">' +
                    'Lignes lues : ' + (summary.read || 0) +
                    ' | Nouvelles : ' + (summary.new || 0) +
                    ' | Déjà existantes : ' + (summary.existing || 0) +
                    ' | Erreurs : ' + (summary.errors || 0) +
                    '</div>';

                renderImportMessages(messages);
                $('#confirm_import_filieres').prop('disabled', !response.can_import || !(summary.new > 0));
            },
            error: function(xhr) {
                var message = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Impossible de lire le fichier.';
                renderImportMessages('<div class="alert alert-danger mb-0">' + escapeHtml(message) + '</div>');
            }
        });
    });

    $('#confirm_import_filieres').on('click', function() {
        var formData = new FormData();
        formData.append('action', 'import');

        $(this).prop('disabled', true);
        $.ajax({
            url: 'import_filieres.php',
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
                    toastr.success('Filières importées avec succès.');
                    setTimeout(function() {
                        window.location.href = 'liste_filieres.php?message=import_reussi';
                    }, 700);
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

    <?php if ($message === 'modification_reussie'): ?>
        toastr.success('La filière a été modifiée avec succès.');
    <?php elseif ($message === 'ajout_reussi'): ?>
        toastr.success('La filière a été ajoutée avec succès.');
    <?php elseif ($message === 'import_reussi'): ?>
        toastr.success('Import des filières terminé.');
    <?php endif; ?>
});
</script>

<?php include 'footer.php'; ?>
