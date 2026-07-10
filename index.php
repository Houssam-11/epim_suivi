<?php
require_once __DIR__ . '/auth_check.php';
auth_start_session();
ob_start();

include 'db.php';

$redirect_url = auth_safe_redirect(isset($_GET['redirect']) ? urldecode($_GET['redirect']) : null, '');
$expired_message = isset($_GET['expired']) ? "Votre session a expiré. Veuillez vous reconnecter." : '';
$error = null;

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    $stmt = $conn->prepare("SELECT id, nom, role, mot_de_passe FROM utilisateurs WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        $stmt->bind_result($id, $nom, $role, $hashed_password);
        $stmt->fetch();

        if (password_verify($password, $hashed_password)) {
            session_regenerate_id(true);
            $_SESSION['id'] = $id;
            $_SESSION['nom'] = $nom;
            $_SESSION['role'] = $role;
            $_SESSION['last_activity'] = time();
            auth_log('login_success', 'email=' . $email);

            if ($redirect_url !== '') {
                header("Location: " . $redirect_url);
                exit();
            }

            if ($role === 'formateur') {
                header("Location: tableau_bord_formateur.php");
                exit();
            }
            if ($role === 'directeur') {
                header("Location: tableau_bord_directeur.php");
                exit();
            }

            auth_log('access_denied', 'role_inconnu=' . $role);
            $error = "Rôle utilisateur non autorisé.";
        } else {
            auth_log('login_failed', 'email=' . $email);
            $error = "Mot de passe incorrect.";
        }
    } else {
        auth_log('login_failed', 'email=' . $email);
        $error = "Aucun compte trouvé avec cet email.";
    }

    $stmt->close();
}

$conn->close();
ob_end_flush();
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion - EPIM Suivi Pédagogique</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <link rel="stylesheet" href="assets/css/theme.css">
</head>
<body class="login-page">
    <div class="login-card">
        <img src="assets/img/logo.png" alt="EPIM - Ecole Professionnelle d'Informatique et de Management" class="login-logo">
        <h2>Suivi Pédagogique</h2>
        <p class="login-subtitle">Connectez-vous pour accéder à votre espace</p>

        <form action="index.php<?php echo $redirect_url ? '?redirect=' . urlencode($redirect_url) : ''; ?>" method="POST">
            <?php if ($expired_message !== ''): ?>
                <div class="alert alert-warning">
                    <?php echo htmlspecialchars($expired_message, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>

            <div class="form-group">
                <label for="email"><i class="fas fa-envelope mr-1 text-muted"></i> Email</label>
                <input type="email" class="form-control" id="email" name="email" placeholder="vous@epim.ma" required>
            </div>

            <div class="form-group">
                <label for="password"><i class="fas fa-lock mr-1 text-muted"></i> Mot de passe</label>
                <input type="password" class="form-control" id="password" name="password" placeholder="••••••••" required>
            </div>

            <button type="submit" class="btn btn-epim-primary mt-2">
                <i class="fas fa-sign-in-alt mr-1"></i> Se connecter
            </button>
        </form>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.9.2/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>
</html>
