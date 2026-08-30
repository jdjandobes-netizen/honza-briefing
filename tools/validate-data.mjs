import { readFile } from "node:fs/promises";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const repositoryRoot = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const errors = [];

const readJson = async (path) => JSON.parse(await readFile(path, "utf8"));
const archivePathPattern = /^data\/archive\/(\d{4}-\d{2}-\d{2})-(morning|afternoon)\.json$/;

const checkSource = (source, label) => {
  if (!source?.name) errors.push(`${label}: chybí source.name`);
  try {
    const url = new URL(source?.url);
    if (url.protocol !== "https:") errors.push(`${label}: zdroj musí používat HTTPS`);
  } catch {
    errors.push(`${label}: neplatná URL zdroje`);
  }
};

const validateEdition = (data, label, expected = {}) => {
  if (data?.schemaVersion !== 1) errors.push(`${label}: schemaVersion musí být 1`);
  if (!data?.publication?.title) errors.push(`${label}: chybí publication.title`);
  if (!["morning", "afternoon"].includes(data?.publication?.edition)) errors.push(`${label}: neplatné publication.edition`);
  if (!Array.isArray(data?.sections)) errors.push(`${label}: sections musí být pole`);
  if (expected.edition && data?.publication?.edition !== expected.edition) errors.push(`${label}: typ vydání neodpovídá manifestu`);

  (data?.topStories || []).forEach((item, index) => checkSource(item.source, `${label}.topStories[${index}]`));
  (data?.sections || []).forEach((section, sectionIndex) => {
    (section.items || []).forEach((item, index) => checkSource(item.source, `${label}.sections[${sectionIndex}].items[${index}]`));
    (section.minor || []).forEach((item, index) => checkSource(item.source, `${label}.sections[${sectionIndex}].minor[${index}]`));
  });
};

const validatePointer = (pointer) => {
  if (pointer?.schemaVersion !== 1 || pointer?.kind !== "briefing-pointer") errors.push("current.json: neplatný pointer");
  const match = archivePathPattern.exec(pointer?.current?.path || "");
  if (!match) errors.push("current.json: neplatná archivní cesta");
  if (match && `${match[1]}-${match[2]}` !== pointer?.current?.id) errors.push("current.json: id neodpovídá cestě");
  if (match && match[1] !== pointer?.current?.date) errors.push("current.json: date neodpovídá cestě");
  if (match && match[2] !== pointer?.current?.edition) errors.push("current.json: edition neodpovídá cestě");
};

const validateIndex = (index) => {
  if (index?.schemaVersion !== 1 || index?.kind !== "briefing-archive-index") errors.push("index.json: neplatný manifest");
  if (index?.timezone !== "Europe/Prague") errors.push("index.json: timezone musí být Europe/Prague");
  if ((index?.retention?.visibleCalendarDays || 0) < 7) errors.push("index.json: frontend musí zobrazovat nejméně 7 dní");
  if (index?.retention?.deleteOlder !== false) errors.push("index.json: deleteOlder musí být false");
  if (!Array.isArray(index?.editions) || !index.editions.length) errors.push("index.json: editions musí být neprázdné pole");

  const seen = new Set();
  for (const [position, entry] of (index?.editions || []).entries()) {
    const match = archivePathPattern.exec(entry?.path || "");
    if (!match) errors.push(`index.json.editions[${position}]: neplatná cesta`);
    if (seen.has(entry?.id)) errors.push(`index.json.editions[${position}]: duplicitní id`);
    seen.add(entry?.id);
    if (match && entry.id !== `${match[1]}-${match[2]}`) errors.push(`index.json.editions[${position}]: id neodpovídá cestě`);
    if (match && (entry.date !== match[1] || entry.edition !== match[2])) errors.push(`index.json.editions[${position}]: datum nebo typ neodpovídá cestě`);
  }

  if (!seen.has(index?.latest?.id)) errors.push("index.json: latest není v editions");
};

const explicitPath = process.argv[2];
if (explicitPath) {
  const data = await readJson(resolve(explicitPath));
  if (data?.kind === "briefing-pointer") validatePointer(data);
  else if (data?.kind === "briefing-archive-index") validateIndex(data);
  else validateEdition(data, explicitPath);
} else {
  const pointer = await readJson(resolve(repositoryRoot, "data/current.json"));
  const index = await readJson(resolve(repositoryRoot, "data/archive/index.json"));
  validatePointer(pointer);
  validateIndex(index);

  if (pointer?.current?.id !== index?.latest?.id || pointer?.current?.path !== index?.latest?.path) {
    errors.push("current.json a index.json.latest na sebe neukazují");
  }

  for (const entry of index?.editions || []) {
    const edition = await readJson(resolve(repositoryRoot, entry.path));
    validateEdition(edition, entry.path, entry);
  }
}

if (errors.length) {
  console.error(errors.join("\n"));
  process.exit(1);
}

console.log(explicitPath ? `OK: ${explicitPath}` : "OK: pointer, manifest a všechna archivní vydání");
