/**
 * Fence ```awn-query / ```dataview / ```awn-dataview — DQL-lite + optional @transform JSON (no eval).
 */
export async function render(target, source, ctx) {
  const fenceTag = String(ctx?.fenceTag || "dql").trim() || "dql";
  const root = target.closest(".awn-fence-block");
  root?.classList.add("awn-fence-block--dql");

  const { dql, transform } = parseDqlBlockSource(source);
  if (!dql) {
    mountDqlBlock(target, fenceTag, dql, `<p class="awn-dql-empty">Пустой DQL-запрос</p>`);
    return;
  }

  const res = await fetch("/api/storage-index/query", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    credentials: "same-origin",
    body: JSON.stringify({ dql })
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    throw new Error(data.error || data.details || `HTTP ${res.status}`);
  }

  if (data.mode === "dql-hybrid" && Array.isArray(data.hybrid?.hits)) {
    const hits = data.hybrid.hits;
    const table = `<table class="awn-dql-table"><thead><tr><th>path</th><th>snippet</th><th>src</th></tr></thead><tbody>${hits
      .map(
        (h) =>
          `<tr><td>${esc(h.path)}</td><td>${esc(h.snippet || "")}</td><td>${esc(
            (h.sources || []).join("+")
          )}</td></tr>`
      )
      .join("")}</tbody></table><p class="awn-dql-meta">dql-hybrid · filters ${data.total} · hits ${hits.length}</p>`;
    mountDqlBlock(target, fenceTag, dql, table);
    return;
  }

  let rows = Array.isArray(data.results) ? data.results : [];
  rows = applyClientTransform(rows, transform);

  if (!rows.length) {
    mountDqlBlock(
      target,
      fenceTag,
      dql,
      `<p class="awn-dql-empty">Нет строк (total ${data.total ?? 0})</p>
       <p class="awn-dql-empty-hint">Проверьте FROM/WHERE, пересоберите storage-index в меню workspace или ослабьте фильтры.</p>`
    );
    return;
  }

  if (data.mode === "dql-group") {
    const body = rows.map(
      (r) =>
        `<tr><td>${esc(r.groupKey)}</td><td>${esc(r.value)}</td><td>${esc(r.count)}</td></tr>`
    );
    mountDqlBlock(
      target,
      fenceTag,
      dql,
      `<table class="awn-dql-table"><thead><tr><th>group</th><th>value</th><th>count</th></tr></thead><tbody>${body.join("")}</tbody></table>`
    );
    return;
  }

  const keys = new Set(["path"]);
  for (const row of rows) {
    if (row.fields && typeof row.fields === "object") {
      Object.keys(row.fields).forEach((k) => keys.add(k));
    }
  }
  const columns = [...keys];
  const thead = columns.map((c) => `<th>${esc(c)}</th>`).join("");
  const tbody = rows
    .map((row) => {
      const cells = columns.map((col) => {
        if (col === "path") return `<td>${esc(row.path)}</td>`;
        const entry = row.fields?.[col];
        const val = entry != null && typeof entry === "object" && "value" in entry ? entry.value : entry;
        return `<td>${esc(val)}</td>`;
      });
      return `<tr>${cells.join("")}</tr>`;
    })
    .join("");

  mountDqlBlock(
    target,
    fenceTag,
    dql,
    `<table class="awn-dql-table"><thead><tr>${thead}</tr></thead><tbody>${tbody}</tbody></table><p class="awn-dql-meta">${esc(
      data.engine || ""
    )} · total ${data.total ?? rows.length}</p>`
  );
}

export default render;

function mountDqlBlock(target, fenceTag, dql, bodyHtml) {
  const tagLabel = fenceTagLabel(fenceTag);
  const query = String(dql || "").trim();
  const queryPreview = query
    ? query
        .split(/\r?\n/)
        .map((l) => l.trim())
        .filter((l) => l && !l.startsWith("@transform"))
        .slice(0, 3)
        .join(" · ")
    : "—";

  target.innerHTML = `
    <div class="awn-dql-block" role="region" aria-label="DQL ${esc(fenceTag)}">
      <header class="awn-dql-block-head">
        <span class="awn-dql-block-badge">DQL-lite</span>
        <code class="awn-dql-block-tag">\`\`\`${esc(fenceTag)}</code>
        <span class="awn-dql-block-kind">${esc(tagLabel)}</span>
      </header>
      ${
        query
          ? `<details class="awn-dql-block-query"><summary class="awn-dql-block-query-summary">${esc(
              queryPreview
            )}</summary><pre class="awn-dql-block-query-pre">${esc(query)}</pre></details>`
          : ""
      }
      <div class="awn-dql-block-body">${bodyHtml}</div>
    </div>`;
}

function fenceTagLabel(fenceTag) {
  if (fenceTag === "dataview") return "алиас Obsidian Dataview";
  if (fenceTag === "awn-dataview") return "алиас CMS (Obsidian-совместимый)";
  if (fenceTag === "awn-query") return "нативный блок CMS";
  return "запрос к storage-index";
}

function parseDqlBlockSource(source) {
  const lines = String(source || "").split(/\r?\n/);
  let transform = null;
  const dqlLines = [];
  for (const line of lines) {
    const t = line.trim();
    if (t.startsWith("@transform")) {
      const json = t.slice("@transform".length).trim();
      try {
        transform = JSON.parse(json);
      } catch {
        // ignore invalid transform
      }
      continue;
    }
    dqlLines.push(line);
  }
  return { dql: dqlLines.join("\n").trim(), transform };
}

function applyClientTransform(rows, spec) {
  if (!spec || typeof spec !== "object") return rows;
  let out = rows.slice();
  if (Array.isArray(spec.pick) && spec.pick.length) {
    const keys = spec.pick.map((k) => String(k));
    out = out.map((row) => {
      const next = { path: row.path, fields: {} };
      for (const key of keys) {
        if (key === "path") continue;
        if (row.fields?.[key] != null) next.fields[key] = row.fields[key];
      }
      return next;
    });
  }
  if (spec.sort?.field) {
    const field = String(spec.sort.field);
    const dir = spec.sort.dir === "desc" ? -1 : 1;
    out.sort((a, b) => {
      const av = field === "path" ? a.path : a.fields?.[field]?.value ?? a.fields?.[field];
      const bv = field === "path" ? b.path : b.fields?.[field]?.value ?? b.fields?.[field];
      return dir * String(av || "").localeCompare(String(bv || ""), "ru");
    });
  }
  if (Number.isFinite(Number(spec.limit))) {
    out = out.slice(0, Math.max(1, Number(spec.limit)));
  }
  return out;
}

function esc(value) {
  return String(value ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;");
}
