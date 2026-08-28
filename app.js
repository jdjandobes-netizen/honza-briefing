const app = document.querySelector("#app");
const nav = document.querySelector("#section-nav");
const refreshButton = document.querySelector("#refresh-button");
const installButton = document.querySelector("#install-button");
const notificationButton = document.querySelector("#notification-button");
const progressBar = document.querySelector("#reading-progress-bar");

let deferredInstallPrompt = null;
let pushConfig = null;

const el = (tag, className, text) => {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text !== undefined && text !== null) node.textContent = String(text);
  return node;
};

const safeUrl = (value) => {
  try {
    const url = new URL(value, window.location.href);
    return ["https:", "http:"].includes(url.protocol) ? url.href : null;
  } catch {
    return null;
  }
};

const sourceLink = (source) => {
  const url = safeUrl(source?.url);
  if (!url) return el("span", null, source?.name || "Zdroj neuveden");
  const link = el("a", null, `${source?.name || "Zdroj"} ↗`);
  link.href = url;
  link.target = "_blank";
  link.rel = "noopener noreferrer";
  return link;
};

const renderHeading = (title, note, indexLabel = "Přehled") => {
  const heading = el("div", "section-heading");
  heading.append(el("span", "section-index", indexLabel));
  heading.append(el("h2", null, title));
  if (note) heading.append(el("span", "section-note", note));
  return heading;
};

const renderStory = (item, index) => {
  const story = el("article", "story");
  story.append(el("span", "story-number", String(index + 1).padStart(2, "0")));

  const title = el("div", "story-title");
  if (item.tag) title.append(el("span", `badge${item.tag === "BEZE ZMĚNY" ? " quiet" : ""}`, item.tag));
  title.append(document.createTextNode(item.title || item.label || "Bez názvu"));
  story.append(title);

  const summary = el("div", "story-summary");
  summary.append(el("p", null, item.summary || ""));
  story.append(summary);

  const source = el("div", "story-source");
  source.append(sourceLink(item.source));
  if (item.time) source.append(el("div", "tone-muted", item.time));
  story.append(source);
  return story;
};

const renderMinor = (items) => {
  if (!Array.isArray(items) || items.length === 0) return null;
  const block = el("div", "minor-block");
  block.append(el("div", "minor-label", "Méně důležité"));
  const list = el("ul", "minor-list");
  for (const item of items) {
    const li = el("li");
    li.append(document.createTextNode(item.text || ""));
    if (item.source) {
      li.append(document.createTextNode(" — "));
      li.append(sourceLink(item.source));
    }
    list.append(li);
  }
  block.append(list);
  return block;
};

const renderTopStories = (items, edition) => {
  if (!Array.isArray(items) || items.length === 0) return null;
  const section = el("section", "top-section");
  section.id = "top";
  const isAfternoon = edition === "afternoon";
  section.append(renderHeading(
    isAfternoon ? "NOVÉ OD 7:00" : "TOP 3",
    isAfternoon ? "Nové události a podstatné posuny od rána" : "Nejdůležitější napříč vydáním",
    isAfternoon ? "Update / 01" : "Priority / 01"
  ));
  const grid = el("div", "top-grid");
  items.slice(0, 3).forEach((item, index) => {
    const card = el("article", `top-card${index === 0 ? " top-card-primary" : ""}`);
    card.append(el("span", "top-index", `0${index + 1}`));
    card.append(el("h3", null, item.title || "Bez názvu"));
    card.append(el("p", null, item.summary || ""));
    card.append(sourceLink(item.source));
    grid.append(card);
  });
  section.append(grid);
  return section;
};

const renderSection = (sectionData, index) => {
  const section = el("section", "briefing-section");
  section.id = sectionData.id;
  section.append(renderHeading(sectionData.title, sectionData.note, `Rubrika / ${String(index + 2).padStart(2, "0")}`));

  if (Array.isArray(sectionData.items) && sectionData.items.length) {
    const list = el("div", "story-list");
    sectionData.items.forEach((item, index) => list.append(renderStory(item, index)));
    section.append(list);
  } else {
    section.append(el("div", "empty-card", sectionData.emptyMessage || "Bez zásadní změny."));
  }

  const minor = renderMinor(sectionData.minor);
  if (minor) section.append(minor);
  return section;
};

const renderVwce = (vwce) => {
  if (!vwce) return null;
  const section = el("section", "briefing-section");
  section.id = "vwce";
  section.append(renderHeading("VWCE", vwce.marketStatus || "XETRA", "Trhy / ETF"));
  const panel = el("div", "vwce-panel");
  const price = el("div", "vwce-price");
  price.append(el("strong", null, vwce.price || "—"));
  price.append(el("span", "meta-line", `${vwce.currency || "EUR"} · ${vwce.asOf || "čas neuveden"}`));
  panel.append(price);

  if (Array.isArray(vwce.metrics) && vwce.metrics.length) {
    const stats = el("div", "stats");
    for (const metric of vwce.metrics) {
      const stat = el("div", "stat");
      stat.append(el("div", "stat-label", metric.label));
      stat.append(el("div", `stat-value tone-${metric.tone || "muted"}`, metric.value));
      stats.append(stat);
    }
    panel.append(stats);
  }
  if (vwce.note) panel.append(el("p", null, vwce.note));
  if (Array.isArray(vwce.sources) && vwce.sources.length) {
    const links = el("p", "footer-meta");
    vwce.sources.forEach((source, index) => {
      if (index) links.append(document.createTextNode(" · "));
      links.append(sourceLink(source));
    });
    panel.append(links);
  }
  section.append(panel);
  return section;
};

const renderPodcasts = (podcasts) => {
  if (!podcasts) return null;
  const section = el("section", "briefing-section");
  section.id = "podcasty";
  section.append(renderHeading("Podcasty", "Nové díly a ověřené tipy", "Audio / Výběr"));
  const list = el("div", "podcast-list");
  for (const podcast of podcasts.shows || []) {
    const row = el("article", "podcast-row");
    const show = el("div", "podcast-show");
    show.append(el("span", `badge${podcast.status === "BEZE ZMĚNY" ? " quiet" : ""}`, podcast.status || "NOVÉ"));
    show.append(document.createTextNode(podcast.show || "Podcast"));
    row.append(show);
    const title = el("div", "podcast-title");
    title.append(sourceLink({ name: podcast.title, url: podcast.url }));
    row.append(title);
    row.append(el("div", "podcast-date", podcast.date || ""));
    list.append(row);
  }
  section.append(list);

  if (Array.isArray(podcasts.recommendations) && podcasts.recommendations.length) {
    section.append(el("div", "minor-label", "Tipy — co dalšího poslouchat"));
    const recommendations = el("div", "recommendations");
    for (const item of podcasts.recommendations) {
      const card = el("div", "recommendation");
      card.append(el("strong", null, item.show));
      const link = sourceLink({ name: item.title, url: item.url });
      card.append(link);
      if (item.date) card.append(el("div", "podcast-date", item.date));
      recommendations.append(card);
    }
    section.append(recommendations);
  }
  return section;
};

const buildNavigation = (data) => {
  nav.replaceChildren();
  const links = [];
  if (data.topStories?.length) links.push(["top", data.publication?.edition === "afternoon" ? "Nové od 7:00" : "TOP 3"]);
  for (const section of data.sections || []) links.push([section.id, section.title]);
  if (data.vwce) links.push(["vwce", "VWCE"]);
  if (data.podcasts) links.push(["podcasty", "Podcasty"]);
  for (const [id, title] of links) {
    const link = el("a", null, title);
    link.href = `#${id}`;
    nav.append(link);
  }
  nav.hidden = links.length === 0;
};

const render = (data) => {
  const publication = data.publication || {};
  document.title = publication.title ? `${publication.title} · Honzův briefing` : "Honzův briefing";
  app.replaceChildren();

  const header = el("header", "publication-header");
  const copy = el("div", "publication-copy");
  copy.append(el("p", "eyebrow", publication.kicker || "Denní briefing pro Honzu"));
  const title = publication.title || "Honzův briefing";
  const [firstWord, ...rest] = title.split(" ");
  const titleNode = el("h1");
  titleNode.append(el("span", "edition-accent", firstWord));
  if (rest.length) titleNode.append(document.createTextNode(` ${rest.join(" ")}`));
  copy.append(titleNode);
  const meta = el("div", "meta-line");
  [publication.date, publication.generatedAt, publication.readingMinutes ? `${publication.readingMinutes} min čtení` : null, publication.freshnessLabel]
    .filter(Boolean)
    .forEach((item) => meta.append(el("span", null, item)));
  copy.append(meta);
  header.append(copy);

  const orbit = el("div", "publication-orbit");
  const core = el("div", "orbit-core");
  const editionMark = publication.edition === "morning" ? "07" : publication.edition === "afternoon" ? "16" : "H";
  core.append(el("strong", null, editionMark));
  orbit.append(core, el("span", "orbit-dot"), el("span", "orbit-caption", publication.edition === "afternoon" ? "PM edition" : "AM edition"));
  header.append(orbit);
  app.append(header);

  const lead = el("section", "lead");
  lead.append(el("span", "lead-label", publication.edition === "afternoon" ? "Co se změnilo" : "Dnes v kostce"));
  lead.append(el("p", null, publication.intro || "Aktuální vydání se připravuje."));
  app.append(lead);

  if (Array.isArray(data.sourceStatus) && data.sourceStatus.length) {
    const status = el("div", "status-row");
    status.setAttribute("aria-label", "Stav zdrojů");
    for (const source of data.sourceStatus) {
      const chip = el("span", "source-chip", source.name);
      chip.dataset.status = source.status || "warning";
      status.append(chip);
    }
    app.append(status);
  }

  const top = renderTopStories(data.topStories, publication.edition);
  if (top) app.append(top);
  (data.sections || []).forEach((section, index) => app.append(renderSection(section, index)));
  const vwce = renderVwce(data.vwce);
  if (vwce) app.append(vwce);
  const podcasts = renderPodcasts(data.podcasts);
  if (podcasts) app.append(podcasts);

  const footer = el("footer", "publication-footer");
  if (publication.nextEdition) footer.append(el("p", "footer-meta", `Další vydání: ${publication.nextEdition}`));
  if (data.footer?.sources?.length) footer.append(el("p", null, `Použité zdroje: ${data.footer.sources.join(", ")}.`));
  if (data.footer?.disclaimer) footer.append(el("p", null, data.footer.disclaimer));
  app.append(footer);

  buildNavigation(data);
  app.setAttribute("aria-busy", "false");
};

const showError = (error) => {
  app.replaceChildren();
  const card = el("section", "error-card");
  card.append(el("p", "eyebrow", "Briefing není dostupný"));
  card.append(el("h1", null, "Aktuální vydání se nepodařilo načíst."));
  card.append(el("p", null, "Zkontrolujte připojení a zkuste stránku obnovit. Pokud jste offline, poslední vydání se zobrazí po prvním úspěšném načtení."));
  card.append(el("p", "error-detail", error?.message || "Neznámá chyba"));
  app.append(card);
  nav.hidden = true;
  app.setAttribute("aria-busy", "false");
};

const loadBriefing = async () => {
  app.setAttribute("aria-busy", "true");
  refreshButton.disabled = true;
  try {
    const response = await fetch(`data/current.json?t=${Date.now()}`, { cache: "no-store" });
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    const data = await response.json();
    if (data.schemaVersion !== 1) throw new Error("Nepodporovaná verze dat");
    render(data);
  } catch (error) {
    showError(error);
  } finally {
    refreshButton.disabled = false;
  }
};

const urlBase64ToUint8Array = (value) => {
  const padding = "=".repeat((4 - value.length % 4) % 4);
  const base64 = (value + padding).replace(/-/g, "+").replace(/_/g, "/");
  const raw = window.atob(base64);
  return Uint8Array.from([...raw].map((character) => character.charCodeAt(0)));
};

const setupNotifications = async () => {
  if (!("Notification" in window) || !("serviceWorker" in navigator) || !("PushManager" in window)) return;

  try {
    const response = await fetch(`data/push-config.json?t=${Date.now()}`, { cache: "no-store" });
    if (!response.ok) return;
    pushConfig = await response.json();
    const endpoint = pushConfig?.subscribeEndpoint ? safeUrl(pushConfig.subscribeEndpoint) : null;
    if (!pushConfig?.enabled || !endpoint || new URL(endpoint).protocol !== "https:" || !pushConfig?.publicKey) return;
    pushConfig.subscribeEndpoint = endpoint;

    const registration = await navigator.serviceWorker.ready;
    const existingSubscription = await registration.pushManager.getSubscription();
    notificationButton.hidden = false;
    notificationButton.textContent = existingSubscription ? "Push zapnutý" : "Zapnout push";
    notificationButton.disabled = Boolean(existingSubscription);
  } catch {
    notificationButton.hidden = true;
  }
};

notificationButton.addEventListener("click", async () => {
  if (!pushConfig?.enabled) return;
  notificationButton.disabled = true;
  notificationButton.textContent = "Zapínám…";

  try {
    const permission = await Notification.requestPermission();
    if (permission !== "granted") throw new Error("Upozornění nebyla povolena");

    const registration = await navigator.serviceWorker.ready;
    const subscription = await registration.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: urlBase64ToUint8Array(pushConfig.publicKey)
    });

    const response = await fetch(pushConfig.subscribeEndpoint, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ subscription, app: "honza-briefing" })
    });
    if (!response.ok) throw new Error(`HTTP ${response.status}`);

    notificationButton.textContent = "Push zapnutý";
  } catch (error) {
    notificationButton.textContent = "Zkusit znovu";
    notificationButton.title = error?.message || "Push se nepodařilo zapnout";
    notificationButton.disabled = false;
  }
});

refreshButton.addEventListener("click", loadBriefing);

window.addEventListener("scroll", () => {
  const height = document.documentElement.scrollHeight - window.innerHeight;
  const ratio = height > 0 ? Math.min(1, Math.max(0, window.scrollY / height)) : 0;
  progressBar.style.width = `${ratio * 100}%`;
}, { passive: true });

window.addEventListener("beforeinstallprompt", (event) => {
  event.preventDefault();
  deferredInstallPrompt = event;
  installButton.hidden = false;
});

installButton.addEventListener("click", async () => {
  if (!deferredInstallPrompt) return;
  deferredInstallPrompt.prompt();
  await deferredInstallPrompt.userChoice;
  deferredInstallPrompt = null;
  installButton.hidden = true;
});

if ("serviceWorker" in navigator) {
  window.addEventListener("load", async () => {
    await navigator.serviceWorker.register("service-worker.js");
    await setupNotifications();
  });
}

loadBriefing();

