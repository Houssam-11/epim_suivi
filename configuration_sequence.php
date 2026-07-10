<?php
include 'page_directeur.php';

$sequence_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;

$stmt_sequence = $conn->prepare(
    'SELECT seq.id, seq.intitule, seq.objectif, seq.volume_horaire,
            uf.id AS unite_id, uf.intitule AS unite_intitule,
            f.nom AS filiere_nom, COALESCE(f.is_archived, 0) AS filiere_is_archived
     FROM sequences_pedagogiques seq
     INNER JOIN unites_de_formation uf ON uf.id = seq.unite_id
     LEFT JOIN filieres f ON f.id = uf.filiere_id
     WHERE seq.id = ?
     LIMIT 1'
);
$stmt_sequence->bind_param('i', $sequence_id);
$stmt_sequence->execute();
$sequence = $stmt_sequence->get_result()->fetch_assoc();
$stmt_sequence->close();

if (!$sequence) {
    echo '<div class="alert alert-danger">Séquence pédagogique introuvable.</div>';
    include 'footer.php';
    exit();
}

$sequence_readonly = (int) ($sequence['filiere_is_archived'] ?? 0) === 1;

$stmt_objectifs = $conn->prepare(
    'SELECT id, objectif, volume_horaire
     FROM objectifs_sequences
     WHERE sequence_id = ?
     ORDER BY ordre ASC, id ASC'
);
$stmt_objectifs->bind_param('i', $sequence_id);
$stmt_objectifs->execute();
$result_objectifs = $stmt_objectifs->get_result();

$objectifs = [];
while ($objectif = $result_objectifs->fetch_assoc()) {
    $objectifs[] = $objectif;
}
$stmt_objectifs->close();

$stmt_descriptifs = $conn->prepare(
    'SELECT d.id, d.objectif_sequence_id, d.description, d.sujet, o.objectif
     FROM descriptifs_objectifs_sequences d
     INNER JOIN objectifs_sequences o ON o.id = d.objectif_sequence_id
     WHERE o.sequence_id = ?
     ORDER BY o.ordre ASC, d.ordre ASC, d.id ASC'
);
$stmt_descriptifs->bind_param('i', $sequence_id);
$stmt_descriptifs->execute();
$result_descriptifs = $stmt_descriptifs->get_result();
?>

<div class="container-fluid fade-in">
    <div class="page-header d-flex flex-column flex-md-row justify-content-between align-items-md-end">
        <div>
            <h1 class="page-title">Configuration pédagogique</h1>
            <p class="page-subtitle"><?php echo htmlspecialchars($sequence['intitule'], ENT_QUOTES, 'UTF-8'); ?></p>
        </div>
        <a href="gerer_unite.php?id=<?php echo (int) $sequence['unite_id']; ?>" class="btn btn-outline-epim mt-3 mt-md-0">
            <i class="fas fa-arrow-left mr-1"></i>Retour à l'unité
        </a>
    </div>

    <?php if ($sequence_readonly): ?>
        <div class="alert alert-warning">
            Cette filière est archivée. La configuration pédagogique reste consultable, mais les ajouts, imports, modifications et suppressions sont désactivés.
        </div>
    <?php endif; ?>

    <div class="epim-card no-hover p-4 mb-4">
        <div class="section-header">
            <div>
                <h2>Informations de la séquence</h2>
                <p>Données générales en lecture seule.</p>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group col-lg-4">
                <label>Filière</label>
                <input type="text" class="form-control" value="<?php echo htmlspecialchars($sequence['filiere_nom'] ?: '-', ENT_QUOTES, 'UTF-8'); ?>" readonly>
            </div>
            <div class="form-group col-lg-4">
                <label>Unité</label>
                <input type="text" class="form-control" value="<?php echo htmlspecialchars($sequence['unite_intitule'], ENT_QUOTES, 'UTF-8'); ?>" readonly>
            </div>
            <div class="form-group col-lg-4">
                <label>Volume horaire</label>
                <input type="text" class="form-control" value="<?php echo htmlspecialchars((string) $sequence['volume_horaire'], ENT_QUOTES, 'UTF-8'); ?> h" readonly>
            </div>
            <div class="form-group col-lg-12">
                <label>Intitulé de la séquence</label>
                <input type="text" class="form-control" value="<?php echo htmlspecialchars($sequence['intitule'], ENT_QUOTES, 'UTF-8'); ?>" readonly>
            </div>
            <div class="form-group col-lg-12">
                <label>Objectif général</label>
                <textarea class="form-control" rows="3" readonly><?php echo htmlspecialchars($sequence['objectif'], ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>
        </div>
    </div>

    <div class="epim-card no-hover p-4 mb-4">
        <div class="section-header">
            <div>
                <h2>Objectifs pédagogiques</h2>
                <p>Chaque objectif appartient à cette séquence.</p>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group col-lg-8">
                <label for="objectif_pedagogique">Objectif pédagogique</label>
                <textarea id="objectif_pedagogique" class="form-control" rows="2" required></textarea>
            </div>
            <div class="form-group col-lg-4">
                <label for="objectif_volume">Volume horaire</label>
                <input type="number" step="0.5" min="0" id="objectif_volume" class="form-control" required>
            </div>
        </div>
        <button type="button" class="btn btn-epim-primary" id="ajouter_objectif_sequence">
            <i class="fas fa-plus mr-1"></i>Ajouter l'objectif
        </button>
    </div>

    <div class="epim-card no-hover p-4 mb-4">
        <div class="section-header">
            <div>
                <h2>Importer des objectifs</h2>
                <p>Importer un fichier Excel contenant les objectifs pédagogiques de cette séquence.</p>
            </div>
        </div>
        <form id="import_objectifs_sequence_form" enctype="multipart/form-data">
            <input type="hidden" name="sequence_id" value="<?php echo (int) $sequence_id; ?>">
            <div class="form-row align-items-end">
                <div class="form-group col-lg-6">
                    <label for="objectifs_file">Fichier Excel</label>
                    <input id="objectifs_file" type="file" name="file" class="form-control" accept=".xlsx,.xls,.csv" required>
                </div>
                <div class="form-group col-lg-6 d-flex flex-wrap" style="gap:8px;">
                    <a href="import_objectifs_sequence.php?action=template" class="btn btn-outline-epim">
                        <i class="fas fa-download mr-1"></i>Télécharger le modèle
                    </a>
                    <button type="button" class="btn btn-epim-accent" id="preview_objectifs_sequence">
                        <i class="fas fa-eye mr-1"></i>Prévisualiser
                    </button>
                    <button type="button" class="btn btn-epim-primary" id="confirm_import_objectifs_sequence" disabled>
                        <i class="fas fa-file-import mr-1"></i>Importer
                    </button>
                </div>
            </div>
        </form>
        <div id="import_objectifs_sequence_messages" class="mt-3"></div>
        <div id="import_objectifs_sequence_preview" class="table-responsive mt-3" hidden>
            <table class="table epim-table table-borderless mb-0">
                <thead>
                    <tr>
                        <th>Objectif pédagogique</th>
                        <th>Volume horaire</th>
                        <th>Statut</th>
                    </tr>
                </thead>
                <tbody id="import_objectifs_sequence_preview_body"></tbody>
            </table>
        </div>
    </div>

    <div class="epim-card no-hover p-3 mb-4">
        <div class="section-header px-1">
            <div>
                <h2>Objectifs configurés</h2>
                <p>Liste des objectifs pédagogiques associés à cette séquence.</p>
            </div>
        </div>
        <div class="table-responsive epim-data-table">
            <table class="table epim-table table-borderless mb-0">
                <thead>
                    <tr>
                        <th>Objectif pédagogique</th>
                        <th>Volume horaire</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody id="objectifs_sequence_liste">
                    <?php if ($objectifs): ?>
                        <?php foreach ($objectifs as $objectif): ?>
                            <tr data-id="<?php echo (int) $objectif['id']; ?>">
                                <td data-field="objectif"><?php echo htmlspecialchars($objectif['objectif'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td data-field="volume_horaire"><span class="badge-epim-info"><?php echo htmlspecialchars((string) $objectif['volume_horaire'], ENT_QUOTES, 'UTF-8'); ?> h</span></td>
                                <td class="text-center">
                                    <button type="button" class="btn btn-outline-epim btn-sm edit-objectif-sequence"><i class="fas fa-edit mr-1"></i>Modifier</button>
                                    <button type="button" class="btn btn-outline-danger-epim btn-sm delete-objectif-sequence"><i class="fas fa-trash mr-1"></i>Supprimer</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="3" class="text-center text-muted py-4">Aucun objectif pédagogique configuré.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="epim-card no-hover p-4 mb-4">
        <div class="section-header">
            <div>
                <h2>Descriptifs pédagogiques</h2>
                <p>Chaque descriptif est lié à un objectif et porte son sujet pédagogique résumé.</p>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group col-lg-4">
                <label for="descriptif_objectif_id">Objectif pédagogique</label>
                <select id="descriptif_objectif_id" class="form-control" required>
                    <option value="">Choisissez un objectif</option>
                    <?php foreach ($objectifs as $objectif): ?>
                        <option value="<?php echo (int) $objectif['id']; ?>"><?php echo htmlspecialchars($objectif['objectif'], ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group col-lg-8">
                <label for="descriptif_description">Description pédagogique</label>
                <textarea id="descriptif_description" class="form-control" rows="2" required></textarea>
            </div>
            <div class="form-group col-lg-8">
                <label for="descriptif_sujet">
                    Sujet
                    <i class="fas fa-info-circle text-muted ml-1" tabindex="0" aria-label="Aide sur le sujet" title="Le sujet est un résumé court du descriptif pédagogique. Il sera proposé au formateur lors de la saisie d'une séance afin de faciliter le choix de l'observation pédagogique correspondante."></i>
                </label>
                <input type="text" id="descriptif_sujet" class="form-control" maxlength="100" placeholder="Caractéristiques des factures et des avoirs" required>
            </div>
        </div>
        <button type="button" class="btn btn-epim-primary" id="ajouter_descriptif_objectif">
            <i class="fas fa-plus mr-1"></i>Ajouter le descriptif
        </button>
    </div>

    <div class="epim-card no-hover p-4 mb-4">
        <div class="section-header">
            <div>
                <h2>Importer des descriptifs</h2>
                <p>Importer les descriptifs pour l'objectif sélectionné.</p>
            </div>
        </div>
        <form id="import_descriptifs_objectif_form" enctype="multipart/form-data">
            <div class="form-row align-items-end">
                <div class="form-group col-lg-4">
                    <label for="import_descriptif_objectif_id">Objectif pédagogique</label>
                    <select id="import_descriptif_objectif_id" name="objectif_id" class="form-control" required>
                        <option value="">Choisissez un objectif</option>
                        <?php foreach ($objectifs as $objectif): ?>
                            <option value="<?php echo (int) $objectif['id']; ?>"><?php echo htmlspecialchars($objectif['objectif'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group col-lg-4">
                    <label for="descriptifs_file">Fichier Excel</label>
                    <input id="descriptifs_file" type="file" name="file" class="form-control" accept=".xlsx,.xls,.csv" required>
                </div>
                <div class="form-group col-lg-4 d-flex flex-wrap" style="gap:8px;">
                    <a href="import_descriptifs_objectif.php?action=template" class="btn btn-outline-epim">
                        <i class="fas fa-download mr-1"></i>Télécharger le modèle
                    </a>
                    <button type="button" class="btn btn-epim-accent" id="preview_descriptifs_objectif">
                        <i class="fas fa-eye mr-1"></i>Prévisualiser
                    </button>
                    <button type="button" class="btn btn-epim-primary" id="confirm_import_descriptifs_objectif" disabled>
                        <i class="fas fa-file-import mr-1"></i>Importer
                    </button>
                </div>
            </div>
        </form>
        <div id="import_descriptifs_objectif_messages" class="mt-3"></div>
        <div id="import_descriptifs_objectif_preview" class="table-responsive mt-3" hidden>
            <table class="table epim-table table-borderless mb-0">
                <thead>
                    <tr>
                        <th>Description pédagogique</th>
                        <th>Sujet</th>
                        <th>Statut</th>
                    </tr>
                </thead>
                <tbody id="import_descriptifs_objectif_preview_body"></tbody>
            </table>
        </div>
    </div>

    <div class="epim-card no-hover p-3">
        <div class="section-header px-1">
            <div>
                <h2>Descriptifs configurés</h2>
                <p>Liste des descriptifs associés aux objectifs de cette séquence.</p>
            </div>
        </div>
        <div class="table-responsive epim-data-table">
            <table class="table epim-table table-borderless mb-0">
                <thead>
                    <tr>
                        <th>Objectif pédagogique</th>
                        <th>Description pédagogique</th>
                        <th>Sujet</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody id="descriptifs_objectif_liste">
                    <?php if ($result_descriptifs && $result_descriptifs->num_rows > 0): ?>
                        <?php while ($descriptif = $result_descriptifs->fetch_assoc()): ?>
                            <tr data-id="<?php echo (int) $descriptif['id']; ?>" data-objectif-id="<?php echo (int) $descriptif['objectif_sequence_id']; ?>">
                                <td data-field="objectif_label"><?php echo htmlspecialchars($descriptif['objectif'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td data-field="description"><?php echo htmlspecialchars($descriptif['description'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td data-field="sujet"><?php echo htmlspecialchars($descriptif['sujet'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="text-center">
                                    <button type="button" class="btn btn-outline-epim btn-sm edit-descriptif-objectif"><i class="fas fa-edit mr-1"></i>Modifier</button>
                                    <button type="button" class="btn btn-outline-danger-epim btn-sm delete-descriptif-objectif"><i class="fas fa-trash mr-1"></i>Supprimer</button>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" class="text-center text-muted py-4">Aucun descriptif pédagogique configuré.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
$(function() {
    var sequenceId = <?php echo (int) $sequence_id; ?>;
    var sequenceReadOnly = <?php echo $sequence_readonly ? 'true' : 'false'; ?>;

    if (sequenceReadOnly) {
        $('input:not([readonly]), textarea:not([readonly]), select, button').prop('disabled', true);
    }

    function escapeHtml(value) {
        return $('<div>').text(value || '').html();
    }

    function formatVolume(value) {
        return String(value).replace(/\.00$/, '');
    }

    function optionLabel(selectId, value) {
        return $('#' + selectId + ' option[value="' + value + '"]').text();
    }

    function appendObjectifOption(row) {
        var option = $('<option>').val(row.id).text(row.objectif);
        $('#descriptif_objectif_id, #import_descriptif_objectif_id').append(option.clone());
    }

    function appendObjectifRow(row) {
        $('#objectifs_sequence_liste tr td[colspan="3"]').closest('tr').remove();
        $('#objectifs_sequence_liste').append(
            '<tr data-id="' + row.id + '">' +
            '<td data-field="objectif">' + escapeHtml(row.objectif) + '</td>' +
            '<td data-field="volume_horaire"><span class="badge-epim-info">' + escapeHtml(formatVolume(row.volume_horaire)) + ' h</span></td>' +
            '<td class="text-center">' +
            '<button type="button" class="btn btn-outline-epim btn-sm edit-objectif-sequence"><i class="fas fa-edit mr-1"></i>Modifier</button> ' +
            '<button type="button" class="btn btn-outline-danger-epim btn-sm delete-objectif-sequence"><i class="fas fa-trash mr-1"></i>Supprimer</button>' +
            '</td>' +
            '</tr>'
        );
        appendObjectifOption(row);
    }

    function appendDescriptifRow(row) {
        $('#descriptifs_objectif_liste tr td[colspan="4"]').closest('tr').remove();
        var label = row.objectif_label || optionLabel('descriptif_objectif_id', row.objectif_id);
        $('#descriptifs_objectif_liste').append(
            '<tr data-id="' + row.id + '" data-objectif-id="' + row.objectif_id + '">' +
            '<td data-field="objectif_label">' + escapeHtml(label) + '</td>' +
            '<td data-field="description">' + escapeHtml(row.description) + '</td>' +
            '<td data-field="sujet">' + escapeHtml(row.sujet) + '</td>' +
            '<td class="text-center">' +
            '<button type="button" class="btn btn-outline-epim btn-sm edit-descriptif-objectif"><i class="fas fa-edit mr-1"></i>Modifier</button> ' +
            '<button type="button" class="btn btn-outline-danger-epim btn-sm delete-descriptif-objectif"><i class="fas fa-trash mr-1"></i>Supprimer</button>' +
            '</td>' +
            '</tr>'
        );
    }

    function statusBadge(row, labelNew, labelExisting) {
        if (row.status_type === 'new') {
            return '<span class="badge-epim-success">' + labelNew + '</span>';
        }
        if (row.status_type === 'existing') {
            return '<span class="badge-epim-warning">' + labelExisting + '</span>';
        }
        return '<span class="badge-epim-danger">Erreur</span>';
    }

    function renderMessages(target, html) {
        $(target).html(html || '');
    }

    $('#ajouter_objectif_sequence').on('click', function() {
        var objectif = $('#objectif_pedagogique').val();
        var volume = $('#objectif_volume').val();
        if (objectif.trim() === '' || volume.trim() === '') {
            toastr.error('Tous les champs doivent être remplis.');
            return;
        }
        $.ajax({
            url: 'objectif_sequence_action.php',
            method: 'POST',
            dataType: 'json',
            data: { action: 'add', sequence_id: sequenceId, objectif: objectif, volume_horaire: volume },
            success: function(response) {
                if (response.success) {
                    appendObjectifRow(response.row);
                    $('#objectif_pedagogique, #objectif_volume').val('');
                    toastr.success('Objectif ajouté avec succès.');
                } else {
                    toastr.error(response.message || 'Ajout impossible.');
                }
            },
            error: function() {
                toastr.error('Erreur de connexion.');
            }
        });
    });

    $(document).on('click', '.edit-objectif-sequence', function() {
        var row = $(this).closest('tr');
        if (row.hasClass('is-editing')) return;
        row.addClass('is-editing');
        var objectif = row.find('[data-field="objectif"]').text().trim();
        var volume = row.find('[data-field="volume_horaire"]').text().replace('h', '').trim();
        row.find('[data-field="objectif"]').html('<textarea class="form-control form-control-sm edit-objectif" rows="2"></textarea>');
        row.find('.edit-objectif').val(objectif);
        row.find('[data-field="volume_horaire"]').html('<input type="number" step="0.5" min="0" class="form-control form-control-sm edit-volume">');
        row.find('.edit-volume').val(volume);
        $(this).replaceWith('<button type="button" class="btn btn-epim-primary btn-sm save-objectif-sequence"><i class="fas fa-save mr-1"></i>Enregistrer</button>');
    });

    $(document).on('click', '.save-objectif-sequence', function() {
        var row = $(this).closest('tr');
        var id = row.data('id');
        var objectif = row.find('.edit-objectif').val();
        var volume = row.find('.edit-volume').val();
        $.ajax({
            url: 'objectif_sequence_action.php',
            method: 'POST',
            dataType: 'json',
            data: { action: 'update', id: id, objectif: objectif, volume_horaire: volume },
            success: function(response) {
                if (response.success) {
                    row.removeClass('is-editing');
                    row.find('[data-field="objectif"]').text(objectif);
                    row.find('[data-field="volume_horaire"]').html('<span class="badge-epim-info">' + escapeHtml(formatVolume(volume)) + ' h</span>');
                    row.find('.save-objectif-sequence').replaceWith('<button type="button" class="btn btn-outline-epim btn-sm edit-objectif-sequence"><i class="fas fa-edit mr-1"></i>Modifier</button>');
                    $('#descriptif_objectif_id option[value="' + id + '"], #import_descriptif_objectif_id option[value="' + id + '"]').text(objectif);
                    $('#descriptifs_objectif_liste tr[data-objectif-id="' + id + '"] [data-field="objectif_label"]').text(objectif);
                    toastr.success('Objectif modifié avec succès.');
                } else {
                    toastr.error(response.message || 'Modification impossible.');
                }
            },
            error: function() {
                toastr.error('Erreur de connexion.');
            }
        });
    });

    $(document).on('click', '.delete-objectif-sequence', function() {
        if (!confirm('Supprimer cet objectif pédagogique ? Ses descriptifs liés seront également supprimés.')) return;
        var row = $(this).closest('tr');
        var id = row.data('id');
        $.ajax({
            url: 'objectif_sequence_action.php',
            method: 'POST',
            dataType: 'json',
            data: { action: 'delete', id: id },
            success: function(response) {
                if (response.success) {
                    row.remove();
                    $('#descriptif_objectif_id option[value="' + id + '"], #import_descriptif_objectif_id option[value="' + id + '"]').remove();
                    $('#descriptifs_objectif_liste tr[data-objectif-id="' + id + '"]').remove();
                    if ($('#objectifs_sequence_liste tr').length === 0) {
                        $('#objectifs_sequence_liste').append('<tr><td colspan="3" class="text-center text-muted py-4">Aucun objectif pédagogique configuré.</td></tr>');
                    }
                    if ($('#descriptifs_objectif_liste tr').length === 0) {
                        $('#descriptifs_objectif_liste').append('<tr><td colspan="4" class="text-center text-muted py-4">Aucun descriptif pédagogique configuré.</td></tr>');
                    }
                    toastr.success('Objectif supprimé avec succès.');
                } else {
                    toastr.error(response.message || 'Suppression impossible.');
                }
            },
            error: function() {
                toastr.error('Erreur de connexion.');
            }
        });
    });

    $('#ajouter_descriptif_objectif').on('click', function() {
        var objectifId = $('#descriptif_objectif_id').val();
        var description = $('#descriptif_description').val();
        var sujet = $('#descriptif_sujet').val();
        if (!objectifId || description.trim() === '' || sujet.trim() === '') {
            toastr.error('Tous les champs doivent être remplis.');
            return;
        }
        $.ajax({
            url: 'descriptif_objectif_action.php',
            method: 'POST',
            dataType: 'json',
            data: { action: 'add', objectif_id: objectifId, description: description, sujet: sujet },
            success: function(response) {
                if (response.success) {
                    appendDescriptifRow(response.row);
                    $('#descriptif_description, #descriptif_sujet').val('');
                    toastr.success('Descriptif ajouté avec succès.');
                } else {
                    toastr.error(response.message || 'Ajout impossible.');
                }
            },
            error: function() {
                toastr.error('Erreur de connexion.');
            }
        });
    });

    $(document).on('click', '.edit-descriptif-objectif', function() {
        var row = $(this).closest('tr');
        if (row.hasClass('is-editing')) return;
        row.addClass('is-editing');
        var description = row.find('[data-field="description"]').text().trim();
        var sujet = row.find('[data-field="sujet"]').text().trim();
        row.find('[data-field="description"]').html('<textarea class="form-control form-control-sm edit-description" rows="2"></textarea>');
        row.find('.edit-description').val(description);
        row.find('[data-field="sujet"]').html('<div class="d-flex align-items-center" style="gap:6px;"><i class="fas fa-info-circle text-muted" tabindex="0" aria-label="Aide sur le sujet" title="Le sujet est un résumé court du descriptif pédagogique. Il sera proposé au formateur lors de la saisie d\\\'une séance afin de faciliter le choix de l\\\'observation pédagogique correspondante."></i><input type="text" class="form-control form-control-sm edit-sujet" maxlength="100" placeholder="Caractéristiques des factures et des avoirs"></div>');
        row.find('.edit-sujet').val(sujet);
        $(this).replaceWith('<button type="button" class="btn btn-epim-primary btn-sm save-descriptif-objectif"><i class="fas fa-save mr-1"></i>Enregistrer</button>');
    });

    $(document).on('click', '.save-descriptif-objectif', function() {
        var row = $(this).closest('tr');
        var description = row.find('.edit-description').val();
        var sujet = row.find('.edit-sujet').val();
        $.ajax({
            url: 'descriptif_objectif_action.php',
            method: 'POST',
            dataType: 'json',
            data: { action: 'update', id: row.data('id'), description: description, sujet: sujet },
            success: function(response) {
                if (response.success) {
                    row.removeClass('is-editing');
                    row.find('[data-field="description"]').text(description);
                    row.find('[data-field="sujet"]').text(sujet);
                    row.find('.save-descriptif-objectif').replaceWith('<button type="button" class="btn btn-outline-epim btn-sm edit-descriptif-objectif"><i class="fas fa-edit mr-1"></i>Modifier</button>');
                    toastr.success('Descriptif modifié avec succès.');
                } else {
                    toastr.error(response.message || 'Modification impossible.');
                }
            },
            error: function() {
                toastr.error('Erreur de connexion.');
            }
        });
    });

    $(document).on('click', '.delete-descriptif-objectif', function() {
        if (!confirm('Supprimer ce descriptif pédagogique ?')) return;
        var row = $(this).closest('tr');
        $.ajax({
            url: 'descriptif_objectif_action.php',
            method: 'POST',
            dataType: 'json',
            data: { action: 'delete', id: row.data('id') },
            success: function(response) {
                if (response.success) {
                    row.remove();
                    if ($('#descriptifs_objectif_liste tr').length === 0) {
                        $('#descriptifs_objectif_liste').append('<tr><td colspan="4" class="text-center text-muted py-4">Aucun descriptif pédagogique configuré.</td></tr>');
                    }
                    toastr.success('Descriptif supprimé avec succès.');
                } else {
                    toastr.error(response.message || 'Suppression impossible.');
                }
            },
            error: function() {
                toastr.error('Erreur de connexion.');
            }
        });
    });

    function bindImport(previewButton, confirmButton, formSelector, endpoint, messagesSelector, previewSelector, previewBodySelector, renderRow, onImportRows) {
        $(previewButton).on('click', function() {
            var form = $(formSelector)[0];
            var formData = new FormData(form);
            if (!formData.get('file') || (formData.get('objectif_id') !== null && !formData.get('objectif_id'))) {
                toastr.error('Veuillez renseigner les champs de l import.');
                return;
            }
            formData.append('action', 'preview');
            $(confirmButton).prop('disabled', true);
            renderMessages(messagesSelector, '<div class="alert alert-info mb-0">Analyse du fichier en cours...</div>');
            $.ajax({
                url: endpoint,
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    if (!response.success) {
                        renderMessages(messagesSelector, '<div class="alert alert-danger mb-0">' + escapeHtml(response.message || 'Fichier invalide.') + '</div>');
                        return;
                    }
                    var body = $(previewBodySelector);
                    body.empty();
                    (response.rows || []).forEach(function(row) { body.append(renderRow(row)); });
                    $(previewSelector).prop('hidden', !(response.rows || []).length);
                    var summary = response.summary || {};
                    var messages = '<div class="alert ' + (response.can_import ? 'alert-success' : 'alert-warning') + ' mb-0">' +
                        'Lignes lues : ' + (summary.read || 0) +
                        ' | Nouveaux : ' + (summary.new || 0) +
                        ' | Déjà existants : ' + (summary.existing || 0) +
                        ' | Erreurs : ' + (summary.errors || 0) +
                        '</div>';
                    renderMessages(messagesSelector, messages);
                    $(confirmButton).prop('disabled', !response.can_import || !(summary.new > 0));
                },
                error: function(xhr) {
                    var message = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Impossible de lire le fichier.';
                    renderMessages(messagesSelector, '<div class="alert alert-danger mb-0">' + escapeHtml(message) + '</div>');
                }
            });
        });

        $(confirmButton).on('click', function() {
            var formData = new FormData();
            $(formSelector).serializeArray().forEach(function(item) { formData.append(item.name, item.value); });
            formData.append('action', 'import');
            $(this).prop('disabled', true);
            $.ajax({
                url: endpoint,
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    var summary = response.summary || {};
                    if (response.success) {
                        (response.created_rows || []).forEach(onImportRows);
                        renderMessages(messagesSelector,
                            '<div class="alert alert-success mb-0">' +
                            'Import terminé. Lignes lues : ' + (summary.read || 0) +
                            ' | Importés : ' + (summary.imported || 0) +
                            ' | Ignorés : ' + (summary.ignored || 0) +
                            ' | En erreur : ' + (summary.errors || 0) +
                            '</div>'
                        );
                        toastr.success('Import terminé avec succès.');
                    } else {
                        renderMessages(messagesSelector, '<div class="alert alert-danger mb-0">' + escapeHtml(response.message || 'Import impossible.') + '</div>');
                    }
                },
                error: function(xhr) {
                    var message = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Erreur lors de l import.';
                    renderMessages(messagesSelector, '<div class="alert alert-danger mb-0">' + escapeHtml(message) + '</div>');
                }
            });
        });
    }

    bindImport(
        '#preview_objectifs_sequence',
        '#confirm_import_objectifs_sequence',
        '#import_objectifs_sequence_form',
        'import_objectifs_sequence.php',
        '#import_objectifs_sequence_messages',
        '#import_objectifs_sequence_preview',
        '#import_objectifs_sequence_preview_body',
        function(row) {
            var details = row.errors && row.errors.length ? '<div class="small text-danger mt-1">' + escapeHtml(row.errors.join(' ')) + '</div>' : '';
            return '<tr><td>' + escapeHtml(row.objectif) + '</td><td><span class="badge-epim-info">' + escapeHtml(row.volume_horaire) + ' h</span></td><td>' + statusBadge(row, 'Nouvel objectif', 'Déjà existant') + details + '</td></tr>';
        },
        appendObjectifRow
    );

    bindImport(
        '#preview_descriptifs_objectif',
        '#confirm_import_descriptifs_objectif',
        '#import_descriptifs_objectif_form',
        'import_descriptifs_objectif.php',
        '#import_descriptifs_objectif_messages',
        '#import_descriptifs_objectif_preview',
        '#import_descriptifs_objectif_preview_body',
        function(row) {
            var details = row.errors && row.errors.length ? '<div class="small text-danger mt-1">' + escapeHtml(row.errors.join(' ')) + '</div>' : '';
            return '<tr><td>' + escapeHtml(row.description) + '</td><td>' + escapeHtml(row.sujet) + '</td><td>' + statusBadge(row, 'Nouveau descriptif', 'Déjà existant') + details + '</td></tr>';
        },
        appendDescriptifRow
    );
});
</script>

<?php
$stmt_descriptifs->close();
include 'footer.php';
?>
