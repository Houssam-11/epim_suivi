<?php
include 'page_directeur.php';

$notification = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'ajouter') {
    $nom = $_POST['nom'];
    $email = $_POST['email'];
    $mot_de_passe = password_hash($_POST['mot_de_passe'], PASSWORD_DEFAULT);

    $sql_utilisateur = "INSERT INTO utilisateurs (nom, email, mot_de_passe, role) VALUES (?, ?, ?, 'formateur')";
    $stmt_utilisateur = $conn->prepare($sql_utilisateur);
    $stmt_utilisateur->bind_param("sss", $nom, $email, $mot_de_passe);

    if ($stmt_utilisateur->execute()) {
        $utilisateur_id = $stmt_utilisateur->insert_id;

        $sql_formateur = "INSERT INTO formateurs (utilisateur_id, nom, email) VALUES (?, ?, ?)";
        $stmt_formateur = $conn->prepare($sql_formateur);
        $stmt_formateur->bind_param("iss", $utilisateur_id, $nom, $email);

        $notification = $stmt_formateur->execute() ? 'success' : 'error_formateur';
    } else {
        $notification = 'error_utilisateur';
    }
}

$sql = "SELECT f.id, f.nom, f.email FROM formateurs f JOIN utilisateurs u ON f.utilisateur_id = u.id ORDER BY f.nom";
$result = $conn->query($sql);
?>
<div class="container-fluid fade-in">
    <div class="page-header">
        <h1 class="page-title">Gestion des formateurs</h1>
        <p class="page-subtitle">Ajoutez, modifiez et supprimez les comptes formateurs.</p>
    </div>

    <div class="epim-card no-hover p-4 mb-4">
        <div class="section-header">
            <div>
                <h2>Ajouter un formateur</h2>
                <p>Un compte utilisateur formateur sera créé automatiquement.</p>
            </div>
        </div>
        <form action="" method="POST">
            <input type="hidden" name="action" value="ajouter">
            <div class="form-row">
                <div class="form-group col-lg-4">
                    <label for="nom">Nom</label>
                    <input type="text" class="form-control" id="nom" name="nom" required>
                </div>
                <div class="form-group col-lg-4">
                    <label for="email">Email</label>
                    <input type="email" class="form-control" id="email" name="email" required>
                </div>
                <div class="form-group col-lg-4">
                    <label for="mot_de_passe">Mot de passe</label>
                    <input type="password" class="form-control" id="mot_de_passe" name="mot_de_passe" required>
                </div>
            </div>
            <button type="submit" class="btn btn-epim-primary">
                <i class="fas fa-plus mr-1"></i>Ajouter un formateur
            </button>
        </form>
    </div>

    <div class="epim-card no-hover p-3">
        <div class="section-header px-1">
            <div>
                <h2>Liste des formateurs</h2>
                <p>Les champs nom et email sont modifiables directement dans le tableau.</p>
            </div>
        </div>
        <div class="table-responsive epim-data-table">
            <table class="table epim-table table-borderless mb-0">
                <thead>
                    <tr>
                        <th>Nom</th>
                        <th>Email</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody id="formateurs_liste">
                    <?php if ($result && $result->num_rows > 0): ?>
                        <?php while ($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td contenteditable="true" class="editable editable-cell" data-id="<?php echo (int) $row['id']; ?>" data-column="nom"><?php echo htmlspecialchars($row['nom'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td contenteditable="true" class="editable editable-cell" data-id="<?php echo (int) $row['id']; ?>" data-column="email"><?php echo htmlspecialchars($row['email'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="text-center">
                                    <button type="button" class="btn btn-outline-danger-epim btn-sm delete-formateur" data-id="<?php echo (int) $row['id']; ?>">
                                        <i class="fas fa-trash mr-1"></i>Supprimer
                                    </button>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="3" class="text-center text-muted py-4">Aucun formateur enregistré.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
$(function() {
    <?php if ($notification === 'success'): ?>
        toastr.success('Formateur ajouté avec succès.');
    <?php elseif ($notification === 'error_utilisateur'): ?>
        toastr.error('Erreur lors de l\'ajout de l\'utilisateur.');
    <?php elseif ($notification === 'error_formateur'): ?>
        toastr.error('Erreur lors de l\'ajout du formateur.');
    <?php endif; ?>

    $('.editable').on('blur', function() {
        var id = $(this).data('id');
        var column = $(this).data('column');
        var value = $(this).text();

        $.ajax({
            url: 'modifier_formateur.php',
            method: 'POST',
            data: { id: id, column: column, value: value },
            dataType: 'json',
            success: function(response) {
                response.success ? toastr.success('Modification enregistrée.') : toastr.error('Erreur lors de la modification.');
            },
            error: function() {
                toastr.error('Erreur lors de la communication avec le serveur.');
            }
        });
    });

    $('.delete-formateur').on('click', function() {
        if (!confirm('Voulez-vous vraiment supprimer ce formateur ?')) {
            return;
        }

        var id = $(this).data('id');
        $.ajax({
            url: 'supprimer_formateur.php',
            method: 'POST',
            data: { id: id },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    toastr.success('Formateur supprimé avec succès.');
                    location.reload();
                } else {
                    toastr.error('Erreur lors de la suppression.');
                }
            },
            error: function() {
                toastr.error('Erreur lors de la communication avec le serveur.');
            }
        });
    });
});
</script>

<?php include 'footer.php'; ?>
