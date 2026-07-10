<?php
require_once __DIR__ . '/auth_check.php';
auth_require_login();
// Vérifier si la session est démarrée
// Inclure le fichier de connexion à la base de données
include 'db.php';

// Vérifier si l'utilisateur est connecté
/* if (!isset($_SESSION['nom'])) {
    // Rediriger vers la page de connexion avec l'URL actuelle
    header('Location: index.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit();
} */

// Récupérer le rôle et le nom de l'utilisateur
$role = isset($_SESSION['role']) ? $_SESSION['role'] : '';
$nom_utilisateur = isset($_SESSION['nom']) ? $_SESSION['nom'] : 'Utilisateur';
$current_page = basename($_SERVER['PHP_SELF']);

// Initiales pour l'avatar
$initiales = '';
foreach (preg_split('/\s+/', trim($nom_utilisateur)) as $part) {
    if ($part !== '') {
        $initiales .= mb_substr($part, 0, 1);
    }
}
$initiales = mb_strtoupper(mb_substr($initiales, 0, 2));

?>

<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tableau de Bord - EPIM Suivi Pédagogique</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css">
    <link rel="stylesheet" href="assets/css/theme.css">
    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>
    <style>
        .sidebar-toggle-area {
            display: flex;
            align-items: center;
            justify-content: flex-start;
            padding: 10px 14px 12px;
            border-bottom: 1px solid #edf1f6;
            margin-bottom: 8px;
        }

        .epim-sidebar.collapsed .sidebar-toggle-area {
            justify-content: flex-start;
            padding-left: 14px;
            padding-right: 8px;
        }

        .label-check {
            display: none;
        }

        .hamburger-label {
            width: 38px;
            height: 34px;
            display: block;
            cursor: pointer;
            position: relative;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--epim-blue), #1064c4);
            box-shadow: 0 6px 14px rgba(0, 75, 156, 0.16);
            transition: transform .2s ease, box-shadow .2s ease;
            margin: 0;
        }

        .hamburger-label:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 18px rgba(0, 75, 156, 0.22);
        }

        .hamburger-label div {
            width: 22px;
            height: 3px;
            background-color: #fff;
            position: absolute;
            left: 8px;
            border-radius: 50px;
            transform-origin: right center;
        }

        .hamburger-label .line1 {
            top: 9px;
            transition: all .3s ease;
        }

        .hamburger-label .line2 {
            top: 16px;
            transition: all .3s ease;
        }

        .hamburger-label .line3 {
            top: 23px;
            transition: all .3s ease;
        }

        #label-check:checked + .hamburger-label .line1 {
            transform: rotate(35deg) scaleX(.55) translate(39px, -4.5px);
            border-radius: 50px 50px 50px 0;
        }

        #label-check:checked + .hamburger-label .line3 {
            transform: rotate(-35deg) scaleX(.55) translate(39px, 4.5px);
            border-radius: 0 50px 50px 50px;
        }

        #label-check:checked + .hamburger-label .line2 {
            border-top-right-radius: 50px;
            border-bottom-right-radius: 50px;
            width: 45px;
        }
    </style>
</head>

<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark fixed-top epim-navbar">
        <a class="navbar-brand" href="<?php echo $role === 'directeur' ? 'tableau_bord_directeur.php' : 'tableau_bord_formateur.php'; ?>">
            <img src="assets/img/logo.png" alt="EPIM">
            <span class="d-none d-md-inline">Suivi Pédagogique</span>
        </a>
        <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ml-auto align-items-center">
                <li class="nav-item d-flex align-items-center mr-3">
                    <span class="user-badge mr-2"><?php echo htmlspecialchars($initiales ?: 'U', ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="navbar-text">Bienvenue, <strong><?php echo htmlspecialchars($nom_utilisateur, ENT_QUOTES, 'UTF-8'); ?></strong></span>
                </li>
                <li class="nav-item">
                    <a class="btn-logout" href="logout.php"><i class="fas fa-sign-out-alt mr-1"></i> Déconnexion</a>
                </li>
            </ul>
        </div>
    </nav>

    <!-- Sidebar -->
    <div id="sidebar" class="sidebar epim-sidebar">
        <div class="sidebar-toggle-area">
            <input class="label-check" id="sidebar-collapse-check" type="checkbox" aria-label="Réduire ou développer la barre latérale">
            <label for="sidebar-collapse-check" class="hamburger-label" title="Réduire/développer la barre latérale">
                <div class="line1"></div>
                <div class="line2"></div>
                <div class="line3"></div>
            </label>
        </div>

        <?php if ($role == 'directeur'): ?>
            <div class="sidebar-section-title">Pilotage</div>
            <a href="tableau_bord_directeur.php" class="sidebar-link <?php echo $current_page === 'tableau_bord_directeur.php' ? 'active' : ''; ?>"><i class="fas fa-tachometer-alt"></i> <span class="menu-text">Tableau de Bord</span></a>
            <a href="liste_filieres.php" class="sidebar-link <?php echo $current_page === 'liste_filieres.php' ? 'active' : ''; ?>"><i class="fas fa-chalkboard-teacher"></i> <span class="menu-text">Gestion des Filières</span></a>
            <a href="validation_seances.php" class="sidebar-link <?php echo $current_page === 'validation_seances.php' ? 'active' : ''; ?>"><i class="fas fa-calendar-check"></i> <span class="menu-text">Gestion des Séances</span></a>
            <a href="formulaire_impression.php" class="sidebar-link <?php echo $current_page === 'formulaire_impression.php' ? 'active' : ''; ?>"><i class="fas fa-print"></i> <span class="menu-text">Impression des Fiches</span></a>
            <div class="sidebar-section-title">Administration</div>
            <a href="gestion_formateurs.php" class="sidebar-link <?php echo $current_page === 'gestion_formateurs.php' ? 'active' : ''; ?>"><i class="fas fa-users"></i> <span class="menu-text">Gestion des Formateurs</span></a>
            <a href="gestion_utilisateurs.php" class="sidebar-link <?php echo $current_page === 'gestion_utilisateurs.php' ? 'active' : ''; ?>"><i class="fas fa-user-cog"></i> <span class="menu-text">Gestion des Utilisateurs</span></a>
            <a href="edition_etats.php" class="sidebar-link <?php echo $current_page === 'edition_etats.php' ? 'active' : ''; ?>"><i class="fas fa-file-alt"></i> <span class="menu-text">Édition des États</span></a>
            <a href="configuration_dates.php" class="sidebar-link <?php echo $current_page === 'configuration_dates.php' ? 'active' : ''; ?>"><i class="fas fa-calendar-alt"></i> <span class="menu-text">Configuration globale des dates</span></a>
        <?php endif; ?>

        <?php if ($role == 'formateur'): ?>
            <div class="sidebar-section-title">Espace Formateur</div>
            <a href="tableau_bord_formateur.php" class="sidebar-link <?php echo $current_page === 'tableau_bord_formateur.php' ? 'active' : ''; ?>"><i class="fas fa-home"></i> <span class="menu-text">Tableau de Bord</span></a>
            <a href="gestion_seances.php" class="sidebar-link <?php echo $current_page === 'gestion_seances.php' ? 'active' : ''; ?>"><i class="fas fa-calendar-alt"></i> <span class="menu-text">Gérer les Séances</span></a>
            <a href="profil_formateur.php" class="sidebar-link <?php echo $current_page === 'profil_formateur.php' ? 'active' : ''; ?>"><i class="fas fa-user"></i> <span class="menu-text">Mon Profil</span></a>
        <?php endif; ?>
    </div>

    <!-- Contenu principal -->
    <div id="content" class="content epim-content">
        <!-- Ce contenu sera chargé à partir des autres pages -->

    <script>
    // Sidebar toggle handling (persisted in localStorage)
    (function() {
        var SIDEBAR_KEY = 'epim_sidebar_collapsed';
        var sidebar = null;
        var content = null;
        var toggle = null;

        function applyState(collapsed) {
            if (!sidebar || !content) return;
            if (collapsed) {
                sidebar.classList.add('collapsed');
                content.classList.add('collapsed');
            } else {
                sidebar.classList.remove('collapsed');
                content.classList.remove('collapsed');
            }
            if (toggle) {
                toggle.checked = collapsed;
                toggle.setAttribute('aria-checked', collapsed ? 'true' : 'false');
            }
        }

        window.toggleSidebar = function() {
            try {
                var cur = localStorage.getItem(SIDEBAR_KEY) === '1';
                var next = !cur;
                localStorage.setItem(SIDEBAR_KEY, next ? '1' : '0');
                applyState(next);
            } catch (e) {
                // ignore
            }
        };

        document.addEventListener('DOMContentLoaded', function() {
            sidebar = document.getElementById('sidebar');
            content = document.getElementById('content');
            toggle = document.getElementById('sidebar-collapse-check');
            try {
                var collapsed = localStorage.getItem(SIDEBAR_KEY) === '1';
                applyState(collapsed);
            } catch (e) {
                // ignore
            }

            if (toggle) {
                toggle.addEventListener('change', function() {
                    var next = toggle.checked;
                    try {
                        localStorage.setItem(SIDEBAR_KEY, next ? '1' : '0');
                    } catch (e) {
                        // ignore
                    }
                    applyState(next);
                });
            }
        });
    })();
    </script>
