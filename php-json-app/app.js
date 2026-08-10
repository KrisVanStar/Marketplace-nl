const $ = (sel) => document.querySelector(sel);

let categories = [];
let categoryLabels = {};
let pollTimer = null;

/** Everything rendered below can contain text scraped from third-party
 *  websites, so it always goes through this before touching innerHTML. */
function esc(value) {
  if (value === null || value === undefined) return "";
  return String(value)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");
}

function showFatalError(message) {
  let banner = document.getElementById("fatal-error-banner");
  if (!banner) {
    banner = document.createElement("div");
    banner.id = "fatal-error-banner";
    banner.className = "error-banner";
    document.querySelector("main").prepend(banner);
  }
  banner.textContent = message;
  banner.classList.remove("hidden");
}

function clearFatalError() {
  const banner = document.getElementById("fatal-error-banner");
  if (banner) banner.classList.add("hidden");
}

async function loadCategories() {
  try {
    const res = await fetch("api/categories.php");
    if (!res.ok) throw new Error(`HTTP ${res.status} bij api/categories.php`);
    categories = await res.json();
    if (!Array.isArray(categories) || categories.length === 0) {
      throw new Error("Lege brancheslijst ontvangen");
    }
  } catch (err) {
    showFatalError(
      `Kon de branches niet laden (${err.message}). Controleer of config.php op de server staat, ` +
        `of de bestanden in de juiste map staan (zie README.md), en of je de pagina via de juiste URL opent.`
    );
    return;
  }
  clearFatalError();

  categoryLabels = {};
  categories.forEach((c) => (categoryLabels[c.value] = c.label));

  const options = categories
    .map((c) => `<option value="${esc(c.value)}">${esc(c.label)}</option>`)
    .join("");
  $("#category").innerHTML = options;
  $("#filter-category").innerHTML = `<option value="">Alle branches</option>` + options;
}

function priorityMeta(priority, score) {
  switch (priority) {
    case "no_website":
      return { cls: "score-nowebsite", label: "Geen website", badge: "!" };
    case "high":
      return { cls: "score-high", label: "Hoge prioriteit", badge: score };
    case "medium":
      return { cls: "score-medium", label: "Gemiddeld", badge: score };
    case "unreachable":
      return { cls: "score-unreachable", label: "Onbereikbaar", badge: "?" };
    case "low":
      return { cls: "score-low", label: "Laag", badge: score };
    default:
      return { cls: "score-unreachable", label: "Nog niet geanalyseerd", badge: "…" };
  }
}

function currentFilters() {
  const params = new URLSearchParams();
  const city = $("#filter-city").value.trim();
  const category = $("#filter-category").value;
  const status = $("#filter-status").value;
  const priority = $("#filter-priority").value;
  const minScore = $("#filter-min-score").value;
  const sort = $("#filter-sort").value;
  if (city) params.set("city", city);
  if (category) params.set("category", category);
  if (status) params.set("status", status);
  if (priority) params.set("priority", priority);
  if (minScore && minScore !== "0") params.set("min_score", minScore);
  if (sort) params.set("sort", sort);
  return params;
}

async function loadLeads() {
  const params = currentFilters();
  let leads = [];
  try {
    const res = await fetch(`api/leads.php?${params.toString()}`);
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    leads = await res.json();
  } catch (err) {
    showFatalError(`Kon de leads niet laden (${err.message}).`);
    return;
  }
  renderLeads(leads);
  $("#export-btn").href = `api/export_csv.php?${params.toString()}`;
  $("#leads-count").textContent = leads.length ? leads.length : "";
}

function renderLeads(leads) {
  const body = $("#leads-body");
  body.innerHTML = "";
  $("#empty-state").classList.toggle("hidden", leads.length > 0);

  for (const lead of leads) {
    const meta = priorityMeta(lead.latest_priority, lead.latest_score);
    const tr = document.createElement("tr");
    tr.className = "lead-row";

    const websiteCell = lead.has_website
      ? `<a href="${esc(lead.website)}" target="_blank" rel="noopener noreferrer">${esc(
          hostnameOf(lead.website)
        )}</a>`
      : `<span class="no-website-tag">geen website</span>`;

    const topProblem = (lead.latest_reasons && lead.latest_reasons[0]) || "—";

    tr.innerHTML = `
      <td><span class="score-badge ${meta.cls}" title="${esc(meta.label)}">${esc(meta.badge)}</span></td>
      <td>
        <span class="lead-name">${esc(lead.name)}</span>
        ${lead.business_type ? `<span class="lead-type">${esc(lead.business_type)}</span>` : ""}
      </td>
      <td>${esc(lead.city || "")}</td>
      <td>${websiteCell}</td>
      <td class="problem-cell">${esc(topProblem)}</td>
      <td></td>
    `;

    const statusCell = tr.children[5];
    const statusSelect = document.createElement("select");
    statusSelect.className = "status-select";
    const statusLabels = { new: "Nieuw", contacted: "Benaderd", won: "Gewonnen", ignored: "Genegeerd" };
    statusSelect.innerHTML = Object.entries(statusLabels)
      .map(([v, l]) => `<option value="${v}" ${v === lead.status ? "selected" : ""}>${l}</option>`)
      .join("");
    statusSelect.addEventListener("click", (e) => e.stopPropagation());
    statusSelect.addEventListener("change", async (e) => {
      e.stopPropagation();
      await fetch("api/lead_update.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ id: lead.id, status: statusSelect.value }),
      });
    });
    statusCell.appendChild(statusSelect);

    tr.addEventListener("click", () => openDetail(lead.id));
    body.appendChild(tr);
  }
}

function hostnameOf(url) {
  try {
    return new URL(url).hostname.replace(/^www\./, "");
  } catch {
    return url;
  }
}

function row(label, value) {
  if (value === null || value === undefined || value === "" || value === false) return "";
  return `<div class="meta-row"><span>${esc(label)}</span><span>${value}</span></div>`;
}

function yesNo(value) {
  return value ? '<span class="ok">ja</span>' : '<span class="bad">nee</span>';
}

function renderCompanySection(lead) {
  const links = [];
  if (lead.osm_url) links.push(`<a href="${esc(lead.osm_url)}" target="_blank" rel="noopener noreferrer">OpenStreetMap</a>`);
  if (lead.map_url) links.push(`<a href="${esc(lead.map_url)}" target="_blank" rel="noopener noreferrer">Kaart</a>`);

  const contact = lead.contact || {};
  const socials = Object.entries(contact.social_links || {})
    .map(([name, url]) => `<a href="${esc(url)}" target="_blank" rel="noopener noreferrer">${esc(name)}</a>`)
    .join(" · ");

  const extraEmails = (contact.emails_on_site || []).filter((e) => e !== contact.email);
  const extraPhones = (contact.phones_on_site || []).filter((p) => p !== contact.phone);

  return `
    <h3>Bedrijfsgegevens</h3>
    ${row("Branche", esc(categoryLabels[lead.category] || lead.category || "—"))}
    ${row("Soort bedrijf", esc(lead.business_type))}
    ${row("Adres", esc(lead.address))}
    ${row("Postcode", esc(lead.postcode))}
    ${row("Plaats", esc(lead.city))}
    ${row("Telefoon", contact.phone ? `<a href="tel:${esc(contact.phone)}">${esc(contact.phone)}</a>` : "")}
    ${row("E-mail", contact.email ? `<a href="mailto:${esc(contact.email)}">${esc(contact.email)}</a>` : "")}
    ${row("Openingstijden", esc(contact.opening_hours))}
    ${row("Sociale media", socials)}
    ${row("Ook gevonden op de site", extraEmails.concat(extraPhones).map(esc).join(", "))}
    ${row("Bron", links.join(" · "))}
  `;
}

function renderWebsiteSection(lead) {
  if (!lead.has_website) {
    return `
      <h3>Website</h3>
      <div class="callout callout-critical">
        Dit bedrijf heeft geen website. Er is dus niets te analyseren — en precies dat maakt het
        een sterke lead.
      </div>
    `;
  }

  const s = lead.signals || {};
  if (!s.reachable) {
    return `
      <h3>Website</h3>
      ${row("Adres", `<a href="${esc(lead.website)}" target="_blank" rel="noopener noreferrer">${esc(lead.website)}</a>`)}
      <div class="callout callout-critical">${esc(lead.scan_error || "De website kon niet worden geladen.")}</div>
    `;
  }

  const platform = s.platform
    ? esc(s.platform) + (s.platform_version ? " " + esc(s.platform_version) : "")
    : "onbekend / maatwerk";

  return `
    <h3>Website</h3>
    ${row("Adres", `<a href="${esc(lead.website)}" target="_blank" rel="noopener noreferrer">${esc(lead.website)}</a>`)}
    ${s.final_url && s.redirected ? row("Komt uit op", esc(s.final_url)) : ""}
    ${row("HTTP-status", esc(s.status_code))}
    ${row("Laadtijd", s.response_time_ms !== null ? esc(s.response_time_ms) + " ms" : "")}
    ${row("Paginagrootte", s.page_size_kb !== null && s.page_size_kb !== undefined ? (s.page_size_kb < 1 ? "< 1 kB" : esc(s.page_size_kb) + " kB") : "")}
    ${row("Beveiligd (HTTPS)", yesNo(s.is_https))}
    ${row("Mobielvriendelijk", yesNo(s.has_viewport_meta))}
    ${row("Techniek / CMS", platform)}
    ${s.jquery_version ? row("jQuery-versie", esc(s.jquery_version)) : ""}
    ${row("Paginatitel", esc(s.title) || "<span class='bad'>ontbreekt</span>")}
    ${row("Zoekomschrijving", esc(s.meta_description) || "<span class='bad'>ontbreekt</span>")}
    ${row("Afbeeldingen", s.image_count ? `${esc(s.image_count)} (waarvan ${esc(s.images_without_alt)} zonder alt-tekst)` : "")}
    ${row("Statistieken", s.has_analytics ? esc((s.analytics_tools || []).join(", ")) : yesNo(false))}
    ${row("Contactformulier", yesNo(s.has_contact_form))}
    ${row("Copyright in voettekst", esc(s.copyright_year))}
    ${row("Laatste archiefwijziging", s.wayback_last_snapshot ? esc(s.wayback_last_snapshot) : "")}
    ${row("Google PageSpeed (mobiel)", s.pagespeed_mobile_score !== null && s.pagespeed_mobile_score !== undefined ? esc(s.pagespeed_mobile_score) + "/100" : "")}
  `;
}

function renderFindings(findings) {
  if (!findings || findings.length === 0) {
    return `<h3>Wat is er mis?</h3><p class="hint">Geen problemen gevonden.</p>`;
  }
  const items = findings
    .map(
      (f) => `
      <li class="finding severity-${esc(f.severity)}">
        <div class="finding-head">
          <span class="severity-badge severity-${esc(f.severity)}">${esc(f.severity)}</span>
          <strong>${esc(f.title)}</strong>
        </div>
        <p class="finding-detail">${esc(f.detail)}</p>
        <p class="finding-reco"><span>Aanbevolen:</span> ${esc(f.recommendation)}</p>
      </li>`
    )
    .join("");
  return `<h3>Wat is er mis, en wat is er nodig? <span class="count-pill">${findings.length}</span></h3>
          <ul class="findings-list">${items}</ul>`;
}

function renderPositives(positives) {
  if (!positives || positives.length === 0) return "";
  return `
    <h3>Wat al goed is</h3>
    <ul class="positives-list">${positives.map((p) => `<li>${esc(p)}</li>`).join("")}</ul>
  `;
}

async function openDetail(id) {
  let lead;
  try {
    const res = await fetch(`api/lead_detail.php?id=${encodeURIComponent(id)}`);
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    lead = await res.json();
  } catch (err) {
    alert(`Kon de details niet laden (${err.message}).`);
    return;
  }

  const meta = priorityMeta(lead.latest_priority, lead.latest_score);

  $("#modal-body").innerHTML = `
    <div class="modal-header">
      <span class="score-badge score-badge-lg ${meta.cls}">${esc(meta.badge)}</span>
      <div>
        <h2>${esc(lead.name)}</h2>
        <p class="modal-sub">${esc(meta.label)}${
    lead.scanned_at ? " · geanalyseerd op " + new Date(lead.scanned_at).toLocaleString("nl-NL") : ""
  }</p>
      </div>
    </div>
    ${lead.summary ? `<div class="callout">${esc(lead.summary)}</div>` : ""}
    ${renderCompanySection(lead)}
    ${renderWebsiteSection(lead)}
    ${renderFindings(lead.findings)}
    ${renderPositives(lead.positives)}
    <h3>Notities</h3>
    <textarea id="lead-notes" rows="3" placeholder="Eigen aantekeningen over deze lead...">${esc(lead.notes || "")}</textarea>
    <div class="modal-actions">
      <button id="save-notes-btn" type="button">Notitie opslaan</button>
      <button id="rescan-btn" class="secondary" type="button">Opnieuw analyseren</button>
      <span id="modal-feedback" class="modal-feedback"></span>
    </div>
  `;

  $("#save-notes-btn").addEventListener("click", async () => {
    await fetch("api/lead_update.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ id, notes: $("#lead-notes").value }),
    });
    $("#modal-feedback").textContent = "Notitie opgeslagen.";
  });

  $("#rescan-btn").addEventListener("click", async () => {
    $("#rescan-btn").textContent = "Bezig met analyseren...";
    $("#rescan-btn").disabled = true;
    await fetch("api/lead_rescan.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ id }),
    });
    await openDetail(id);
    await loadLeads();
  });

  $("#detail-modal").classList.remove("hidden");
}

$("#modal-close").addEventListener("click", () => $("#detail-modal").classList.add("hidden"));
$("#detail-modal").addEventListener("click", (e) => {
  if (e.target === $("#detail-modal")) $("#detail-modal").classList.add("hidden");
});
document.addEventListener("keydown", (e) => {
  if (e.key === "Escape") $("#detail-modal").classList.add("hidden");
});

$("#refresh-btn").addEventListener("click", loadLeads);
["#filter-category", "#filter-status", "#filter-priority", "#filter-sort"].forEach((sel) =>
  $(sel).addEventListener("change", loadLeads)
);
$("#filter-city").addEventListener("input", debounce(loadLeads, 400));
$("#filter-min-score").addEventListener("change", loadLeads);

function debounce(fn, ms) {
  let t;
  return (...args) => {
    clearTimeout(t);
    t = setTimeout(() => fn(...args), ms);
  };
}

$("#scan-form").addEventListener("submit", async (e) => {
  e.preventDefault();
  const statusEl = $("#job-status");
  statusEl.classList.remove("hidden");
  statusEl.textContent = "Bezig met zoeken in OpenStreetMap...";

  let job;
  try {
    const res = await fetch("api/scan_discover.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        city: $("#city").value.trim(),
        category: $("#category").value,
        limit: parseInt($("#limit").value, 10) || 20,
        only_without_website: $("#only-no-website").checked,
      }),
    });
    job = await res.json();
    if (!res.ok) throw new Error(job.error || `HTTP ${res.status}`);
  } catch (err) {
    statusEl.textContent = `Zoeken mislukt: ${err.message}`;
    return;
  }

  if (job.status === "error" || job.status === "done") {
    statusEl.textContent = job.message || "Klaar.";
    loadLeads();
    return;
  }
  pollJob(job.id);
});

function pollJob(jobId) {
  const statusEl = $("#job-status");
  statusEl.classList.remove("hidden");
  if (pollTimer) clearInterval(pollTimer);

  const tick = async () => {
    const res = await fetch(`api/scan_status.php?id=${encodeURIComponent(jobId)}`);
    if (!res.ok) return;
    const job = await res.json();

    let text = `${job.processed} van ${job.total} geanalyseerd — ${job.found_leads} kansrijke leads.`;
    if (job.message) text = `${job.message} ${text}`;
    if (job.status === "running" && job.processed < job.total) {
      text += " De rest wordt op de achtergrond verwerkt door de cron-taak.";
    }
    statusEl.textContent = text;

    if (job.status === "done" || job.status === "error") {
      clearInterval(pollTimer);
      pollTimer = null;
    }
    loadLeads();
  };

  tick();
  pollTimer = setInterval(tick, 4000);
}

$("#import-form").addEventListener("submit", async (e) => {
  e.preventDefault();
  const fileInput = $("#csv-file");
  if (!fileInput.files.length) return;

  const statusEl = $("#import-status");
  statusEl.classList.remove("hidden");
  statusEl.textContent = "Bezig met importeren en analyseren...";

  const formData = new FormData();
  formData.append("file", fileInput.files[0]);

  try {
    const res = await fetch("api/leads_import.php", { method: "POST", body: formData });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || `HTTP ${res.status}`);
    statusEl.textContent = `${data.imported} bedrijven geïmporteerd. Eerste batch geanalyseerd, rest volgt via de cron-taak.`;
    if (data.job_id) pollJob(data.job_id);
  } catch (err) {
    statusEl.textContent = `Import mislukt: ${err.message}`;
  }
  loadLeads();
});

loadCategories().then(loadLeads);
