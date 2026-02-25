(function () {
  const cfg = window.MTC_CONFIG || {};
  const $ = (sel) => document.querySelector(sel);

  const app = $("#mtc-app");
  if (!app) return;

  const fileInput = $("#mtc-file");
  const uploadBtn = $("#mtc-upload-btn");
  const resetBtn = $("#mtc-reset-btn");
  const rowsEl = $("#mtc-rows");
  const downloadAll = $("#mtc-download-all");

  function uuidFallback() {
    return "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx".replace(/[xy]/g, (c) => {
      const r = (Math.random() * 16) | 0;
      const v = c === "x" ? r : (r & 0x3) | 0x8;
      return v.toString(16);
    });
  }

  function getBatchId() {
    const key = "mtc_batch_id";
    let v = localStorage.getItem(key);
    if (!v) {
      v =
        window.crypto && window.crypto.randomUUID
          ? window.crypto.randomUUID()
          : uuidFallback();
      localStorage.setItem(key, v);
    }
    return v;
  }

  const batchId = getBatchId();
  const rowMap = new Map(); // jobId -> row DOM
  const maxQueuedUiMs = Number(cfg.maxQueuedUiMs || 6000);

  function waitingLabel(cssClass, text) {
    return `
      <span class="${cssClass}">
        ${text}
        <img
	  style="height: 18px; margin: 2px 0 0 0; padding: 0 0 0 0"
          src="https://www.alexanderpeppe.com/wp-content/uploads/2026/01/icons8-dots-loading.gif"
          alt="waiting"
          class="queued-dots"
        >
      </span>
    `;
  }

  function queuedLabel() {
    return waitingLabel("status-queued", "queued");
  }

  function processingLabel() {
    return waitingLabel("status-processing", "processing");
  }

  function statusToHtml(status, queuedForMs) {
    const s = String(status || "").toLowerCase().trim();
    if (s === "queued") {
      if (queuedForMs > maxQueuedUiMs) {
        return processingLabel();
      }
      return queuedLabel();
    }
    if (s === "processing") return processingLabel();
    return status || "";
  }

  function msUntilNextMidnightLocal() {
    const now = new Date();
    const next = new Date(now);
    next.setHours(24, 0, 0, 0); // next local midnight
    return next.getTime() - now.getTime();
  }

  function expiryCutoffLocalMidnight() {
    // "Expired after the most recent midnight"
    const now = new Date();
    const todayMidnight = new Date(now);
    todayMidnight.setHours(0, 0, 0, 0);
    return todayMidnight.getTime();
  }

  function isExpiredByMidnight(createdAtMs) {
    // If created before today's midnight, it's expired.
    return createdAtMs < expiryCutoffLocalMidnight();
  }

  function makeRow(name, status, jobId) {
    const row = document.createElement("div");
    row.className = "mtc-row";
    row.dataset.jobId = jobId ? String(jobId) : "";

    const c1 = document.createElement("div");
    c1.textContent = name;

    const c2 = document.createElement("div");
    c2.textContent = status;

    const c3 = document.createElement("div");
    c3.innerHTML = "";

    row.appendChild(c1);
    row.appendChild(c2);
    row.appendChild(c3);

    return row;
  }

  function setRow(row, status, downloadUrl, errorText) {
    const cells = row.querySelectorAll("div");
    if (cells[1])
      cells[1].innerHTML =
        status + (errorText ? " — " + String(errorText) : "");
    if (cells[2]) {
      cells[2].innerHTML = downloadUrl
        ? `<a href="${downloadUrl}">Download</a>`
        : "";
    }
  }

  async function postForm(action, formData) {
    formData.append("action", action);
    formData.append("_ajax_nonce", cfg.nonce);

    const resp = await fetch(
      cfg.ajaxUrl + "?action=" + encodeURIComponent(action),
      {
        method: "POST",
        body: formData,
        credentials: "same-origin",
      }
    );

    const json = await resp.json();
    if (!json || !json.success) {
      const msg =
        json && json.data && json.data.message
          ? json.data.message
          : "Request failed.";
      throw new Error(msg);
    }
    return json.data;
  }

  async function uploadOne(file) {
    if (!file) return;

    if (cfg.maxUploadBytes && file.size > cfg.maxUploadBytes) {
      const row = makeRow(file.name, "error", "");
      setRow(row, "error", null, "File too large for limits.");
      rowsEl.appendChild(row);
      return;
    }

    const tempRow = makeRow(file.name, "uploading…", "");
    rowsEl.appendChild(tempRow);

    const fd = new FormData();
    fd.append("batch_id", batchId);
    fd.append("file", file, file.name);

    try {
      const data = await postForm("mtc_upload", fd);
      const jobId = data.job_id;

      tempRow.dataset.jobId = String(jobId);
      tempRow.dataset.createdAt = String(Date.now());
      rowMap.set(jobId, tempRow);

      const statusHtml = statusToHtml(data.status || "queued", 0);
      setRow(tempRow, statusHtml, null, "");

    } catch (e) {
      setRow(tempRow, "error", null, e.message || "Upload failed.");
    }
  }

  let refreshInFlight = false;
  async function refreshStatus() {
    if (refreshInFlight) return;
    refreshInFlight = true;

    const fd = new FormData();
    fd.append("batch_id", batchId);

    try {
      const data = await postForm("mtc_status", fd);

      (data.jobs || []).forEach((j) => {
        let row = rowMap.get(j.job_id);
        if (!row) {
          row = makeRow(j.name, "", j.job_id);
          rowsEl.appendChild(row);
          rowMap.set(j.job_id, row);
        }

        // Ensure createdAt exists
        row.dataset.createdAt = row.dataset.createdAt || String(Date.now());

        const rawCreatedAtMs = parseInt(row.dataset.createdAt, 10);
        const createdAtMs = Number.isFinite(rawCreatedAtMs)
          ? rawCreatedAtMs
          : Date.now();
        const expired = isExpiredByMidnight(createdAtMs);
        const queuedForMs = Math.max(0, Date.now() - createdAtMs);

        if (expired) {
          setRow(
            row,
            'expired <span class="expired-note">(removed at midnight)</span>',
            null,
            ""
          );
          row.classList.add("mtc-expired");
        } else {
          const statusHtml = statusToHtml(j.status, queuedForMs);
          setRow(row, statusHtml, j.download_url, j.error);
          row.classList.remove("mtc-expired");
        }
      });

      if (data.zip_url) {
        downloadAll.href = data.zip_url;
        downloadAll.style.display = "";
      } else {
        downloadAll.style.display = "none";
      }
    } catch (e) {
      // non-fatal
    } finally {
      refreshInFlight = false;
    }
  }

  let pollTimer = null;
  function startPolling() {
    if (pollTimer) return;
    pollTimer = setInterval(refreshStatus, 2000);
  }

  uploadBtn.addEventListener("click", async () => {
    const files = Array.from(fileInput.files || []);
    if (!files.length) return;

    for (const f of files) {
      await uploadOne(f);
    }

    await refreshStatus();
    startPolling();
  });

  resetBtn.addEventListener("click", () => {
    rowsEl.innerHTML = "";
    rowMap.clear();
    downloadAll.style.display = "none";
    localStorage.removeItem("mtc_batch_id");
    location.reload();
  });

  // Re-check right after midnight
  setTimeout(() => {
    refreshStatus();
    // optional: hard reset list at midnight (uncomment if desired)
    // rowsEl.innerHTML = "";
    // rowMap.clear();
  }, msUntilNextMidnightLocal() + 1000);

  refreshStatus();
  startPolling();
})();
