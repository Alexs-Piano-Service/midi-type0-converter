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
  const pollIntervalMs = Math.max(500, Number(cfg.pollIntervalMs || 2000));
  const pollMaxBackoffMs = Math.max(
    pollIntervalMs,
    Number(cfg.pollMaxBackoffMs || 15000)
  );

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

  let nonceRefreshPromise = null;

  async function refreshNonce() {
    if (nonceRefreshPromise) return nonceRefreshPromise;

    nonceRefreshPromise = (async () => {
      const fd = new FormData();
      fd.append("action", "mtc_refresh_nonce");

      const resp = await fetch(
        cfg.ajaxUrl + "?action=" + encodeURIComponent("mtc_refresh_nonce"),
        {
          method: "POST",
          body: fd,
          credentials: "same-origin",
        }
      );

      const rawText = await resp.text();
      let json = null;
      try {
        json = rawText ? JSON.parse(rawText) : null;
      } catch (_e) {}

      if (
        !resp.ok ||
        !json ||
        !json.success ||
        !json.data ||
        !json.data.nonce
      ) {
        throw new Error("Unable to refresh security token.");
      }

      cfg.nonce = String(json.data.nonce);
      return cfg.nonce;
    })();

    try {
      return await nonceRefreshPromise;
    } finally {
      nonceRefreshPromise = null;
    }
  }

  function isNonceFailure(resp, rawText) {
    if (!resp || Number(resp.status) !== 403) return false;
    return String(rawText || "").trim() === "-1";
  }

  async function postForm(action, formData, options) {
    const opts = options || {};
    const allowNonceRetry = opts.allowNonceRetry !== false;

    formData.set("action", action);
    formData.set("_ajax_nonce", cfg.nonce || "");

    const resp = await fetch(
      cfg.ajaxUrl + "?action=" + encodeURIComponent(action),
      {
        method: "POST",
        body: formData,
        credentials: "same-origin",
      }
    );

    const rawText = await resp.text();
    let json = null;
    try {
      json = rawText ? JSON.parse(rawText) : null;
    } catch (_e) {}

    if (allowNonceRetry && isNonceFailure(resp, rawText)) {
      await refreshNonce();
      return postForm(action, formData, { allowNonceRetry: false });
    }

    if (!resp.ok || !json || !json.success) {
      const msg =
        json && json.data && json.data.message
          ? json.data.message
          : String(rawText || "").trim() === "-1"
          ? "Request denied (expired security token). Retrying failed; refresh and try again."
          : `Request failed (${resp.status || "network"}).`;
      const err = new Error(msg);
      err.status = resp.status || 0;
      const retryAfterHeader = resp.headers.get("Retry-After");
      const retryAfterSeconds = parseInt(String(retryAfterHeader || ""), 10);
      if (Number.isFinite(retryAfterSeconds) && retryAfterSeconds > 0) {
        err.retryAfterMs = retryAfterSeconds * 1000;
      }
      throw err;
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
      knownHasActiveJobs = true;

      const statusHtml = statusToHtml(data.status || "queued", 0);
      setRow(tempRow, statusHtml, null, "");

    } catch (e) {
      setRow(tempRow, "error", null, e.message || "Upload failed.");
    }
  }

  let pollTimer = null;
  let pollBackoffMs = 0;
  let isPolling = false;
  let knownHasActiveJobs = false;

  function clearPollTimer() {
    if (!pollTimer) return;
    clearTimeout(pollTimer);
    pollTimer = null;
  }

  function stopPolling() {
    isPolling = false;
    clearPollTimer();
  }

  function scheduleNextPoll() {
    if (!isPolling || refreshInFlight || pollTimer || !knownHasActiveJobs) return;

    const delay = pollBackoffMs > 0 ? pollBackoffMs : pollIntervalMs;
    pollTimer = setTimeout(async () => {
      pollTimer = null;
      await refreshStatus();
      scheduleNextPoll();
    }, delay);
  }

  function startPolling() {
    isPolling = true;
    scheduleNextPoll();
  }

  let refreshInFlight = false;
  async function refreshStatus() {
    if (refreshInFlight) return;
    refreshInFlight = true;

    const fd = new FormData();
    fd.append("batch_id", batchId);

    try {
      const data = await postForm("mtc_status", fd);
      let activeJobCount = 0;

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
        const statusNorm = String(j.status || "").toLowerCase().trim();
        const isActive = statusNorm === "queued" || statusNorm === "processing";

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
          if (isActive) activeJobCount++;
        }
      });

      knownHasActiveJobs = activeJobCount > 0;
      pollBackoffMs = 0;
      if (!knownHasActiveJobs) {
        stopPolling();
      }

      if (data.zip_url) {
        downloadAll.href = data.zip_url;
        downloadAll.style.display = "";
      } else {
        downloadAll.style.display = "none";
      }
    } catch (e) {
      // non-fatal
      if (e && Number(e.status) === 429) {
        const hintedMs =
          Number.isFinite(e.retryAfterMs) && e.retryAfterMs > 0
            ? e.retryAfterMs
            : 0;
        if (hintedMs > 0) {
          pollBackoffMs = Math.min(
            pollMaxBackoffMs,
            Math.max(pollIntervalMs, hintedMs)
          );
        } else {
          const nextBackoff = pollBackoffMs > 0 ? pollBackoffMs * 2 : pollIntervalMs * 2;
          pollBackoffMs = Math.min(pollMaxBackoffMs, nextBackoff);
        }
      }
    } finally {
      refreshInFlight = false;
    }
  }

  uploadBtn.addEventListener("click", async () => {
    const files = Array.from(fileInput.files || []);
    if (!files.length) return;

    for (const f of files) {
      await uploadOne(f);
    }

    knownHasActiveJobs = true;
    startPolling();
    await refreshStatus();
    if (knownHasActiveJobs) startPolling();
  });

  resetBtn.addEventListener("click", () => {
    stopPolling();
    knownHasActiveJobs = false;
    pollBackoffMs = 0;
    rowsEl.innerHTML = "";
    rowMap.clear();
    downloadAll.style.display = "none";
    localStorage.removeItem("mtc_batch_id");
    location.reload();
  });

  // Re-check right after midnight
  setTimeout(() => {
    refreshStatus();
    if (knownHasActiveJobs) startPolling();
    // optional: hard reset list at midnight (uncomment if desired)
    // rowsEl.innerHTML = "";
    // rowMap.clear();
  }, msUntilNextMidnightLocal() + 1000);

  refreshStatus().then(() => {
    if (knownHasActiveJobs) startPolling();
  });
})();
