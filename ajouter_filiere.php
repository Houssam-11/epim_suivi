<?php
// Inclure la session et vérifier si l'utilisateur est directeur
require_once __DIR__ . '/auth_check.php';
auth_require_role('directeur');
require_once __DIR__ . '/includes/filiere_helper.php';

// Vérifier le rôle du directeur
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'directeur') {
    header('Location: index.php');
    exit();
}

// Connexion à la base de données
require_once 'db.php';


$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("Erreur de connexion: " . $conn->connect_error);
}
$conn->set_charset("utf8");
filiere_ensure_columns($conn);


// Gestion de l'ajout d'une filière
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nom_filiere = $_POST['nom_filiere'];
    $niveau = $_POST['niveau'];
    $annee_formation = filiere_normalize_annee_formation($_POST['annee_formation'] ?? null);
    $secteur_id = null;

    if ($annee_formation <= 0) {
        die("La durée de la formation est obligatoire.");
    }

    // Vérifier si un nouveau secteur est ajouté
    if (!empty($_POST['nouveau_secteur'])) {
        $nouveau_secteur = $_POST['nouveau_secteur'];

        // Insérer le nouveau secteur dans la base de données
        $stmt = $conn->prepare("INSERT INTO secteurs (nom) VALUES (?)");
        $stmt->bind_param('s', $nouveau_secteur);
        $stmt->execute();
        $secteur_id = $conn->insert_id; // Récupérer l'ID du nouveau secteur
        $stmt->close();
    } else {
        $secteur_id = $_POST['secteur_id']; // Utiliser le secteur existant
    }

    // Insérer la nouvelle filière dans la base de données
    $stmt = $conn->prepare("INSERT INTO filieres (nom, niveau, annee_formation, secteur_id) VALUES (?, ?, ?, ?)");
    $stmt->bind_param('ssii', $nom_filiere, $niveau, $annee_formation, $secteur_id);
    $stmt->execute();
    $stmt->close();

    // Redirection après l'ajout
    header('Location: liste_filieres.php');
    exit();
}

// Récupérer les secteurs existants pour la liste déroulante
$result_secteurs = $conn->query("SELECT id, nom FROM secteurs");
$anneesFormation = filiere_annees_formation_options();
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Ajouter une Filière</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
</head>
<body>
    <div class="container mt-5">
        <h2>Ajouter une nouvelle filière</h2>
        <form method="POST">
            <div class="form-group">
                <label for="nom_filiere">Nom de la filière</label>
                <input type="text" class="form-control" id="nom_filiere" name="nom_filiere" required>
            </div>
            <div class="form-group">
                <label for="niveau">Niveau de la filière</label>
                <select class="form-control" id="niveau" name="niveau" required>
                    <option value="Technicien">Technicien</option>
                    <option value="Technicien Spécialisé">Technicien Spécialisé</option>
                </select>
            </div>
            <div class="form-group">
                <label for="annee_formation">Durée de la formation</label>
                <select class="form-control" id="annee_formation" name="annee_formation" required>
                    <option value="">Sélectionner...</option>
                    <?php foreach ($anneesFormation as $anneeFormationOption): ?>
                        <option value="<?php echo (int) $anneeFormationOption; ?>">
                            <?php echo htmlspecialchars(filiere_duree_formation_label($anneeFormationOption), ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="secteur_id">Sélectionner un secteur existant</label>
                <select class="form-control" id="secteur_id" name="secteur_id">
                    <option value="">-- Sélectionner un secteur --</option>
                    <?php while ($row = $result_secteurs->fetch_assoc()): ?>
                        <option value="<?php echo $row['id']; ?>"><?php echo $row['nom']; ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="nouveau_secteur">Ou ajouter un nouveau secteur</label>
                <input type="text" class="form-control" id="nouveau_secteur" name="nouveau_secteur" placeholder="Nouveau secteur">
            </div>
            <button type="submit" class="btn btn-primary">Ajouter la filière</button>
        </form>
    </div>
</body>
</html>

<?php
$conn->close();
?>
