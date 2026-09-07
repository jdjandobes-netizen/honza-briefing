(() => {
  const originalFetch = window.fetch.bind(window);
  let currentArchivePath = null;
  let japanPromise = null;

  const extractArchivePath = (value) => {
    const match = String(value || "").match(/data\/archive\/\d{4}-\d{2}-\d{2}-(morning|afternoon)\.json/);
    return match ? match[0] : null;
  };

  const loadJapan = () => {
    if (!japanPromise) {
      japanPromise = originalFetch(`data/japan-current.json?t=${Date.now()}`, { cache: "no-store" })
        .then((response) => {
          if (!response.ok) throw new Error(`Japan fallback: HTTP ${response.status}`);
          return response.json();
        })
        .catch((error) => {
          console.warn("Japan fallback unavailable", error);
          return null;
        });
    }
    return japanPromise;
  };

  const mergeJapan = (data, patch) => {
    if (!data || !patch?.section || !Array.isArray(data.sections)) return data;
    if (data.sections.some((section) => section?.id === "japonsko")) return data;

    const sections = [...data.sections];
    const worldIndex = sections.findIndex((section) => section?.id === "svet");
    const insertAt = worldIndex >= 0 ? worldIndex + 1 : Math.min(3, sections.length);
    sections.splice(insertAt, 0, patch.section);
    data.sections = sections;

    if (Array.isArray(patch.sourceStatus)) {
      const existing = new Set((data.sourceStatus || []).map((item) => item?.name));
      data.sourceStatus = [...(data.sourceStatus || [])];
      for (const item of patch.sourceStatus) {
        if (item?.name && !existing.has(item.name)) {
          data.sourceStatus.push(item);
          existing.add(item.name);
        }
      }
    }

    if (Array.isArray(patch.footerSources)) {
      data.footer = data.footer || {};
      const existing = new Set(data.footer.sources || []);
      data.footer.sources = [...(data.footer.sources || [])];
      for (const name of patch.footerSources) {
        if (name && !existing.has(name)) {
          data.footer.sources.push(name);
          existing.add(name);
        }
      }
    }

    if (data.publication?.readingMinutes) {
      data.publication.readingMinutes = Number(data.publication.readingMinutes) + 4;
    }
    return data;
  };

  window.fetch = async (input, init) => {
    const requestUrl = typeof input === "string" ? input : input?.url || "";
    const response = await originalFetch(input, init);

    if (requestUrl.includes("data/current.json") && response.ok) {
      try {
        const pointer = await response.clone().json();
        currentArchivePath = extractArchivePath(pointer?.current?.path);
      } catch {
        currentArchivePath = null;
      }
      return response;
    }

    const requestedArchive = extractArchivePath(requestUrl);
    if (!response.ok || !requestedArchive || !currentArchivePath || requestedArchive !== currentArchivePath) {
      return response;
    }

    try {
      const data = await response.clone().json();
      if (data?.sections?.some((section) => section?.id === "japonsko")) return response;
      const patch = await loadJapan();
      if (!patch?.section) return response;
      const merged = mergeJapan(data, patch);
      const headers = new Headers(response.headers);
      headers.set("content-type", "application/json; charset=utf-8");
      return new Response(JSON.stringify(merged), {
        status: response.status,
        statusText: response.statusText,
        headers
      });
    } catch (error) {
      console.warn("Japan fallback merge failed", error);
      return response;
    }
  };
})();
