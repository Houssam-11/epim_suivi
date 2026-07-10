# Plateforme de suivi pédagogique

Application web PHP/MySQL destinée au suivi pédagogique d'un établissement de formation professionnelle. Elle permet de gérer les filières, unités de formation, séquences pédagogiques, séances, validations, années scolaires, configurations calendaires et exports institutionnels.

## Prérequis

- PHP avec Apache, par exemple via XAMPP.
- MySQL ou MariaDB.
- Composer et les dépendances déjà présentes dans `vendor/`.
- Extension PHP `mysqli`.
- Extension PHP `zip` pour certains traitements Excel.

## Installation locale

1. Placer le projet dans un dossier servi par Apache, par exemple :

   ```text
   C:\xampp\htdocs\sp - Server v1
   ```

2. Créer la base de données MySQL :

   ```sql
   CREATE DATABASE epim_gestion_codex CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

3. Importer le dump principal si disponible :

   ```text
   epim_gestion_codex.sql
   ```

4. Appliquer les migrations du dossier `database/migrations/` si le dump ne contient pas les dernières évolutions.

5. Vérifier la connexion dans `db.php`.

6. Démarrer Apache et MySQL depuis XAMPP.

7. Ouvrir l'application dans le navigateur :

   ```text
   http://localhost/sp%20-%20Server%20v1/
   ```

## Points d'entrée principaux

| Fichier | Rôle |
|---|---|
| `index.php` | Connexion. |
| `tableau_bord_directeur.php` | Tableau de bord Directeur. |
| `tableau_bord_formateur.php` | Tableau de bord Formateur. |
| `liste_filieres.php` | Gestion des filières. |
| `modifier_filiere.php` | Gestion d'une filière et de ses unités. |
| `gerer_unite.php` | Gestion d'une unité et de ses séquences. |
| `configuration_sequence.php` | Configuration pédagogique d'une séquence. |
| `ajouter_seance.php` | Ajout de séance côté Formateur. |
| `validation_seances.php` | Validation des séances côté Directeur. |
| `configuration_dates.php` | Configuration globale des dates. |
| `edition_etats.php` | Suivi pédagogique et export Excel. |

## Documentation

La documentation complète de reprise est disponible dans :

- `DEVELOPER_GUIDE.md`

La documentation technique historique et détaillée se trouve dans :

- `docs/`

## Export Excel

L'export annuel utilise le modèle officiel :

```text
_Planning prévisionnel .xlsx
```

Ce fichier ne doit jamais être modifié directement. L'application crée une copie temporaire, la remplit, puis génère le fichier exporté.

