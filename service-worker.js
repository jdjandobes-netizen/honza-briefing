const CACHE_NAME = "honza-briefing-v8";
const SHELL = [
  "./",
  "./index.html",
  "./styles.css?v=7",
  "./app.js?v=8",
  "./podcast.js?v=8",
  "./podcast.css?v=8",
  "./manifest.webmanifest?v=7",
  "./icons/favicon-32.png?v=7",
  "./icons/news-mark.png?v=7",
  "./icons/icon-192.png?v=7",
  "./icons/icon-512.png?v=7",
  "./icons/icon-maskable-512.png?v=7",
  "./data/current.json",
  "./data/archive/index.json"
];

self.addEventListener("install", (event) => {
  event.waitUntil(caches.open(CACHE_NAME).then((cache) => cache.addAll(SHELL)));
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
  // Never intercept private settings, authentication, audio or partial audio requests.
  if (url.pathname.includes("/api/") || event.request.headers.has("Range")) return;

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
    event.respondWith(
      caches.match(event.request).then((cached) => cached || fetch(event.request))
    );
  }
});

self.addEventListener("push", (event) => {
  let payload = {};
  if (event.data) {
    try {
      payload = event.data.json();
    } catch {
      payload = { body: event.data.text() };
    }
  }

  const title = payload.title || "Briefing je ready";
  const options = {
    body: payload.body || "Nové vydání je připravené ke čtení.",
    icon: "./icons/icon-192.png",
    badge: "./icons/icon-192.png",
    tag: payload.tag || "honza-briefing-ready",
    renotify: true,
    data: { url: payload.url || "./" }
  };

  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener("notificationclick", (event) => {
  event.notification.close();
  let target = new URL("./", self.location.href);
  try {
    const candidate = new URL(event.notification.data?.url || "./", self.location.href);
    if (candidate.origin === self.location.origin) target = candidate;
  } catch {}
  const targetUrl = target.href;

  event.waitUntil(
    self.clients.matchAll({ type: "window", includeUncontrolled: true }).then((clients) => {
      const existing = clients.find((client) => client.url.startsWith(self.location.origin));
      if (existing) return existing.navigate(targetUrl).then(() => existing.focus());
      return self.clients.openWindow(targetUrl);
    })
  );
});
