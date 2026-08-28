import { readFile } from "node:fs/promises";

const path = process.argv[2] || new URL("../data/current.json", import.meta.url);
const raw = await readFile(path, "utf8");
const data = JSON.parse(raw);
const errors = [];

if (data.schemaVersion !== 1) errors.push("schemaVersion musí být 1");
if (!data.publication?.title) errors.push("chybí publication.title");
if (!Array.isArray(data.sections)) errors.push("sections musí být pole");

const checkSource = (source, label) => {
  if (!source?.name) errors.push(`${label}: chybí source.name`);
  try {
    const url = new URL(source?.url);
    if (url.protocol !== "https:") errors.push(`${label}: zdroj musí používat HTTPS`);
  } catch {
    errors.push(`${label}: neplatná URL zdroje`);
  }
};

(data.topStories || []).forEach((item, index) => checkSource(item.source, `topStories[${index}]`));
(data.sections || []).forEach((section, sectionIndex) => {
  (section.items || []).forEach((item, index) => checkSource(item.source, `sections[${sectionIndex}].items[${index}]`));
  (section.minor || []).forEach((item, index) => checkSource(item.source, `sections[${sectionIndex}].minor[${index}]`));
});

if (errors.length) {
  console.error(errors.join("\n"));
  process.exit(1);
}

console.log(`OK: ${data.publication.title} (${data.publication.edition || "bez typu"})`);
