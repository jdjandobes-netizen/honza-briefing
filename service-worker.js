const CACHE_NAME = "honza-briefing-v20";
const SHELL = [
  "./",
  "./index.html",
  "./styles.css?v=7",
  "./app.js?v=10",
  "./podcast.js?v=13",
  "./podcast.css?v=8",
  "./manifest.webmanifest?v=10",
  "./assets/brand/news-32-v10.png",
  "./assets/brand/news-192-v10.png",
  "./assets/brand/news-512-v10.png",
  "./assets/brand/news-maskable-512-v10.png",
  "./apple-touch-icon.png",
  "./data/current.json",
  "./data/archive/index.json"
];

self.addEventListener("install", (event) => {
  event.waitUntil(caches.open(CACHE_NAME).then((cache) => cache.addAll(SHELL.map(path => new Request(path, {cache: "reload"})))));
  self.skipWaiting();
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener("fetch", (event) => {
  if (event.request.method !== "GET") return;
  const url = new URL(event.request.url);
  if (url.pathname.includes("/api/") || event.request.headers.has("Range")) return;

  if (event.request.mode === "navigate" && url.origin === self.location.origin) {
    event.respondWith(fetch(event.request).then(response => {
      if (response.ok) { const copy=response.clone(); event.waitUntil(caches.open(CACHE_NAME).then(cache=>cache.put(event.request,copy))); }
      return response;
    }).catch(async()=>await caches.match(event.request)||await caches.match("./index.html")));
    return;
  }

  const publicSource = url.origin === self.location.origin ||
    (url.origin === "https://raw.githubusercontent.com" && url.pathname.startsWith("/jdjandobes-netizen/honza-briefing/main/data/"));
  if (publicSource && url.pathname.includes("/data/") && url.pathname.endsWith(".json")) {
    const canonicalUrl = new URL(url);
    canonicalUrl.search = "";
    const canonicalRequest = new Request(canonicalUrl.href);
    event.respondWith(
      fetch(event.request)
        .then((response) => {
          const copy = response.clone();
          if (response.ok) event.waitUntil(caches.open(CACHE_NAME).then((cache) => cache.put(canonicalRequest, copy)));
          return response;
        })
        .catch(() => caches.match(canonicalRequest))
    );
    return;
  }

  if (url.origin === self.location.origin) {
    event.respondWith(caches.match(event.request).then((cached) => cached || fetch(event.request)));
  }
});

// Only explicit podcast completion notifications remain supported.
self.addEventListener("push", (event) => {
  let payload = {};
  try { payload = event.data?.json() || {}; } catch { return; }
  if (!String(payload.tag || "").startsWith("podcast-")) return;
  event.waitUntil(self.registration.showNotification(payload.title || "Podcast je pĹ™ipravenĂ˝", {
    body: payload.body || "HonzĹŻv briefing",
    icon: "./assets/brand/news-192-v10.png",
    badge: "./assets/brand/news-192-v10.png",
    tag: payload.tag,
    data: {url: payload.url || "./"}
  }));
});

self.addEventListener("notificationclick", (event) => {
  event.notification.close();
  let target = new URL("./", self.location.href);
  try {
    const candidate = new URL(event.notification.data?.url || "./", self.location.href);
    if (candidate.origin === self.location.origin) target = candidate;
  } catch {}
  const targetUrl = target.href;
  event.waitUntil(self.clients.matchAll({ type: "window", includeUncontrolled: true }).then((clients) => {
    const existing = clients.find((client) => client.url.startsWith(self.location.origin));
    if (existing) return existing.navigate(targetUrl).then(() => existing.focus());
    return self.clients.openWindow(targetUrl);
  }));
});
