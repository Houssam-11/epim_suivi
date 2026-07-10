<?php

ob_start();

include 'page_formateur.php';
require_once __DIR__ . '/annees_scolaires.php';
require_once __DIR__ . '/includes/unite_helper.php';

unite_ensure_columns($conn);

// Récupérer l'ID de la séance à modifier depuis l'URL
$seance_id = filter_input(INPUT_GET, 'seance_id', FILTER_VALIDATE_INT);
if (!$seance_id || $seance_id < 1) {
    http_response_code(400);
    exit('Séance invalide.');
}

// Récupérer les informations actuelles de la séance + la séquence/unité associées
$sql = "SELECT sp.*, sq.unite_id, sq.intitule AS sequence_intitule, COALESCE(uf.type_unite, ?) AS type_unite, COALESCE(uf.masse_horaire, 0) AS masse_horaire_unite
        FROM seances_pedagogiques sp
        LEFT JOIN sequences_pedagogiques sq ON sp.sequence_id = sq.id
        LEFT JOIN unites_de_formation uf ON uf.id = sq.unite_id
        WHERE sp.id = ?";
$stmt = $conn->prepare($sql);
$default_type_unite = TYPE_UNITE_PEDAGOGIQUE;
$stmt->bind_param("si", $default_type_unite, $seance_id);
$stmt->execute();
$result = $stmt->get_result();
$seance = $result->fetch_assoc();
$stmt->close();
$is_stage_unite = $seance && unite_normalize_type($seance['type_unite'] ?? TYPE_UNITE_PEDAGOGIQUE) === TYPE_UNITE_STAGE;

$annee_scolaire_id = annee_scolaire_selected_id(
    $conn,
    $_POST['annee_scolaire_id'] ?? $_GET['annee_scolaire_id'] ?? ($seance['annee_scolaire_id'] ?? null)
);

// Vérifier si la séance existe
if (!$seance) {
    http_response_code(404);
    exit("Séance non trouvée");
}

if (!annee_scolaire_is_editable_for_current_user($conn, (int) ($seance['annee_scolaire_id'] ?? 0))) {
    http_response_code(403);
    exit("Cette année scolaire est archivée. Modification non autorisée.");
}

// Si le formulaire est soumis
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $date_seance = trim((string) ($_POST['date_seance'] ?? ''));
    $objectif_pedagogique = trim((string) ($_POST['objectif_pedagogique'] ?? ''));
    $description_activites = trim((string) ($_POST['description_activites'] ?? ''));
    $observations = trim((string) ($_POST['observations'] ?? ''));
    $dispositions_prochaine = trim((string) ($_POST['dispositions_prochaine'] ?? ''));
    $heures_reelles_input = trim((string) ($_POST['heures_reelles'] ?? ''));
    $heures_reelles = $heures_reelles_input === ''
        ? (float) $seance['heures_officielles']
        : filter_var($heures_reelles_input, FILTER_VALIDATE_FLOAT);
    $controle_continu = isset($_POST['controle_continu']) ? 1 : 0;

    $requiredText = [$date_seance, $objectif_pedagogique, $description_activites, $observations, $dispositions_prochaine];
    if ($heures_reelles === false || in_array('', $requiredText, true)) {
        $error = "Veuillez renseigner tous les champs obligatoires.";
    } elseif (!annee_scolaire_date_in_period($conn, $annee_scolaire_id, $date_seance)) {
        $error = "La date saisie n'appartient pas à l'année scolaire sélectionnée.";
    } elseif (!annee_scolaire_is_editable_for_current_user($conn, $annee_scolaire_id)) {
        $error = "Cette année scolaire est archivée. Modification non autorisée.";
    } else {
        // Mettre à jour la séance dans la base de données
        $sql_update = "UPDATE seances_pedagogiques SET annee_scolaire_id = ?, date_seance = ?, objectif_pedagogique = ?, description_activites = ?, observations_formateur = ?, dispositions_prochaine = ?, heures_reelles = ?, controle_continu = ? WHERE id = ?";
        $stmt_update = $conn->prepare($sql_update);
        $stmt_update->bind_param("isssssdii", $annee_scolaire_id, $date_seance, $objectif_pedagogique, $description_activites, $observations, $dispositions_prochaine, $heures_reelles, $controle_continu, $seance_id);

        if ($stmt_update->execute()) {
            $stmt_update->close();
            if (ob_get_length()) {
                ob_clean();
            }
            header("Location: gestion_seances.php?unite_id=" . (int) $seance['unite_id'] . "&annee_scolaire_id=" . (int) $annee_scolaire_id);
            exit();
        } else {
            $error = "Erreur lors de la mise à jour de la séance.";
        }
        $stmt_update->close();
    }
}

?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Modifier une Séance - EPIM</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <link rel="stylesheet" href="assets/css/theme.css">
</head>
<body>
    <div class="container-fluid mt-2 fade-in">
        <div class="page-header">
            <h2><i class="fas fa-pen text-primary mr-2"></i>Modifier la séance pédagogique</h2>
            <p>Séquence : <strong><?php echo htmlspecialchars($seance['sequence_intitule'] ?? '', ENT_QUOTES, 'UTF-8'); ?></strong></p>
        </div>

        <!-- Affichage d'un message d'erreur si nécessaire -->
        <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <div class="epim-card p-4">
        <!-- Formulaire de modification d'une séance -->
        <form method="POST" action="modifier_seance.php?seance_id=<?php echo $seance_id; ?>&annee_scolaire_id=<?php echo (int) $annee_scolaire_id; ?>">
            <input type="hidden" name="annee_scolaire_id" value="<?php echo (int) $annee_scolaire_id; ?>">
            <input type="hidden" id="sequence_id" value="<?php echo (int) $seance['sequence_id']; ?>">

            <div class="form-group">
                <label for="date_seance"><i class="fas fa-calendar-alt mr-1 text-primary"></i> Date et horaire de la séance</label>
                <input type="datetime-local" class="form-control" id="date_seance" name="date_seance" value="<?php echo date('Y-m-d\TH:i', strtotime($seance['date_seance'])); ?>" required>
            </div>

            <hr class="my-4">

            <!-- OBJECTIF : sélection uniquement, aucune saisie libre -->
            <div class="form-group">
                <label for="objectif_picker"><i class="fas fa-bullseye mr-1 text-primary"></i> Objectif pédagogique de la séance</label>
                <select class="form-control recommendation-picker" id="objectif_picker" data-target="objectif_pedagogique" data-hidden="recommendation_objectif_id" required>
                    <option value="">Chargement des propositions...</option>
                </select>
                <span class="field-hint"><i class="fas fa-info-circle"></i> L'objectif est obligatoirement choisi dans la liste liée à la séquence (aucune saisie libre).</span>
                <input type="hidden" id="recommendation_objectif_id" name="recommendation_objectif_id">
                <input type="hidden" id="objectif_pedagogique" name="objectif_pedagogique" value="<?php echo htmlspecialchars($seance['objectif_pedagogique'], ENT_QUOTES, 'UTF-8'); ?>" required>
            </div>

            <!-- DESCRIPTION : sélection parmi 5 à 10 propositions, modifiable manuellement -->
            <div class="form-group">
                <label for="description_picker"><i class="fas fa-align-left mr-1 text-primary"></i> Descriptif du déroulement de la séance</label>
                <select class="form-control recommendation-picker mb-2" id="description_picker" data-target="description_activites" data-hidden="recommendation_description_id" disabled>
                    <option value="">-- Sélectionnez d'abord un objectif --</option>
                </select>
                <span class="field-hint mb-2"><i class="fas fa-pen"></i> Vous pouvez modifier librement le texte ci-dessous après sélection.</span>
                <input type="hidden" id="recommendation_description_id" name="recommendation_description_id">
                <textarea class="form-control" id="description_activites" name="description_activites" rows="4" maxlength="10000" required><?php echo htmlspecialchars($seance['description_activites'], ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>

            <!-- OBSERVATION : sélection parmi 5 à 10 propositions, modifiable manuellement -->
            <div class="form-group">
                <label for="observation_picker"><i class="fas fa-eye mr-1 text-primary"></i> Observations du formateur en fin de séance</label>
                <select class="form-control recommendation-picker mb-2" id="observation_picker" data-target="observations" data-hidden="recommendation_observation_id" disabled>
                    <option value="">-- Sélectionnez d'abord une description --</option>
                </select>
                <span class="field-hint mb-2"><i class="fas fa-pen"></i> Vous pouvez modifier librement le texte ci-dessous après sélection.</span>
                <input type="hidden" id="recommendation_observation_id" name="recommendation_observation_id">
                <textarea class="form-control" id="observations" name="observations" rows="3" maxlength="10000" required><?php echo htmlspecialchars($seance['observations_formateur'], ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>

            <!-- DISPOSITION : sélection parmi 5 à 10 propositions, modifiable manuellement -->
            <div class="form-group">
                <label for="disposition_picker"><i class="fas fa-arrow-circle-right mr-1 text-primary"></i> Dispositions pour la prochaine séance</label>
                <select class="form-control recommendation-picker mb-2" id="disposition_picker" data-target="dispositions_prochaine" data-hidden="recommendation_disposition_id" disabled>
                    <option value="">-- Sélectionnez d'abord une observation --</option>
                </select>
                <span class="field-hint mb-2"><i class="fas fa-pen"></i> Vous pouvez modifier librement le texte ci-dessous après sélection.</span>
                <input type="hidden" id="recommendation_disposition_id" name="recommendation_disposition_id">
                <textarea class="form-control" id="dispositions_prochaine" name="dispositions_prochaine" rows="3" maxlength="10000" required><?php echo htmlspecialchars($seance['dispositions_prochaine'], ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>

            <hr class="my-4">

            <div class="form-group">
                <div class="custom-control custom-checkbox">
                    <input type="checkbox" class="custom-control-input" id="controle_continu" name="controle_continu" value="1" <?php echo !empty($seance['controle_continu']) ? 'checked' : ''; ?>>
                    <label class="custom-control-label" for="controle_continu">
                        <i class="fas fa-clipboard-check mr-1 text-primary"></i> Contrôle continu réalisé pendant cette séance
                    </label>
                </div>
            </div>

            <hr class="my-4">

            <div class="form-group">
                <?php if ($is_stage_unite): ?>
                    <label for="heures_reelles"><i class="fas fa-clock mr-1 text-primary"></i>Masse horaire réalisée</label>
                    <span class="field-hint mb-2"><i class="fas fa-info-circle"></i> Modifiez cette valeur uniquement si le stage est réalisé partiellement.</span>
                    <input type="number" step="0.5" min="0" class="form-control" id="heures_reelles" name="heures_reelles" value="<?php echo htmlspecialchars((string) $seance['heures_reelles'], ENT_QUOTES, 'UTF-8'); ?>" required>
                <?php else: ?>
                <label for="heures_reelles"><i class="fas fa-clock mr-1 text-primary"></i>Nombre d'heures réalisées (choisir si différente de la période prévue)</label>
                <span class="field-hint mb-2"><i class="fas fa-info-circle"></i> Laissez vide pour revenir aux heures prevues.</span>
                <select class="form-control" id="heures_reelles" name="heures_reelles">
                    <option value="">Utiliser les heures prevues (<?php echo htmlspecialchars((string) $seance['heures_officielles'], ENT_QUOTES, 'UTF-8'); ?> h)</option>
                    <option value="1" <?php echo $seance['heures_reelles'] == '1' ? 'selected' : ''; ?>>1 heure</option>
                    <option value="1.5" <?php echo $seance['heures_reelles'] == '1.5' ? 'selected' : ''; ?>>1h30</option>
                    <option value="2" <?php echo $seance['heures_reelles'] == '2' ? 'selected' : ''; ?>>2 heures</option>
                    <option value="2.5" <?php echo $seance['heures_reelles'] == '2.5' ? 'selected' : ''; ?>>2h30</option>
                    <option value="3" <?php echo $seance['heures_reelles'] == '3' ? 'selected' : ''; ?>>3 heures</option>
                    <option value="3.5" <?php echo $seance['heures_reelles'] == '3.5' ? 'selected' : ''; ?>>3h30</option>
                    <option value="4" <?php echo $seance['heures_reelles'] == '4' ? 'selected' : ''; ?>>4 heures</option>
                </select>
                <?php endif; ?>
            </div>

            <button type="submit" class="btn btn-epim-primary mt-3"><i class="fas fa-save mr-1"></i> Enregistrer les modifications</button>
            <a href="gestion_seances.php?unite_id=<?php echo (int) $seance['unite_id']; ?>&annee_scolaire_id=<?php echo (int) $annee_scolaire_id; ?>" class="btn btn-outline-epim mt-3">Annuler</a>
        </form>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const sequenceId = document.getElementById('sequence_id').value;
        const RECO_LIMIT = 8;

        // Valeurs actuelles de la séance (pré-remplissage / présélection)
        const currentValues = {
            objectif: <?php echo json_encode($seance['objectif_pedagogique'], JSON_UNESCAPED_UNICODE); ?>,
            description: <?php echo json_encode($seance['description_activites'], JSON_UNESCAPED_UNICODE); ?>,
            observation: <?php echo json_encode($seance['observations_formateur'], JSON_UNESCAPED_UNICODE); ?>,
            disposition: <?php echo json_encode($seance['dispositions_prochaine'], JSON_UNESCAPED_UNICODE); ?>
        };

        const levels = {
            objectif:    { picker: 'objectif_picker',    endpoint: 'get_objectifs.php',    next: 'description', mode: 'select',   emptyMsg: 'Aucun objectif disponible pour cette séquence' },
            description: { picker: 'description_picker', endpoint: 'get_descriptions.php', next: 'observation', mode: 'editable', emptyMsg: 'Aucune description liée : conservez ou modifiez le texte actuel' },
            observation: { picker: 'observation_picker', endpoint: 'get_observations.php', next: 'disposition', mode: 'editable', emptyMsg: 'Aucune observation liée : conservez ou modifiez le texte actuel' },
            disposition: { picker: 'disposition_picker', endpoint: 'get_dispositions.php', next: null,          mode: 'editable', emptyMsg: 'Aucune disposition liée : conservez ou modifiez le texte actuel' }
        };
        const order = ['objectif', 'description', 'observation', 'disposition'];

        function resetLevel(type, message, disable) {
            const config = levels[type];
            const picker = document.getElementById(config.picker);
            picker.innerHTML = '';
            picker.append(new Option(message, ''));
            picker.disabled = disable;
            document.getElementById(picker.dataset.hidden).value = '';
        }

        async function loadRecommendations(type, parentId, selectCurrent) {
            const config = levels[type];
            const picker = document.getElementById(config.picker);
            resetLevel(type, 'Chargement des propositions...', true);

            const params = new URLSearchParams({ sequence_id: sequenceId, limit: String(RECO_LIMIT) });
            if (parentId) {
                params.set('parent_id', parentId);
            }
            try {
                const response = await fetch(config.endpoint + '?' + params.toString(), {
                    headers: { 'Accept': 'application/json' }
                });
                if (!response.ok) throw new Error('HTTP ' + response.status);
                const payload = await response.json();
                picker.innerHTML = '';

                // S'assurer que la valeur actuelle de la séance figure dans la liste
                let currentInList = payload.data.some(item => item.text === currentValues[type]);
                if (!currentInList && currentValues[type]) {
                    payload.data.unshift({ id: 'current', text: currentValues[type], source: 'actuel' });
                }

                if (!payload.data.length) {
                    picker.append(new Option(config.emptyMsg, ''));
                    picker.disabled = (config.mode === 'select');
                    return;
                }

                picker.append(new Option('-- Choisir une proposition (' + payload.data.length + ') --', ''));
                payload.data.forEach(function (item) {
                    const option = new Option(item.text, String(item.id));
                    option.dataset.text = item.text;
                    option.dataset.source = item.source;
                    if (selectCurrent && item.text === currentValues[type]) {
                        option.selected = true;
                    }
                    picker.append(option);
                });
                picker.disabled = false;

                // Déclencher le chargement du niveau suivant si une valeur actuelle a été présélectionnée
                if (selectCurrent && config.next && picker.value) {
                    loadRecommendations(config.next, picker.value, true);
                }
            } catch (error) {
                picker.innerHTML = '';
                picker.append(new Option('Propositions indisponibles, réessayez plus tard', ''));
                picker.disabled = true;
                console.error(error);
            }
        }

        order.forEach(function (type) {
            const config = levels[type];
            const picker = document.getElementById(config.picker);

            picker.addEventListener('change', function () {
                const option = picker.options[picker.selectedIndex];
                const selectedId = picker.value;
                document.getElementById(picker.dataset.hidden).value = (selectedId === 'current') ? '' : selectedId;
                const target = document.getElementById(picker.dataset.target);

                target.value = selectedId ? option.dataset.text : '';

                if (config.next) {
                    order.slice(order.indexOf(type) + 1).forEach(function (downstreamType) {
                        resetLevel(downstreamType, "-- Sélectionner d'abord le niveau précédent --", true);
                    });
                    if (selectedId) {
                        loadRecommendations(config.next, selectedId, false);
                    }
                }
            });
        });

        // Chargement initial : on présélectionne automatiquement les valeurs déjà enregistrées
        loadRecommendations('objectif', null, true);
    });
    </script>
    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.9.2/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>
</html>
<?php
$conn->close();
?>
