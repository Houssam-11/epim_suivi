<?php
//Inclure la barre de navigation et le menu vertical
include 'page_directeur.php';
require_once __DIR__ . '/annees_scolaires.php';

$annees_scolaires = annee_scolaire_print_options($conn);
$annee_active_id = getCurrentWorkingAcademicYear($conn);
$annee_print_ids = array_map(static fn($annee) => (int) $annee['id'], $annees_scolaires);
if (!in_array($annee_active_id, $annee_print_ids, true)) {
    $annee_active_id = annee_scolaire_active_id($conn);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Formulaire de Fiche à Imprimer</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
</head>
<body>
    <div class="container mt-5">
        <h2>Impression de la Fiche de Suivi Pédagogique</h2>
        
        <!-- Formulaire de filtrage -->
        <form method="POST" action="imprimer_fiche_tcpdf.php">
            <!-- Sélectionner une filière -->
            <div class="form-group">
                <label for="filiere_id">Sélectionner la Filière :</label>
                <select class="form-control" id="filiere_id" name="filiere_id">
                    <option value="">Choisir une filière</option>
                    <!-- Les options seront ajoutées dynamiquement depuis la base de données -->
                </select>
            </div>

            <!-- Sélectionner une unité (chargée dynamiquement) -->
            <div class="form-group">
                <label for="unite_id">Sélectionner l'Unité :</label>
                <select class="form-control" id="unite_id" name="unite_id">
                    <option value="">Sélectionner une filière d'abord</option>
                    <!-- Les options seront ajoutées dynamiquement après sélection de la filière -->
                </select>
            </div>

            <!-- Bouton pour générer le PDF -->
            <div class="form-group">
                <label for="annee_scolaire_id">Année scolaire :</label>
                <select class="form-control" id="annee_scolaire_id" name="annee_scolaire_id" required>
                    <option value="">Choisir une année scolaire</option>
                    <?php foreach ($annees_scolaires as $annee): ?>
                        <option value="<?php echo (int) $annee['id']; ?>" <?php echo (int) $annee['id'] === $annee_active_id ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($annee['display_label'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit" class="btn btn-primary">Imprimer la fiche</button>
        </form>
    </div>

    <script type="text/javascript">
        $(document).ready(function() {
            // Charger les filières dès le chargement de la page
            $.ajax({
                type: 'POST',
                url: 'get_filieres.php',
                success: function(html) {
                    $('#filiere_id').html(html);
                }
            });

            // Charger les unités en fonction de la filière sélectionnée
            $('#filiere_id').on('change', function() {
                var filiereId = $(this).val();
                if (filiereId) {
                    $.ajax({
                        type: 'POST',
                        url: 'get_unites.php',
                        data: {filiere_id: filiereId},
                        success: function(html) {
                            $('#unite_id').html(html);
                        }
                    });
                } else {
                    $('#unite_id').html('<option value="">Sélectionner une filière d\'abord</option>');
                }
            });
        });
    </script>
</body>
</html>
