# Génération de la base de connaissances

Depuis la racine du projet :

```powershell
php tools/build_recommendation_data.php
node tools/build_recommendation_workbook.mjs
```

Le premier script analyse et normalise les CSV, puis génère le JSON, le seed SQL et les rapports. Le second construit le classeur XLSX à partir des données normalisées.

