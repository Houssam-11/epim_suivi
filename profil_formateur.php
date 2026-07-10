<?php
include 'page_formateur.php';

$formateur_id = (int) $_SESSION['id'];
$success = '';
$error   = '';

// Charger les informations actuelles du formateur
$stmt_load = $conn->prepare("SELECT nom, email FROM utilisateurs WHERE id = ? AND role = 'formateur'");
$stmt_load->bind_param("i", $formateur_id);
$stmt_load->execute();
$stmt_load->bind_result($nom_current, $email_current);
$stmt_load->fetch();
$stmt_load->close();

// Traitement du formulaire de mise à jour
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_info') {
        $nouveau_nom   = trim($_POST['nom'] ?? '');
        $nouvel_email  = trim($_POST['email'] ?? '');

        if ($nouveau_nom === '' || $nouvel_email === '') {
            $error = "Le nom et l'email sont obligatoires.";
        } elseif (!filter_var($nouvel_email, FILTER_VALIDATE_EMAIL)) {
            $error = "L'adresse email n'est pas valide.";
        } else {
            // Vérifier si l'email est déjà utilisé par quelqu'un d'autre
            $stmt_check = $conn->prepare("SELECT id FROM utilisateurs WHERE email = ? AND id != ?");
            $stmt_check->bind_param("si", $nouvel_email, $formateur_id);
            $stmt_check->execute();
            $stmt_check->store_result();
            if ($stmt_check->num_rows > 0) {
                $error = "Cette adresse email est déjà utilisée par un autre compte.";
            } else {
                $stmt_update = $conn->prepare("UPDATE utilisateurs SET nom = ?, email = ? WHERE id = ?");
                $stmt_update->bind_param("ssi", $nouveau_nom, $nouvel_email, $formateur_id);
                if ($stmt_update->execute()) {
                    $_SESSION['nom'] = $nouveau_nom;
                    $nom_current     = $nouveau_nom;
                    $email_current   = $nouvel_email;
                    $success = "Vos informations ont été mises à jour avec succès.";
                } else {
                    $error = "Erreur lors de la mise à jour. Veuillez réessayer.";
                }
                $stmt_update->close();
            }
            $stmt_check->close();
        }

    } elseif ($action === 'change_password') {
        $ancien_mdp    = $_POST['ancien_mdp'] ?? '';
        $nouveau_mdp   = $_POST['nouveau_mdp'] ?? '';
        $confirme_mdp  = $_POST['confirme_mdp'] ?? '';

        if ($ancien_mdp === '' || $nouveau_mdp === '' || $confirme_mdp === '') {
            $error = "Tous les champs du mot de passe sont obligatoires.";
        } elseif ($nouveau_mdp !== $confirme_mdp) {
            $error = "Le nouveau mot de passe et sa confirmation ne correspondent pas.";
        } elseif (strlen($nouveau_mdp) < 6) {
            $error = "Le nouveau mot de passe doit contenir au moins 6 caractères.";
        } else {
            // Vérifier l'ancien mot de passe
            $stmt_mdp = $conn->prepare("SELECT mot_de_passe FROM utilisateurs WHERE id = ?");
            $stmt_mdp->bind_param("i", $formateur_id);
            $stmt_mdp->execute();
            $stmt_mdp->bind_result($hashed);
            $stmt_mdp->fetch();
            $stmt_mdp->close();

            if (!password_verify($ancien_mdp, $hashed)) {
                $error = "L'ancien mot de passe est incorrect.";
            } else {
                $nouveau_hash = password_hash($nouveau_mdp, PASSWORD_DEFAULT);
                $stmt_newmdp  = $conn->prepare("UPDATE utilisateurs SET mot_de_passe = ? WHERE id = ?");
                $stmt_newmdp->bind_param("si", $nouveau_hash, $formateur_id);
                if ($stmt_newmdp->execute()) {
                    $success = "Votre mot de passe a été modifié avec succès.";
                } else {
                    $error = "Erreur lors du changement de mot de passe. Veuillez réessayer.";
                }
                $stmt_newmdp->close();
            }
        }
    }
}

// Récupérer les statistiques du formateur
$stmt_stats = $conn->prepare(
    "SELECT COUNT(DISTINCT uf.id)         AS nb_unites,
            COUNT(DISTINCT sp.id)          AS nb_seances,
            COALESCE(SUM(sp.heures_reelles), 0) AS total_heures
     FROM unites_de_formation uf
     LEFT JOIN sequences_pedagogiques seq ON seq.unite_id = uf.id
     LEFT JOIN seances_pedagogiques sp    ON sp.sequence_id = seq.id
     WHERE uf.formateur_id = ?"
);
$stmt_stats->bind_param("i", $formateur_id);
$stmt_stats->execute();
$stats = $stmt_stats->get_result()->fetch_assoc();
$stmt_stats->close();

// Initiales pour avatar
$initiales = '';
foreach (preg_split('/\s+/', trim($nom_current ?? '')) as $part) {
    if ($part !== '') $initiales .= mb_substr($part, 0, 1);
}
$initiales = mb_strtoupper(mb_substr($initiales, 0, 2));
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mon Profil - EPIM</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <link rel="stylesheet" href="assets/css/theme.css">
    <style>
        .avatar-lg {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--epim-blue), #1064c4);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Poppins', sans-serif;
            font-size: 2rem;
            font-weight: 700;
            margin: 0 auto 14px;
            box-shadow: 0 6px 20px rgba(0,75,156,0.25);
        }
        .nav-tabs .nav-link {
            color: var(--epim-text-muted);
            font-weight: 600;
            border-radius: var(--epim-radius-sm) var(--epim-radius-sm) 0 0;
        }
        .nav-tabs .nav-link.active {
            color: var(--epim-blue);
            border-bottom-color: transparent;
        }
        .tab-content {
            border: 1px solid #dee2e6;
            border-top: none;
            border-radius: 0 0 var(--epim-radius) var(--epim-radius);
            padding: 28px;
            background: #fff;
        }
    </style>
</head>
<body>
<div class="container-fluid fade-in">
    <div class="page-header">
        <h2><i class="fas fa-user-circle text-primary mr-2"></i>Mon Profil</h2>
        <p>Consultez et mettez à jour vos informations personnelles.</p>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle mr-1"></i><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle mr-1"></i><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <div class="row">
        <!-- Colonne gauche : carte identité -->
        <div class="col-md-4 mb-4">
            <div class="epim-card p-4 text-center">
                <div class="avatar-lg"><?php echo htmlspecialchars($initiales ?: 'F', ENT_QUOTES, 'UTF-8'); ?></div>
                <h4 class="mb-1"><?php echo htmlspecialchars($nom_current ?? '', ENT_QUOTES, 'UTF-8'); ?></h4>
                <p class="text-muted mb-3"><i class="fas fa-chalkboard-teacher mr-1"></i>Formateur</p>
                <p class="text-muted small mb-4"><i class="fas fa-envelope mr-1"></i><?php echo htmlspecialchars($email_current ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
                <hr>
                <div class="row text-center mt-3">
                    <div class="col-4">
                        <div style="font-size:1.5rem; font-weight:700; color:var(--epim-blue);"><?php echo (int)$stats['nb_unites']; ?></div>
                        <div class="text-muted" style="font-size:.78rem;">Unités</div>
                    </div>
                    <div class="col-4">
                        <div style="font-size:1.5rem; font-weight:700; color:var(--epim-blue);"><?php echo (int)$stats['nb_seances']; ?></div>
                        <div class="text-muted" style="font-size:.78rem;">Séances</div>
                    </div>
                    <div class="col-4">
                        <div style="font-size:1.5rem; font-weight:700; color:var(--epim-orange);"><?php echo (int)$stats['total_heures']; ?>h</div>
                        <div class="text-muted" style="font-size:.78rem;">Heures</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Colonne droite : formulaires onglets -->
        <div class="col-md-8 mb-4">
            <ul class="nav nav-tabs" id="profileTabs" role="tablist">
                <li class="nav-item">
                    <a class="nav-link active" id="info-tab" data-toggle="tab" href="#info" role="tab">
                        <i class="fas fa-user mr-1"></i>Mes informations
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="password-tab" data-toggle="tab" href="#password" role="tab">
                        <i class="fas fa-lock mr-1"></i>Changer le mot de passe
                    </a>
                </li>
            </ul>
            <div class="tab-content" id="profileTabsContent">
                <!-- Onglet : informations -->
                <div class="tab-pane fade show active" id="info" role="tabpanel">
                    <form method="POST" action="profil_formateur.php">
                        <input type="hidden" name="action" value="update_info">
                        <div class="form-group">
                            <label for="nom"><i class="fas fa-user mr-1 text-primary"></i> Nom complet</label>
                            <input type="text" class="form-control" id="nom" name="nom"
                                   value="<?php echo htmlspecialchars($nom_current ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="email"><i class="fas fa-envelope mr-1 text-primary"></i> Adresse email</label>
                            <input type="email" class="form-control" id="email" name="email"
                                   value="<?php echo htmlspecialchars($email_current ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>
                        <button type="submit" class="btn btn-epim-primary">
                            <i class="fas fa-save mr-1"></i> Enregistrer les modifications
                        </button>
                    </form>
                </div>

                <!-- Onglet : mot de passe -->
                <div class="tab-pane fade" id="password" role="tabpanel">
                    <form method="POST" action="profil_formateur.php">
                        <input type="hidden" name="action" value="change_password">
                        <div class="form-group">
                            <label for="ancien_mdp"><i class="fas fa-lock mr-1 text-primary"></i> Ancien mot de passe</label>
                            <input type="password" class="form-control" id="ancien_mdp" name="ancien_mdp" required>
                        </div>
                        <div class="form-group">
                            <label for="nouveau_mdp"><i class="fas fa-key mr-1 text-primary"></i> Nouveau mot de passe</label>
                            <input type="password" class="form-control" id="nouveau_mdp" name="nouveau_mdp"
                                   minlength="6" required>
                            <small class="field-hint">Au moins 6 caractères.</small>
                        </div>
                        <div class="form-group">
                            <label for="confirme_mdp"><i class="fas fa-check-circle mr-1 text-primary"></i> Confirmer le nouveau mot de passe</label>
                            <input type="password" class="form-control" id="confirme_mdp" name="confirme_mdp"
                                   minlength="6" required>
                        </div>
                        <button type="submit" class="btn btn-epim-primary">
                            <i class="fas fa-save mr-1"></i> Changer le mot de passe
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
