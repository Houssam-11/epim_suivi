<?php
require_once __DIR__ . '/auth_check.php';
auth_require_role('directeur');

// Vérification du rôle (seulement un administrateur ou directeur peut ajouter des utilisateurs)
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'directeur') {
    header("Location: index.php");
    exit();
}

// Connexion à la base de données
require_once 'db.php';
 

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("Erreur de connexion: " . $conn->connect_error);
}
$conn->set_charset("utf8");

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $nom = $_POST['nom'];
    $email = $_POST['email'];
    $mot_de_passe = $_POST['mot_de_passe'];
    $role = $_POST['role'];

    // Vérifier si l'email existe déjà
    $stmt = $conn->prepare("SELECT id FROM utilisateurs WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        // L'email est déjà utilisé
        $error = "Cet email est déjà utilisé.";
    } else {
        // Hashage du mot de passe
        $mot_de_passe_hash = password_hash($mot_de_passe, PASSWORD_BCRYPT);

        // Insertion de l'utilisateur dans la base de données
        $stmt = $conn->prepare("INSERT INTO utilisateurs (nom, email, mot_de_passe, role) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $nom, $email, $mot_de_passe_hash, $role);

        if ($stmt->execute()) {
            header("Location: gestion_utilisateur.php");  // Redirection après succès
            exit();
        } else {
            $error = "Erreur lors de l'ajout de l'utilisateur.";
        }
    }

    $stmt->close();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ajouter un Utilisateur</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
</head>
<body>
    <div class="container mt-5">
        <h2>Ajouter un nouvel utilisateur</h2>

        <!-- Affichage d'un message d'erreur si nécessaire -->
        <?php if (isset($error)): ?>
            <div class="alert alert-danger">
                <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <!-- Formulaire d'ajout d'utilisateur -->
        <form method="POST" action="ajouter_utilisateur.php">
            <div class="form-group">
                <label for="nom">Nom complet :</label>
                <input type="text" class="form-control" id="nom" name="nom" required>
            </div>

            <div class="form-group">
                <label for="email">Email :</label>
                <input type="email" class="form-control" id="email" name="email" required>
            </div>

            <div class="form-group">
                <label for="mot_de_passe">Mot de passe :</label>
                <input type="password" class="form-control" id="mot_de_passe" name="mot_de_passe" required>
            </div>

            <div class="form-group">
                <label for="role">Rôle :</label>
                <select class="form-control" id="role" name="role" required>
                    <option value="Formateur">Formateur</option>
                    <option value="directeur">directeur</option>
                </select>
            </div>

            <button type="submit" class="btn btn-primary">Ajouter l'utilisateur</button>
        </form>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.9.2/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>
</html>
