<?php
include 'page_directeur.php';

$notification = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'ajouter') {
    $nom = $_POST['nom'];
    $email = $_POST['email'];
    $mot_de_passe = password_hash($_POST['mot_de_passe'], PASSWORD_DEFAULT);

    $sql_verification = "SELECT id FROM utilisateurs WHERE email = ?";
    $stmt_verification = $conn->prepare($sql_verification);
    $stmt_verification->bind_param("s", $email);
    $stmt_verification->execute();
    $stmt_verification->store_result();

    if ($stmt_verification->num_rows > 0) {
        $notification = 'error_email_exists';
    } else {
        $sql_utilisateur = "INSERT INTO utilisateurs (nom, email, mot_de_passe, role) VALUES (?, ?, ?, 'directeur')";
        $stmt_utilisateur = $conn->prepare($sql_utilisateur);
        $stmt_utilisateur->bind_param("sss", $nom, $email, $mot_de_passe);
        $notification = $stmt_utilisateur->execute() ? 'success' : 'error_ajout';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_password') {
    $utilisateur_id = (int) $_POST['utilisateur_id'];
    $nouveau_mot_de_passe = password_hash('12345678', PASSWORD_DEFAULT);

    $sql_reset_password = "UPDATE utilisateurs SET mot_de_passe = ? WHERE id = ?";
    $stmt_reset_password = $conn->prepare($sql_reset_password);
    $stmt_reset_password->bind_param("si", $nouveau_mot_de_passe, $utilisateur_id);
    $notification = $stmt_reset_password->execute() ? 'password_reset_success' : 'password_reset_error';
}

$sql_directeurs = "SELECT id, nom, email FROM utilisateurs WHERE role = 'directeur' ORDER BY nom";
$result_directeurs = $conn->query($sql_directeurs);

$sql_formateurs = "SELECT f.id AS formateur_id, f.nom, u.email, u.id AS utilisateur_id
                   FROM formateurs f
                   JOIN utilisateurs u ON f.utilisateur_id = u.id
                   ORDER BY f.nom";
$result_formateurs = $conn->query($sql_formateurs);
?>

<div class="container-fluid fade-in">
    <div class="page-header">
        <h1 class="page-title">Gestion des utilisateurs</h1>
        <p class="page-subtitle">Administrez les comptes directeur et les accès formateur.</p>
    </div>

    <div class="epim-card no-hover p-3 mb-4">
        <div class="section-header px-1">
            <div>
                <h2>Directeurs</h2>
                <p>Comptes ayant accès à l'espace de pilotage.</p>
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
                <tbody>
                    <?php while ($row = $result_directeurs->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['nom'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($row['email'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="text-center">
                                <form action="" method="POST" class="d-inline">
                                    <input type="hidden" name="action" value="reset_password">
                                    <input type="hidden" name="utilisateur_id" value="<?php echo (int) $row['id']; ?>">
                                    <button type="submit" class="btn btn-epim-accent btn-sm">
                                        <i class="fas fa-key mr-1"></i>Réinitialiser
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="epim-card no-hover p-3 mb-4">
        <div class="section-header px-1">
            <div>
                <h2>Formateurs</h2>
                <p>Comptes liés aux formateurs enregistrés.</p>
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
                <tbody>
                    <?php while ($row = $result_formateurs->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['nom'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($row['email'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="text-center">
                                <form action="" method="POST" class="d-inline">
                                    <input type="hidden" name="action" value="reset_password">
                                    <input type="hidden" name="utilisateur_id" value="<?php echo (int) $row['utilisateur_id']; ?>">
                                    <button type="submit" class="btn btn-epim-accent btn-sm">
                                        <i class="fas fa-key mr-1"></i>Réinitialiser
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="epim-card no-hover p-4">
        <div class="section-header">
            <div>
                <h2>Ajouter un directeur</h2>
                <p>Créez un nouveau compte directeur.</p>
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
                <i class="fas fa-plus mr-1"></i>Ajouter un directeur
            </button>
        </form>
    </div>
</div>

<script>
$(function() {
    <?php if ($notification === 'success'): ?>
        toastr.success('Directeur ajouté avec succès.');
    <?php elseif ($notification === 'error_email_exists'): ?>
        toastr.error('Cet email est déjà utilisé.');
    <?php elseif ($notification === 'error_ajout'): ?>
        toastr.error('Erreur lors de l\'ajout du directeur.');
    <?php elseif ($notification === 'password_reset_success'): ?>
        toastr.success('Mot de passe réinitialisé avec succès. Nouveau mot de passe: 12345678.');
    <?php elseif ($notification === 'password_reset_error'): ?>
        toastr.error('Erreur lors de la réinitialisation du mot de passe.');
    <?php endif; ?>
});
</script>

<?php include 'footer.php'; ?>
