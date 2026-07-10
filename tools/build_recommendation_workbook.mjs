import fs from "node:fs/promises";
import path from "node:path";
import { SpreadsheetFile, Workbook } from "@oai/artifact-tool";

const root = path.resolve(import.meta.dirname, "..");
const inputPath = path.join(root, "data", "recommendation_rows.json");
const outputDir = path.join(root, "outputs", "recommendation_engine");
const previewDir = path.join(outputDir, "previews");
const rows = JSON.parse(await fs.readFile(inputPath, "utf8"));

const workbook = Workbook.create();
const summary = workbook.worksheets.add("Synthèse");
const knowledge = workbook.worksheets.add("Base de connaissances");

const columns = [
  "filiere",
  "unite",
  "sequence",
  "objectif",
  "description",
  "observation",
  "disposition",
  "source",
];
const data = rows.map((row) => [
  row.filiere,
  row.unite_id,
  row.sequence_id,
  row.objectif,
  row.description,
  row.observation,
  row.disposition,
  row.source,
]);

knowledge.getRangeByIndexes(0, 0, data.length + 1, columns.length).values = [columns, ...data];
knowledge.showGridLines = false;
knowledge.freezePanes.freezeRows(1);
knowledge.getRange("A1:H1").format = {
  fill: "#17365D",
  font: { bold: true, color: "#FFFFFF" },
  wrapText: true,
  verticalAlignment: "center",
};
knowledge.getRange(`A2:C${data.length + 1}`).format.verticalAlignment = "top";
knowledge.getRange(`D2:G${data.length + 1}`).format = {
  wrapText: true,
  verticalAlignment: "top",
};
knowledge.getRange(`A1:H${data.length + 1}`).format.borders = {
  preset: "all",
  style: "thin",
  color: "#D9E2F3",
};
knowledge.getRange("A:A").format.columnWidth = 27;
knowledge.getRange("B:C").format.columnWidth = 11;
knowledge.getRange("D:G").format.columnWidth = 48;
knowledge.getRange("H:H").format.columnWidth = 14;
knowledge.getRange("1:1").format.rowHeight = 30;
knowledge.tables.add(`A1:H${data.length + 1}`, true, "KnowledgeBaseTable").style = "TableStyleMedium2";

const byFiliere = new Map();
for (const row of rows) {
  const current = byFiliere.get(row.filiere) ?? {
    rows: 0,
    historical: 0,
    generated: 0,
    units: new Set(),
    sequences: new Set(),
  };
  current.rows += 1;
  current[row.source] += 1;
  current.units.add(row.unite_id);
  current.sequences.add(row.sequence_id);
  byFiliere.set(row.filiere, current);
}

summary.showGridLines = false;
summary.getRange("A1:F1").merge();
summary.getRange("A1").values = [["Base de connaissances pédagogiques"]];
summary.getRange("A1:F1").format = {
  fill: "#17365D",
  font: { bold: true, color: "#FFFFFF", size: 16 },
  horizontalAlignment: "center",
  verticalAlignment: "center",
};
summary.getRange("A1:F1").format.rowHeight = 36;
summary.getRange("A3:B7").values = [
  ["Indicateur", "Valeur"],
  ["Chaînes pédagogiques", rows.length],
  ["Contenus historiques", rows.filter((row) => row.source === "historical").length],
  ["Contenus générés", rows.filter((row) => row.source === "generated").length],
  ["Séquences couvertes", new Set(rows.map((row) => row.sequence_id)).size],
];
summary.getRange("A3:B3").format = {
  fill: "#4472C4",
  font: { bold: true, color: "#FFFFFF" },
};
summary.getRange("A3:B7").format.borders = {
  preset: "all",
  style: "thin",
  color: "#B4C6E7",
};

const filiereRows = [["Filière", "Chaînes", "Historique", "Généré", "Unités", "Séquences"]];
for (const [filiere, values] of [...byFiliere.entries()].sort()) {
  filiereRows.push([
    filiere,
    values.rows,
    values.historical,
    values.generated,
    values.units.size,
    values.sequences.size,
  ]);
}
summary.getRangeByIndexes(9, 0, filiereRows.length, 6).values = filiereRows;
summary.getRange("A10:F10").format = {
  fill: "#4472C4",
  font: { bold: true, color: "#FFFFFF" },
};
summary.getRange(`A10:F${9 + filiereRows.length}`).format.borders = {
  preset: "all",
  style: "thin",
  color: "#B4C6E7",
};
summary.getRange("A:A").format.columnWidth = 31;
summary.getRange("B:F").format.columnWidth = 15;
summary.getRange("A3:F20").format.verticalAlignment = "center";
summary.freezePanes.freezeRows(1);

const chart = summary.charts.add(
  "bar",
  summary.getRange(`A10:D${9 + filiereRows.length}`),
);
chart.title = "Couverture par filière";
chart.hasLegend = true;
chart.xAxis = { axisType: "textAxis" };
chart.setPosition("H3", "P18");

await fs.mkdir(previewDir, { recursive: true });
for (const [sheetName, range] of [
  ["Synthèse", "A1:P20"],
  ["Base de connaissances", "A1:H28"],
]) {
  const preview = await workbook.render({
    sheetName,
    range,
    scale: 1,
    format: "png",
  });
  const safeName = sheetName.normalize("NFD").replace(/[\u0300-\u036f]/g, "").replace(/\s+/g, "_");
  await fs.writeFile(
    path.join(previewDir, `${safeName}.png`),
    new Uint8Array(await preview.arrayBuffer()),
  );
}

const inspection = await workbook.inspect({
  kind: "table",
  range: "Synthèse!A1:F14",
  include: "values,formulas",
  tableMaxRows: 14,
  tableMaxCols: 6,
});
console.log(inspection.ndjson);

const errors = await workbook.inspect({
  kind: "match",
  searchTerm: "#REF!|#DIV/0!|#VALUE!|#NAME\\?|#N/A",
  options: { useRegex: true, maxResults: 100 },
  summary: "Recherche finale des erreurs de formule",
});
console.log(errors.ndjson);

await fs.mkdir(outputDir, { recursive: true });
const output = await SpreadsheetFile.exportXlsx(workbook);
await output.save(path.join(outputDir, "recommendation_knowledge_base.xlsx"));

