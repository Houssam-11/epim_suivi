<?php
include 'page_directeur.php';
require_once __DIR__ . '/includes/unite_helper.php';

unite_ensure_columns($conn);

$unite_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

$sql_unite = "SELECT uf.*, COALESCE(f.is_archived, 0) AS filiere_is_archived
              FROM unites_de_formation uf
              LEFT JOIN filieres f ON f.id = uf.filiere_id
              WHERE uf.id = ?";
$stmt_unite = $conn->prepare($sql_unite);
$stmt_unite->bind_param("i", $unite_id);
$stmt_unite->execute();
$unite = $stmt_unite->get_result()->fetch_assoc();
$is_filiere_archived = $unite && (int) ($unite['filiere_is_archived'] ?? 0) === 1;
$is_unit_self_archived = $unite && (int) ($unite['is_archived'] ?? 0) === 1;
$is_unite_archived = $unite && ($is_unit_self_archived || $is_filiere_archived);

if (!$unite) {
    echo '<div class="alert alert-danger">Unité de formation introuvable.</div>';
    include 'footer.php';
    exit();
}

$sql_sequences = $is_unite_archived
    ? "SELECT * FROM sequences_pedagogiques WHERE unite_id = ? ORDER BY intitule"
    : "SELECT * FROM sequences_pedagogiques WHERE unite_id = ? AND COALESCE(is_archived, 0) = 0 ORDER BY intitule";
$stmt_sequences = $conn->prepare($sql_sequences);
$stmt_sequences->bind_param("i", $unite_id);
$stmt_sequences->execute();
$result_sequences = $stmt_sequences->get_result();

$sql_formateurs = "SELECT id, nom FROM formateurs ORDER BY nom";
$result_formateurs = $conn->query($sql_formateurs);
$anneesUniteFormation = unite_annees_formation_options();
?>

<div class="container-fluid fade-in">
    <div class="page-header d-flex flex-column flex-md-row justify-content-between align-items-md-end">
        <div>
            <h1 class="page-title">Gérer l'unité</h1>
            <p class="page-subtitle"><?php echo htmlspecialchars($unite['intitule'], ENT_QUOTES, 'UTF-8'); ?></p>
        </div>
        <a href="modifier_filiere.php?id=<?php echo (int) $unite['filiere_id']; ?>" class="btn btn-outline-epim mt-3 mt-md-0">
            <i class="fas fa-arrow-left mr-1"></i>Retour à la filière
        </a>
    </div>

    <?php if ($is_unite_archived): ?>
        <div class="alert alert-warning">
            Cette unite est archivee. Elle reste disponible pour l'historique, mais ne peut plus recevoir de nouvelles sequences.
        </div>
    <?php endif; ?>

    <div class="epim-card no-hover p-4 mb-4">
        <div class="section-header">
            <div>
                <h2>Informations de l'unité</h2>
                <p>Modifier l'intitulé, l'objectif, le formateur et les volumes horaires.</p>
            </div>
        </div>
        <form action="modifier_unite_action.php" method="POST">
            <input type="hidden" name="id" value="<?php echo (int) $unite['id']; ?>">
            <div class="form-row">
                <div class="form-group col-lg-6">
                    <label for="intitule">Intitulé de l'unité</label>
                    <input type="text" id="intitule" name="intitule" class="form-control" value="<?php echo htmlspecialchars($unite['intitule'], ENT_QUOTES, 'UTF-8'); ?>" required <?php echo $is_unite_archived ? 'disabled' : ''; ?>>
                </div>
                <div class="form-group col-lg-6">
                    <label for="formateur_id">Formateur</label>
                    <select id="formateur_id" name="formateur_id" class="form-control" required <?php echo $is_unite_archived ? 'disabled' : ''; ?>>
                        <option value="" disabled>Choisissez un formateur</option>
                        <?php while ($formateur = $result_formateurs->fetch_assoc()): ?>
                            <option value="<?php echo (int) $formateur['id']; ?>" <?php echo (int) $unite['formateur_id'] === (int) $formateur['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($formateur['nom'], ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-group col-lg-12">
                    <label for="objectif_general">Objectif général</label>
                    <textarea id="objectif_general" name="objectif_general" class="form-control" rows="3" required <?php echo $is_unite_archived ? 'disabled' : ''; ?>><?php echo htmlspecialchars($unite['objectif_general'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>
                <div class="form-group col-lg-6">
                    <label for="heures_par_seance_defaut">Heures par séance par défaut</label>
                    <input type="number" id="heures_par_seance_defaut" name="heures_par_seance_defaut" class="form-control" value="<?php echo htmlspecialchars($unite['heures_par_seance_defaut'], ENT_QUOTES, 'UTF-8'); ?>" required <?php echo $is_unite_archived ? 'disabled' : ''; ?>>
                </div>
                <div class="form-group col-lg-6">
                    <label for="masse_horaire">Masse horaire</label>
                    <input type="number" id="masse_horaire" name="masse_horaire" class="form-control" value="<?php echo htmlspecialchars($unite['masse_horaire'], ENT_QUOTES, 'UTF-8'); ?>" required <?php echo $is_unite_archived ? 'disabled' : ''; ?>>
                </div>
                <div class="form-group col-lg-6">
                    <label for="semestre">Semestre</label>
                    <select id="semestre" name="semestre" class="form-control" required <?php echo $is_unite_archived ? 'disabled' : ''; ?>>
                        <option value="1" <?php echo unite_normalize_semestre($unite['semestre'] ?? 1) === 1 ? 'selected' : ''; ?>>Premier semestre</option>
                        <option value="2" <?php echo unite_normalize_semestre($unite['semestre'] ?? 1) === 2 ? 'selected' : ''; ?>>Deuxième semestre</option>
                    </select>
                </div>
                <div class="form-group col-lg-6">
                    <label for="annee_formation">Année de formation</label>
                    <select id="annee_formation" name="annee_formation" class="form-control" required <?php echo $is_unite_archived ? 'disabled' : ''; ?>>
                        <option value="">Sélectionner...</option>
                        <?php foreach ($anneesUniteFormation as $anneeOption): ?>
                            <option value="<?php echo (int) $anneeOption; ?>" <?php echo unite_normalize_annee_formation($unite['annee_formation'] ?? 2) === (int) $anneeOption ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(unite_annee_formation_label($anneeOption), ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group col-lg-6">
                    <label for="type_unite">Type d'unité</label>
                    <select id="type_unite" name="type_unite" class="form-control" required <?php echo $is_unite_archived ? 'disabled' : ''; ?>>
                        <?php foreach (unite_type_options() as $typeValue => $typeLabel): ?>
                            <option value="<?php echo htmlspecialchars($typeValue, ENT_QUOTES, 'UTF-8'); ?>" <?php echo unite_normalize_type($unite['type_unite'] ?? TYPE_UNITE_PEDAGOGIQUE) === $typeValue ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <button type="submit" class="btn btn-epim-primary" <?php echo $is_unite_archived ? 'disabled' : ''; ?>>
                <i class="fas fa-save mr-1"></i>Enregistrer les modifications
            </button>
        </form>
    </div>

    <?php if (!$is_unite_archived): ?>
    <div class="epim-card no-hover p-4 mb-4">
        <div class="section-header">
            <div>
                <h2>Ajouter une séquence pédagogique</h2>
                <p>Créer une nouvelle séquence pour cette unité.</p>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group col-lg-6">
                <label for="intitule_sequence">Intitulé de la séquence</label>
                <input type="text" id="intitule_sequence" class="form-control" required>
            </div>
            <div class="form-group col-lg-6">
                <label for="volume_horaire">Volume horaire</label>
                <input type="number" id="volume_horaire" class="form-control" required>
            </div>
            <div class="form-group col-lg-12">
                <label for="objectif_sequence">Objectif de la séquence</label>
                <textarea id="objectif_sequence" class="form-control" rows="3" required></textarea>
            </div>
        </div>
        <button type="button" class="btn btn-epim-primary" id="ajouter_sequence">
            <i class="fas fa-plus mr-1"></i>Ajouter la séquence
        </button>
    </div>

    <?php endif; ?>
    <?php if (!$is_unite_archived): ?>
    <div class="epim-card no-hover p-4 mb-4">
        <div class="section-header">
            <div>
                <h2>Importer des séquences pédagogiques</h2>
                <p>Importer un fichier Excel contenant les séquences de cette unité.</p>
            </div>
        </div>
        <form id="import_sequences_form" enctype="multipart/form-data">
            <input type="hidden" name="unite_id" value="<?php echo (int) $unite_id; ?>">
            <div class="form-row align-items-end">
                <div class="form-group col-lg-6">
                    <label for="sequence_file">Fichier Excel</label>
                    <input id="sequence_file" type="file" name="file" class="form-control" accept=".xlsx,.xls,.csv" required>
                </div>
                <div class="form-group col-lg-6 d-flex flex-wrap" style="gap:8px;">
                    <a href="import_sequences.php?action=template" class="btn btn-outline-epim">
                        <i class="fas fa-download mr-1"></i>Télécharger le modèle
                    </a>
                    <button type="button" class="btn btn-epim-accent" id="preview_sequences">
                        <i class="fas fa-eye mr-1"></i>Prévisualiser
                    </button>
                    <button type="button" class="btn btn-epim-primary" id="confirm_import_sequences" disabled>
                        <i class="fas fa-file-import mr-1"></i>Importer
                    </button>
                </div>
            </div>
        </form>
        <div id="import_sequences_messages" class="mt-3"></div>
        <div id="import_sequences_preview" class="table-responsive mt-3" hidden>
            <table class="table epim-table table-borderless mb-0">
                <thead>
                    <tr>
                        <th>Intitulé</th>
                        <th>Volume horaire</th>
                        <th>Objectif général</th>
                        <th>Statut</th>
                    </tr>
                </thead>
                <tbody id="import_sequences_preview_body"></tbody>
            </table>
        </div>
    </div>

    <?php endif; ?>
    <div class="epim-card no-hover p-3">
        <div class="section-header px-1">
            <div>
                <h2>Séquences pédagogiques</h2>
                <p>Les cellules sont modifiables directement dans le tableau.</p>
            </div>
        </div>
        <div class="table-responsive epim-data-table">
            <table class="table epim-table table-borderless mb-0">
                <thead>
                    <tr>
                        <th>Intitulé</th>
                        <th>Objectif</th>
                        <th>Volume horaire</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody id="sequences_liste">
                    <?php if ($result_sequences && $result_sequences->num_rows > 0): ?>
                        <?php while ($sequence = $result_sequences->fetch_assoc()): ?>
                            <tr>
                                <td <?php echo $is_unite_archived ? '' : 'contenteditable="true" class="editable editable-cell" data-id="' . (int) $sequence['id'] . '" data-column="intitule"'; ?>><?php echo htmlspecialchars($sequence['intitule'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td <?php echo $is_unite_archived ? '' : 'contenteditable="true" class="editable editable-cell" data-id="' . (int) $sequence['id'] . '" data-column="objectif"'; ?>><?php echo htmlspecialchars($sequence['objectif'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td <?php echo $is_unite_archived ? '' : 'contenteditable="true" class="editable editable-cell" data-id="' . (int) $sequence['id'] . '" data-column="volume_horaire"'; ?>><?php echo htmlspecialchars($sequence['volume_horaire'], ENT_QUOTES, 'UTF-8'); ?> heures</td>
                                <td class="text-center">
                                    <?php if ($is_unite_archived): ?>
                                        <span class="text-muted">Archivée</span>
                                    <?php else: ?>
                                        <a href="configuration_sequence.php?id=<?php echo (int) $sequence['id']; ?>" target="_blank" rel="noopener" class="btn btn-epim-primary btn-sm">
                                            <i class="fas fa-cog mr-1"></i>Gérer
                                        </a>
                                        <button type="button" class="btn btn-outline-danger-epim btn-sm delete-sequence" data-id="<?php echo (int) $sequence['id']; ?>">
                                            <i class="fas fa-trash mr-1"></i>Supprimer
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" class="text-center text-muted py-4">Aucune séquence enregistrée.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="epim-card no-hover p-4 mt-4">
        <div class="section-header">
            <div>
                <h2>Actions sur l'unite</h2>
                <p>Archivage manuel ou suppression definitive si l'unite ne contient aucune sequence.</p>
            </div>
        </div>
        <div class="d-flex flex-wrap" style="gap: 10px;">
            <?php if (!$is_unite_archived): ?>
                <button type="button" class="btn btn-warning" id="archiver_unite">
                    <i class="fas fa-archive mr-1"></i>Archiver
                </button>
            <?php elseif ($is_unit_self_archived && !$is_filiere_archived): ?>
                <button type="button" class="btn btn-epim-primary" id="reactiver_unite">
                    <i class="fas fa-undo mr-1"></i>Réactiver
                </button>
            <?php endif; ?>
            <?php if (!$is_unite_archived): ?>
                <button type="button" class="btn btn-danger" id="supprimer_unite">
                    <i class="fas fa-trash-alt mr-1"></i>Supprimer definitivement l'unite
                </button>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
$(function() {
    function escapeHtml(value) {
        return $('<div>').text(value || '').html();
    }

    function sequenceStatusBadge(row) {
        if (row.status_type === 'new') {
            return '<span class="badge-epim-success">Nouvelle séquence</span>';
        }
        if (row.status_type === 'existing') {
            return '<span class="badge-epim-warning">Déjà existante</span>';
        }
        return '<span class="badge-epim-danger">Erreur</span>';
    }

    function renderSequenceImportMessages(html) {
        $('#import_sequences_messages').html(html || '');
    }

    function renderSequenceImportPreview(rows) {
        var body = $('#import_sequences_preview_body');
        body.empty();

        rows.forEach(function(row) {
            var details = row.errors && row.errors.length
                ? '<div class="small text-danger mt-1">' + escapeHtml(row.errors.join(' ')) + '</div>'
                : '';

            body.append(
                '<tr>' +
                '<td>' + escapeHtml(row.intitule) + '</td>' +
                '<td><span class="badge-epim-info">' + escapeHtml(row.volume_horaire) + ' h</span></td>' +
                '<td>' + escapeHtml(row.objectif) + '</td>' +
                '<td>' + sequenceStatusBadge(row) + details + '</td>' +
                '</tr>'
            );
        });

        $('#import_sequences_preview').prop('hidden', rows.length === 0);
    }

    function appendSequenceRow(row) {
        $('#sequences_liste tr td[colspan="4"]').closest('tr').remove();
        $('#sequences_liste').append(
            '<tr>' +
            '<td contenteditable="true" class="editable editable-cell" data-id="' + row.id + '" data-column="intitule">' + escapeHtml(row.intitule) + '</td>' +
            '<td contenteditable="true" class="editable editable-cell" data-id="' + row.id + '" data-column="objectif">' + escapeHtml(row.objectif) + '</td>' +
            '<td contenteditable="true" class="editable editable-cell" data-id="' + row.id + '" data-column="volume_horaire">' + escapeHtml(row.volume_horaire) + ' heures</td>' +
            '<td class="text-center">' +
            '<a href="configuration_sequence.php?id=' + row.id + '" target="_blank" rel="noopener" class="btn btn-epim-primary btn-sm"><i class="fas fa-cog mr-1"></i>Gérer</a> ' +
            '<button type="button" class="btn btn-outline-danger-epim btn-sm delete-sequence" data-id="' + row.id + '"><i class="fas fa-trash mr-1"></i>Supprimer</button>' +
            '</td>' +
            '</tr>'
        );
    }

    function bindEditable() {
        $('.editable').off('blur').on('blur', function() {
            var id = $(this).data('id');
            var column = $(this).data('column');
            var value = $(this).text();

            $.ajax({
                url: 'modifier_sequence.php',
                method: 'POST',
                dataType: 'json',
                data: { id: id, column: column, value: value },
                success: function(response) {
                    response.success ? toastr.success('Séquence mise à jour avec succès.') : toastr.error('Erreur lors de la mise à jour.');
                },
                error: function() {
                    toastr.error('Erreur de connexion.');
                }
            });
        });
    }

    bindEditable();

    $('#ajouter_sequence').on('click', function() {
        var uniteId = <?php echo (int) $unite_id; ?>;
        var intitule = $('#intitule_sequence').val();
        var objectif = $('#objectif_sequence').val();
        var volumeHoraire = $('#volume_horaire').val();

        if (intitule.trim() === '' || objectif.trim() === '' || volumeHoraire.trim() === '') {
            toastr.error('Tous les champs doivent être remplis.');
            return;
        }

        $.ajax({
            url: 'ajouter_sequence.php',
            method: 'POST',
            dataType: 'json',
            data: {
                unite_id: uniteId,
                intitule: intitule,
                objectif: objectif,
                volume_horaire: volumeHoraire
            },
            success: function(response) {
                if (response.success) {
                    appendSequenceRow({
                        id: response.id,
                        intitule: intitule,
                        objectif: objectif,
                        volume_horaire: volumeHoraire
                    });
                    $('#intitule_sequence, #objectif_sequence, #volume_horaire').val('');
                    bindEditable();
                    toastr.success('Séquence ajoutée avec succès.');
                } else {
                    toastr.error(response.message || 'Erreur lors de l\'ajout de la séquence.');
                }
            },
            error: function() {
                toastr.error('Erreur de connexion.');
            }
        });
    });

    $('#preview_sequences').on('click', function() {
        var fileInput = $('#sequence_file')[0];
        if (!fileInput.files.length) {
            toastr.error('Veuillez sélectionner un fichier Excel.');
            return;
        }

        var formData = new FormData($('#import_sequences_form')[0]);
        formData.append('action', 'preview');

        $('#confirm_import_sequences').prop('disabled', true);
        renderSequenceImportMessages('<div class="alert alert-info mb-0">Analyse du fichier en cours...</div>');

        $.ajax({
            url: 'import_sequences.php',
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(response) {
                if (!response.success) {
                    renderSequenceImportMessages('<div class="alert alert-danger mb-0">' + escapeHtml(response.message || 'Fichier invalide.') + '</div>');
                    return;
                }

                renderSequenceImportPreview(response.rows || []);
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

                renderSequenceImportMessages(messages);
                $('#confirm_import_sequences').prop('disabled', !response.can_import || !(summary.new > 0));
            },
            error: function(xhr) {
                var message = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Impossible de lire le fichier.';
                renderSequenceImportMessages('<div class="alert alert-danger mb-0">' + escapeHtml(message) + '</div>');
            }
        });
    });

    $('#confirm_import_sequences').on('click', function() {
        var formData = new FormData();
        formData.append('action', 'import');
        formData.append('unite_id', '<?php echo (int) $unite_id; ?>');

        $(this).prop('disabled', true);
        $.ajax({
            url: 'import_sequences.php',
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(response) {
                var summary = response.summary || {};
                if (response.success) {
                    (response.created_rows || []).forEach(function(row) {
                        appendSequenceRow(row);
                    });
                    bindEditable();
                    renderSequenceImportMessages(
                        '<div class="alert alert-success mb-0">' +
                        'Import terminé. Lignes lues : ' + (summary.read || 0) +
                        ' | Importées : ' + (summary.imported || 0) +
                        ' | Ignorées : ' + (summary.ignored || 0) +
                        ' | En erreur : ' + (summary.errors || 0) +
                        '</div>'
                    );
                    toastr.success('Séquences importées avec succès.');
                } else {
                    renderSequenceImportMessages('<div class="alert alert-danger mb-0">' + escapeHtml(response.message || 'Import impossible.') + '</div>');
                }
            },
            error: function(xhr) {
                var message = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Erreur lors de l\'import.';
                renderSequenceImportMessages('<div class="alert alert-danger mb-0">' + escapeHtml(message) + '</div>');
            }
        });
    });

    $(document).on('click', '.delete-sequence', function() {
        var id = $(this).data('id');
        var row = $(this).closest('tr');

        if (!confirm('Êtes-vous sûr de vouloir supprimer cette séquence ?')) {
            return;
        }

        $.ajax({
            url: 'supprimer_sequence.php',
            method: 'POST',
            dataType: 'json',
            data: { id: id },
            success: function(response) {
                if (response.success) {
                    row.remove();
                    toastr.success('Séquence supprimée avec succès.');
                } else {
                    toastr.error(response.message || 'Erreur lors de la suppression.');
                }
            },
            error: function() {
                toastr.error('Erreur de connexion.');
            }
        });
    });

    $('#archiver_unite').on('click', function() {
        if (!confirm("Archiver cette unite ? Les sequences et objectifs lies seront archives. Les seances existantes resteront intactes.")) {
            return;
        }

        $.ajax({
            url: 'archive_unite.php',
            method: 'POST',
            dataType: 'json',
            data: { id: <?php echo (int) $unite_id; ?> },
            success: function(response) {
                if (response.success) {
                    toastr.success(response.message || "Unite archivee avec succes.");
                    window.location.href = 'modifier_filiere.php?id=<?php echo (int) $unite['filiere_id']; ?>';
                } else {
                    toastr.error(response.message || "Archivage impossible.");
                }
            },
            error: function() {
                toastr.error("Erreur de connexion.");
            }
        });
    });

    $('#reactiver_unite').on('click', function() {
        if (!confirm("Réactiver cette unité ? Elle redeviendra immédiatement modifiable.")) {
            return;
        }

        $.ajax({
            url: 'archive_unite.php',
            method: 'POST',
            dataType: 'json',
            data: { id: <?php echo (int) $unite_id; ?>, archive_action: 'reactivate' },
            success: function(response) {
                if (response.success) {
                    toastr.success(response.message || "Unité réactivée avec succès.");
                    window.location.reload();
                } else {
                    toastr.error(response.message || "Réactivation impossible.");
                }
            },
            error: function() {
                toastr.error("Erreur de connexion.");
            }
        });
    });

    $('#supprimer_unite').on('click', function() {
        if (!confirm("Supprimer definitivement cette unite ? Cette action est irreversible et sera autorisee uniquement si aucune sequence n'existe.")) {
            return;
        }

        $.ajax({
            url: 'supprimer_unite.php',
            method: 'POST',
            dataType: 'json',
            data: { id: <?php echo (int) $unite_id; ?> },
            success: function(response) {
                if (response.success) {
                    toastr.success(response.message || "Unite supprimee definitivement.");
                    window.location.href = 'modifier_filiere.php?id=<?php echo (int) $unite['filiere_id']; ?>';
                } else {
                    toastr.error(response.message || "Suppression impossible.");
                }
            },
            error: function() {
                toastr.error("Erreur de connexion.");
            }
        });
    });
});
</script>

<?php include 'footer.php'; ?>
