<?php

ob_start();

include 'page_formateur.php';
require_once __DIR__ . '/recommendation_usage.php';
require_once __DIR__ . '/annees_scolaires.php';
require_once __DIR__ . '/includes/unite_helper.php';

unite_ensure_columns($conn);

// Récupérer l'ID de l'unité de formation depuis l'URL
$unite_id = filter_input(INPUT_GET, 'unite_id', FILTER_VALIDATE_INT);
if (!$unite_id || $unite_id < 1) {
    http_response_code(400);
    exit('Unité de formation invalide.');
}

// Récupérer les informations de l'unité.
$sql_unite = "SELECT heures_par_seance_defaut, intitule, COALESCE(is_archived, 0) AS is_archived, COALESCE(semestre, 1) AS semestre, COALESCE(type_unite, ?) AS type_unite, COALESCE(masse_horaire, 0) AS masse_horaire FROM unites_de_formation WHERE id = ?";
$stmt_unite = $conn->prepare($sql_unite);
$default_type_unite = TYPE_UNITE_PEDAGOGIQUE;
$stmt_unite->bind_param("si", $default_type_unite, $unite_id);
$stmt_unite->execute();
$stmt_unite->bind_result($heures_officielles, $unite_nom, $unite_archived, $unite_semestre, $type_unite, $masse_horaire_unite);
if (!$stmt_unite->fetch()) {
    $stmt_unite->close();
    http_response_code(404);
    exit('Unité de formation introuvable.');
}
$stmt_unite->close();
$type_unite = unite_normalize_type($type_unite);
$is_stage_unite = $type_unite === TYPE_UNITE_STAGE;
if ($is_stage_unite) {
    $heures_officielles = (float) $masse_horaire_unite;
}

if ((int) $unite_archived === 1) {
    http_response_code(403);
    exit('Cette unité est archivée. Aucune nouvelle séance ne peut y être ajoutée.');
}

$annee_scolaire_id = annee_scolaire_selected_id(
    $conn,
    $_POST['annee_scolaire_id'] ?? $_GET['annee_scolaire_id'] ?? null
);

// Récupérer les séquences pédagogiques liées à cette unité de formation
$sql_sequences = "SELECT id, intitule FROM sequences_pedagogiques WHERE unite_id = ? AND COALESCE(is_archived, 0) = 0";
$stmt_sequences = $conn->prepare($sql_sequences);
$stmt_sequences->bind_param("i", $unite_id);
$stmt_sequences->execute();
$result_sequences = $stmt_sequences->get_result();

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $sequence_id = filter_input(INPUT_POST, 'sequence_id', FILTER_VALIDATE_INT);
    $date_seance = trim((string) ($_POST['date_seance'] ?? ''));
    $objectif_pedagogique = trim((string) ($_POST['objectif_pedagogique'] ?? ''));
    $description_activites = trim((string) ($_POST['description_activites'] ?? ''));
    $observations = trim((string) ($_POST['observations'] ?? ''));
    $dispositions_prochaine = trim((string) ($_POST['dispositions_prochaine'] ?? ''));
    $heures_reelles_input = trim((string) ($_POST['heures_reelles'] ?? ''));
    $heures_reelles = $heures_reelles_input === ''
        ? (float) $heures_officielles
        : filter_var($heures_reelles_input, FILTER_VALIDATE_FLOAT);
    $controle_continu = isset($_POST['controle_continu']) ? 1 : 0;

    $requiredText = [$date_seance, $objectif_pedagogique, $description_activites, $observations, $dispositions_prochaine];
    if (!$sequence_id || $heures_reelles === false || in_array('', $requiredText, true)) {
        $error = "Veuillez renseigner tous les champs obligatoires.";
    } elseif (!annee_scolaire_date_in_period($conn, $annee_scolaire_id, $date_seance)) {
        $error = "La date saisie n'appartient pas à l'année scolaire sélectionnée.";
    } elseif (!annee_scolaire_is_editable_for_current_user($conn, $annee_scolaire_id)) {
        $error = "Cette année scolaire est archivée. Aucune nouvelle séance ne peut y être ajoutée.";
    } else {
        $sequenceCheck = $conn->prepare(
            "SELECT COUNT(*) FROM sequences_pedagogiques WHERE id = ? AND unite_id = ? AND COALESCE(is_archived, 0) = 0"
        );
        $sequenceCheck->bind_param("ii", $sequence_id, $unite_id);
        $sequenceCheck->execute();
        $sequenceCheck->bind_result($sequenceCount);
        $sequenceCheck->fetch();
        $sequenceCheck->close();
        if ((int) $sequenceCount !== 1) {
            $error = "La séquence sélectionnée ne correspond pas à cette unité.";
        }
    }

    if (!isset($error)) {
        $conn->begin_transaction();
        try {
            // Insertion de la nouvelle séance dans la base de données
            $sql = "INSERT INTO seances_pedagogiques (sequence_id, annee_scolaire_id, date_seance, objectif_pedagogique, description_activites, observations_formateur, dispositions_prochaine, heures_officielles, heures_reelles, controle_continu)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("iisssssidi", $sequence_id, $annee_scolaire_id, $date_seance, $objectif_pedagogique, $description_activites, $observations, $dispositions_prochaine, $heures_officielles, $heures_reelles, $controle_continu);
            $stmt->execute();

            // Récupérer l'ID de la séance nouvellement ajoutée
            $seance_id = $stmt->insert_id;
            $stmt->close();

            // Calculer les heures cumulées (total des heures réalisées pour cette séquence)
            $sql_cumulees = "SELECT COALESCE(SUM(heures_reelles), 0) FROM seances_pedagogiques WHERE sequence_id = ? AND annee_scolaire_id = ?";
            $stmt_cumulees = $conn->prepare($sql_cumulees);
            $stmt_cumulees->bind_param("ii", $sequence_id, $annee_scolaire_id);
            $stmt_cumulees->execute();
            $stmt_cumulees->bind_result($heures_cumulees);
            $stmt_cumulees->fetch();
            $stmt_cumulees->close();

            // Calculer le taux de réalisation
            $taux_realisation = $heures_officielles > 0
                ? ($heures_cumulees / $heures_officielles) * 100
                : 0;

            $sql_suivi = "INSERT INTO suivi_pedagogique (seance_id, heures_cumulees, taux_realisation, valide_par_directeur, commentaire_directeur)
                          VALUES (?, ?, ?, 0, '')";
            $stmt_suivi = $conn->prepare($sql_suivi);
            $stmt_suivi->bind_param("idd", $seance_id, $heures_cumulees, $taux_realisation);
            $stmt_suivi->execute();
            $stmt_suivi->close();

            record_recommendation_usage(
                $conn,
                $seance_id,
                (int) $sequence_id,
                [
                    'objectif' => $objectif_pedagogique,
                    'description' => $description_activites,
                    'observation' => $observations,
                    'disposition' => $dispositions_prochaine,
                ],
                [
                    'objectif' => $_POST['recommendation_objectif_id'] ?? null,
                    'description' => $_POST['recommendation_description_id'] ?? null,
                    'observation' => $_POST['recommendation_observation_id'] ?? null,
                    'disposition' => $_POST['recommendation_disposition_id'] ?? null,
                ]
            );

            $conn->commit();
            if (ob_get_length()) {
                ob_clean();
            }
            header("Location: gestion_seances.php?unite_id=$unite_id&unite_nom=" . urlencode($unite_nom) . "&annee_scolaire_id=" . (int) $annee_scolaire_id);
            exit();
        } catch (Throwable $exception) {
            $conn->rollback();
            error_log('Echec ajout seance : ' . $exception->getMessage());
            $error = "Erreur lors de l'ajout de la séance.";
        }
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ajouter une Séance - EPIM Suivi Pédagogique</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <link rel="stylesheet" href="assets/css/theme.css">
</head>
<body>
    <div class="container-fluid mt-2 fade-in">
        <div class="page-header">
            <h2><i class="fas fa-plus-circle text-primary mr-2"></i>Ajouter une nouvelle séance pédagogique</h2>
            <p>Unité de formation : <strong><?php echo htmlspecialchars($unite_nom ?? '', ENT_QUOTES, 'UTF-8'); ?></strong></p>
        </div>

        <!-- Affichage d'un message d'erreur si nécessaire -->
        <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <div class="epim-card p-4">
        <!-- Formulaire d'ajout d'une nouvelle séance -->
        <form method="POST" action="ajouter_seance.php?unite_id=<?php echo $unite_id; ?>&unite_nom=<?php echo urlencode($unite_nom); ?>&annee_scolaire_id=<?php echo (int) $annee_scolaire_id; ?>">
            <input type="hidden" name="annee_scolaire_id" value="<?php echo (int) $annee_scolaire_id; ?>">
            <div class="form-group">
                <label for="sequence_id"><i class="fas fa-stream mr-1 text-primary"></i> Séquence pédagogique</label>
                <select class="form-control" id="sequence_id" name="sequence_id" required>
                    <option value="">-- Sélectionnez une séquence --</option>
                    <?php while ($row_sequences = $result_sequences->fetch_assoc()): ?>
                        <option value="<?php echo (int) $row_sequences['id']; ?>"><?php echo htmlspecialchars($row_sequences['intitule'], ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="date_seance"><i class="fas fa-calendar-alt mr-1 text-primary"></i> Date et horaire de la séance</label>
                <input type="datetime-local" class="form-control" id="date_seance" name="date_seance" required>
            </div>

            <hr class="my-4">

            <!-- OBJECTIF : sélection uniquement, aucune saisie libre -->
            <div class="form-group">
                <label for="objectif_picker"><i class="fas fa-bullseye mr-1 text-primary"></i> Objectif pédagogique de la séance</label>
                <select class="form-control recommendation-picker" id="objectif_picker" data-target="objectif_pedagogique" data-hidden="recommendation_objectif_id" disabled required>
                    <option value="">-- Sélectionnez d'abord une séquence --</option>
                </select>
                <span class="field-hint"><i class="fas fa-info-circle"></i> L'objectif est obligatoirement choisi dans la liste liée à la séquence sélectionnée (aucune saisie libre).</span>
                <input type="hidden" id="recommendation_objectif_id" name="recommendation_objectif_id">
                <input type="hidden" id="objectif_pedagogique" name="objectif_pedagogique" required>
            </div>

            <!-- DESCRIPTION : sélection parmi 5 à 10 propositions, modifiable manuellement -->
            <div class="form-group">
                <label for="description_picker"><i class="fas fa-align-left mr-1 text-primary"></i> Descriptif du déroulement de la séance</label>
                <select class="form-control recommendation-picker mb-2" id="description_picker" data-target="description_activites" data-hidden="recommendation_description_id" disabled>
                    <option value="">-- Sélectionnez d'abord un objectif --</option>
                </select>
                <span class="field-hint mb-2"><i class="fas fa-pen"></i> Vous pouvez modifier librement le texte ci-dessous après sélection.</span>
                <input type="hidden" id="recommendation_description_id" name="recommendation_description_id">
                <textarea class="form-control" id="description_activites" name="description_activites" rows="4" maxlength="10000" required placeholder="Sélectionnez d'abord un objectif pour afficher des propositions de description..."></textarea>
            </div>

            <!-- OBSERVATION : sélection parmi 5 à 10 propositions, modifiable manuellement -->
            <div class="form-group">
                <label for="observation_picker"><i class="fas fa-eye mr-1 text-primary"></i> Observations du formateur en fin de séance</label>
                <select class="form-control recommendation-picker mb-2" id="observation_picker" data-target="observations" data-hidden="recommendation_observation_id" disabled>
                    <option value="">-- Sélectionnez d'abord une description --</option>
                </select>
                <span class="field-hint mb-2"><i class="fas fa-pen"></i> Vous pouvez modifier librement le texte ci-dessous après sélection.</span>
                <input type="hidden" id="recommendation_observation_id" name="recommendation_observation_id">
                <textarea class="form-control" id="observations" name="observations" rows="3" maxlength="10000" required placeholder="Sélectionnez d'abord une description pour afficher des propositions..."></textarea>
            </div>

            <!-- DISPOSITION : sélection parmi 5 à 10 propositions, modifiable manuellement -->
            <div class="form-group">
                <label for="disposition_picker"><i class="fas fa-arrow-circle-right mr-1 text-primary"></i> Dispositions pour la prochaine séance</label>
                <select class="form-control recommendation-picker mb-2" id="disposition_picker" data-target="dispositions_prochaine" data-hidden="recommendation_disposition_id" disabled>
                    <option value="">-- Sélectionnez d'abord une observation --</option>
                </select>
                <span class="field-hint mb-2"><i class="fas fa-pen"></i> Vous pouvez modifier librement le texte ci-dessous après sélection.</span>
                <input type="hidden" id="recommendation_disposition_id" name="recommendation_disposition_id">
                <textarea class="form-control" id="dispositions_prochaine" name="dispositions_prochaine" rows="3" maxlength="10000" required placeholder="Sélectionnez d'abord une observation pour afficher des propositions..."></textarea>
            </div>

            <hr class="my-4">

            <div class="form-group">
                <div class="custom-control custom-checkbox">
                    <input type="checkbox" class="custom-control-input" id="controle_continu" name="controle_continu" value="1">
                    <label class="custom-control-label" for="controle_continu">
                        <i class="fas fa-clipboard-check mr-1 text-primary"></i> Contrôle continu réalisé pendant cette séance
                    </label>
                </div>
            </div>

            <hr class="my-4">

            <div class="form-group">
                <?php if ($is_stage_unite): ?>
                    <label for="heures_reelles"><i class="fas fa-clock mr-1 text-primary"></i> Masse horaire réalisée</label>
                    <span class="field-hint mb-2"><i class="fas fa-info-circle"></i> Valeur pré-remplie avec la masse horaire officielle de l'unité. Modifiez-la uniquement si le stage est réalisé partiellement.</span>
                    <input type="number" step="0.5" min="0" class="form-control" id="heures_reelles" name="heures_reelles" value="<?php echo htmlspecialchars((string) $masse_horaire_unite, ENT_QUOTES, 'UTF-8'); ?>" required>
                <?php else: ?>
                    <label for="heures_reelles"><i class="fas fa-clock mr-1 text-primary"></i> Nombre d'heures réalisées (choisir si différente de la période prévue)</label>
                    <span class="field-hint mb-2"><i class="fas fa-info-circle"></i> Laissez vide si les heures réalisées correspondent aux heures prévues.</span>
                    <select class="form-control" id="heures_reelles" name="heures_reelles">
                        <option value="">Utiliser les heures prévues (<?php echo htmlspecialchars((string) $heures_officielles, ENT_QUOTES, 'UTF-8'); ?> h)</option>
                        <option value="1">1 heure</option>
                        <option value="1.5">1h30</option>
                        <option value="2">2 heures</option>
                        <option value="2.5">2h30</option>
                        <option value="3">3 heures</option>
                        <option value="3.5">3h30</option>
                        <option value="4">4 heures</option>
                    </select>
                <?php endif; ?>
            </div>

            <button type="submit" class="btn btn-epim-primary mt-3"><i class="fas fa-save mr-1"></i> Ajouter la séance</button>
            <a href="gestion_seances.php?unite_id=<?php echo $unite_id; ?>&unite_nom=<?php echo urlencode($unite_nom); ?>&annee_scolaire_id=<?php echo (int) $annee_scolaire_id; ?>" class="btn btn-outline-epim mt-3">Annuler</a>
        </form>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const sequence = document.getElementById('sequence_id');
        const RECO_LIMIT = 8; // entre 5 et 10 propositions affichées

        // Configuration des niveaux de la chaîne de recommandation.
        // mode 'select'   : le champ caché reçoit exactement le texte de l'option choisie (aucune saisie libre)
        // mode 'editable' : le texte de l'option choisie pré-remplit un textarea modifiable
        const levels = {
            objectif:    { picker: 'objectif_picker',    endpoint: 'get_objectifs.php',    next: 'description', mode: 'select',   emptyMsg: 'Aucun objectif disponible pour cette séquence' },
            description: { picker: 'description_picker', endpoint: 'get_descriptions.php', next: 'observation', mode: 'editable', emptyMsg: 'Aucune description liée : saisie libre possible' },
            observation: { picker: 'observation_picker', endpoint: 'get_observations.php', next: 'disposition', mode: 'editable', emptyMsg: 'Aucune observation liée : saisie libre possible' },
            disposition: { picker: 'disposition_picker', endpoint: 'get_dispositions.php', next: null,          mode: 'editable', emptyMsg: 'Aucune disposition liée : saisie libre possible' }
        };
        levels.disposition.emptyMsg = 'Dispositions indisponibles : saisie libre possible';
        const order = ['objectif', 'description', 'observation', 'disposition'];

        function resetLevel(type, message, disable) {
            const config = levels[type];
            const picker = document.getElementById(config.picker);
            picker.innerHTML = '';
            picker.append(new Option(message, ''));
            picker.disabled = disable;
            document.getElementById(picker.dataset.hidden).value = '';

            const target = document.getElementById(picker.dataset.target);
            if (config.mode === 'editable') {
                target.value = '';
            } else {
                target.value = '';
            }
        }

        function resetFrom(type) {
            order.slice(order.indexOf(type)).forEach(function (t) {
                resetLevel(t, t === 'objectif'
                    ? '-- Sélectionnez d\'abord une séquence --'
                    : '-- Sélectionner d\'abord le niveau précédent --', true);
            });
        }

        async function loadRecommendations(type, parentId) {
            const config = levels[type];
            const picker = document.getElementById(config.picker);
            resetLevel(type, 'Chargement des propositions...', true);

            const params = new URLSearchParams({ sequence_id: sequence.value, limit: String(RECO_LIMIT) });
            if (parentId) {
                params.set('parent_id', parentId);
            }
            if (type === 'disposition') {
                const observationPicker = document.getElementById(levels.observation.picker);
                const observationOption = observationPicker.options[observationPicker.selectedIndex];
                if (observationOption) {
                    if (observationOption.dataset.level) {
                        params.set('observation_level', observationOption.dataset.level);
                    }
                    if (observationOption.dataset.family) {
                        params.set('observation_family', observationOption.dataset.family);
                    }
                    if (observationOption.dataset.subject) {
                        params.set('observation_subject', observationOption.dataset.subject);
                    }
                }
            }
            try {
                const response = await fetch(config.endpoint + '?' + params.toString(), {
                    headers: { 'Accept': 'application/json' }
                });
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                const payload = await response.json();
                picker.innerHTML = '';

                if (!payload.data.length) {
                    picker.append(new Option(config.emptyMsg, ''));
                    // En mode "select", si aucune proposition n'existe, on laisse le champ vide
                    // mais le formateur ne peut toujours pas saisir de texte libre pour objectif/observation/disposition.
                    picker.disabled = (config.mode === 'select');
                    return;
                }

                picker.append(new Option('-- Choisir une proposition (' + payload.data.length + ') --', ''));
                payload.data.forEach(function (item) {
                    const option = new Option(item.text, String(item.id));
                    option.dataset.text = item.text;
                    option.dataset.source = item.source;
                    if (item.family) {
                        option.dataset.family = item.family;
                    }
                    if (item.subject) {
                        option.dataset.subject = item.subject;
                    }
                    if (item.level) {
                        option.dataset.level = item.level;
                    }
                    picker.append(option);
                });
                picker.disabled = false;
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
                document.getElementById(picker.dataset.hidden).value = selectedId;
                const target = document.getElementById(picker.dataset.target);

                if (selectedId) {
                    target.value = option.dataset.text;
                } else {
                    target.value = '';
                }

                // Réinitialiser tous les niveaux suivants
                if (config.next) {
                    order.slice(order.indexOf(type) + 1).forEach(function (downstreamType) {
                        resetLevel(downstreamType, '-- Sélectionner d\'abord le niveau précédent --', true);
                    });
                    if (selectedId) {
                        loadRecommendations(config.next, selectedId);
                    }
                }
            });
        });

        sequence.addEventListener('change', function () {
            resetFrom('objectif');
            if (sequence.value) {
                loadRecommendations('objectif');
            }
        });

        if (sequence.value) {
            loadRecommendations('objectif');
        }
    });
    </script>
    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.9.2/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>
</html>
