const $ = (sel) => document.querySelector(sel);

let categories = [];
let pollTimer = null;

async function loadCategories() {
  const res = await fetch("/api/categories");
  categories = await res.json();
  const scanSelect = $("#category");
  const filterSelect = $("#filter-category");
  scanSelect.innerHTML = categories.map((c) => `<option value="${c}">${c.replace(/_/g, " ")}</option>`).join("");
  filterSelect.innerHTML =
    `<option value="">Alle categorieën</option>` +
    categories.map((c) => `<option value="${c}">${c.replace(/_/g, " ")}</option>`).join("");
}

function scoreClass(priority) {
  if (priority === "high") return "score-high";
  if (priority === "medium") return "score-medium";
  if (priority === "unreachable") return "score-unreachable";
  return "score-low";
}

function currentFilters() {
  const params = new URLSearchParams();
  const city = $("#filter-city").value.trim();
  const category = $("#filter-category").value;
  const status = $("#filter-status").value;
  const minScore = $("#filter-min-score").value;
  if (city) params.set("city", city);
  if (category) params.set("category", category);
  if (status) params.set("status", status);
  if (minScore) params.set("min_score", minScore);
  return params;
}

async function loadLeads() {
  const params = currentFilters();
  const res = await fetch(`/api/leads?${params.toString()}`);
  const leads = await res.json();
  renderLeads(leads);
  $("#export-btn").href = `/api/export.csv?${params.toString()}`;
}

function renderLeads(leads) {
  const body = $("#leads-body");
  const empty = $("#empty-state");
  body.innerHTML = "";
  empty.classList.toggle("hidden", leads.length > 0);

  for (const lead of leads) {
    const tr = document.createElement("tr");
    tr.className = "lead-row";
    const score = lead.latest_score;
    const badge =
      score === null || score === undefined
        ? '<span class="score-badge score-unreachable">?</span>'
        : `<span class="score-badge ${scoreClass(lead.latest_priority)}">${score}</span>`;

    tr.innerHTML = `
      <td>${badge}</td>
      <td>${lead.name}</td>
      <td>${lead.city || ""}</td>
      <td>${(lead.category || "").replace(/_/g, " ")}</td>
      <td><a href="${lead.website}" target="_blank" rel="noopener">${new URL(lead.website).hostname}</a></td>
      <td></td>
      <td></td>
    `;

    const statusCell = tr.children[5];
    const statusSelect = document.createElement("select");
    statusSelect.className = "status-select";
    statusSelect.innerHTML = ["new", "contacted", "won", "ignored"]
      .map((s) => `<option value="${s}" ${s === lead.status ? "selected" : ""}>${s}</option>`)
      .join("");
    statusSelect.addEventListener("click", (e) => e.stopPropagation());
    statusSelect.addEventListener("change", async (e) => {
      e.stopPropagation();
      await fetch(`/api/leads/${lead.id}`, {
        method: "PATCH",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ status: statusSelect.value }),
      });
    });
    statusCell.appendChild(statusSelect);

    tr.addEventListener("click", () => openDetail(lead.id));
    body.appendChild(tr);
  }
}

async function openDetail(id) {
  const res = await fetch(`/api/leads/${id}`);
  if (!res.ok) return;
  const lead = await res.json();
  const reasons = (lead.latest_reasons || []).map((r) => `<li>${r}</li>`).join("");
  $("#modal-body").innerHTML = `
    <h2>${lead.name}</h2>
    <div class="meta-row"><span>Website</span><a href="${lead.website}" target="_blank" rel="noopener">${lead.website}</a></div>
    <div class="meta-row"><span>Stad</span><span>${lead.city || "-"}</span></div>
    <div class="meta-row"><span>Categorie</span><span>${(lead.category || "-").replace(/_/g, " ")}</span></div>
    <div class="meta-row"><span>Adres</span><span>${lead.address || "-"}</span></div>
    <div class="meta-row"><span>Telefoon</span><span>${lead.phone || "-"}</span></div>
    <div class="meta-row"><span>Laatst gescand</span><span>${lead.scanned_at ? new Date(lead.scanned_at).toLocaleString("nl-NL") : "-"}</span></div>
    <h3>Score: ${lead.latest_score ?? "-"} (${lead.latest_priority || "-"})</h3>
    <ul class="reasons-list">${reasons}</ul>
    <button id="rescan-btn">Opnieuw scannen</button>
  `;
  $("#rescan-btn").addEventListener("click", async () => {
    $("#rescan-btn").textContent = "Bezig...";
    await fetch(`/api/leads/${id}/rescan`, { method: "POST" });
    await openDetail(id);
    await loadLeads();
  });
  $("#detail-modal").classList.remove("hidden");
}

$("#modal-close").addEventListener("click", () => $("#detail-modal").classList.add("hidden"));
$("#detail-modal").addEventListener("click", (e) => {
  if (e.target === $("#detail-modal")) $("#detail-modal").classList.add("hidden");
});

$("#refresh-btn").addEventListener("click", (e) => {
  e.preventDefault();
  loadLeads();
});

$("#scan-form").addEventListener("submit", async (e) => {
  e.preventDefault();
  const city = $("#city").value.trim();
  const category = $("#category").value;
  const limit = parseInt($("#limit").value, 10) || 20;

  const res = await fetch("/api/scan/discover", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ city, category, limit }),
  });
  if (!res.ok) {
    const err = await res.json();
    alert(err.detail || "Scan starten mislukt");
    return;
  }
  const job = await res.json();
  pollJob(job.id);
});

function pollJob(jobId) {
  const statusEl = $("#job-status");
  statusEl.classList.remove("hidden");
  statusEl.textContent = "Scan gestart...";
  if (pollTimer) clearInterval(pollTimer);

  pollTimer = setInterval(async () => {
    const res = await fetch(`/api/scan/status/${jobId}`);
    if (!res.ok) return;
    const job = await res.json();
    statusEl.textContent = `Status: ${job.status} — ${job.processed}/${job.total} gescand — ${job.found_leads} kansrijke leads. ${job.message || ""}`;
    if (job.status === "done" || job.status === "error") {
      clearInterval(pollTimer);
      pollTimer = null;
      loadLeads();
    }
  }, 2000);
}

$("#import-form").addEventListener("submit", async (e) => {
  e.preventDefault();
  const fileInput = $("#csv-file");
  if (!fileInput.files.length) return;
  const formData = new FormData();
  formData.append("file", fileInput.files[0]);

  const statusEl = $("#import-status");
  statusEl.classList.remove("hidden");
  statusEl.textContent = "Bezig met importeren en scannen...";

  const res = await fetch("/api/leads/import", { method: "POST", body: formData });
  const data = await res.json();
  statusEl.textContent = res.ok ? `${data.imported} leads geïmporteerd en gescand.` : data.detail || "Import mislukt";
  loadLeads();
});

loadCategories().then(loadLeads);
