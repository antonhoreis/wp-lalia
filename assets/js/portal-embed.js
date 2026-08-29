/**
 * /my-lalia/ — parent side of the postMessage bridge with the LALIA portal
 * iframe (lalia-erp portal/src/app/embed.ts is the other half).
 *
 * Messages are `{ type: "lalia-portal", v: 1, action }`, both directions.
 *
 *   frame → page   ready    portal booted; drop the loading overlay
 *                  logout   user logged out inside the portal (its ERP session
 *                           is already revoked) → end the WordPress session too
 *                  reload   portal session expired → reload this page, which
 *                           mints a fresh handoff token if WordPress is still
 *                           logged in (else lands on the login screen)
 *   page → frame   logout   WordPress session ended (seen by the heartbeat) →
 *                           portal revokes its ERP session
 *
 * Every inbound message is checked against the portal origin AND the frame's
 * window; every outbound message targets the portal origin, never "*".
 * Inlined into the page by the PHP template (no enqueue, no theme).
 */
(function () {
  "use strict";

  var cfg = window.LALIA_PORTAL_EMBED || {};
  var frame = document.getElementById("lalia-portal-frame");
  var status = document.getElementById("lalia-portal-status");
  if (!frame || !cfg.portalOrigin) {
    return;
  }

  var MSG_TYPE = "lalia-portal";
  var RELOAD_GUARD_KEY = "lalia-portal-reload-at";
  var RELOAD_GUARD_MS = 30000;
  var LOGOUT_GRACE_MS = 400;
  var stopped = false;

  // ── overlay ──────────────────────────────────────────────────────────────

  function hideStatus() {
    if (status) {
      status.hidden = true;
    }
  }

  function showError(text) {
    if (!status) {
      return;
    }
    var msg = status.querySelector(".lalia-portal-message");
    if (msg) {
      msg.textContent = text;
    }
    status.classList.add("is-error");
    status.hidden = false;
  }

  if (status) {
    var reloadBtn = status.querySelector(".lalia-portal-reload");
    if (reloadBtn) {
      reloadBtn.addEventListener("click", function () {
        window.location.reload();
      });
    }
  }

  // The frame's document loading is the fallback signal for builds of the
  // portal that predate the bridge (no `ready` message).
  frame.addEventListener("load", hideStatus);

  // ── frame → page ─────────────────────────────────────────────────────────

  function postToFrame(action) {
    if (!frame.contentWindow) {
      return;
    }
    frame.contentWindow.postMessage(
      { type: MSG_TYPE, v: 1, action: action },
      cfg.portalOrigin,
    );
  }

  window.addEventListener("message", function (ev) {
    if (ev.origin !== cfg.portalOrigin || ev.source !== frame.contentWindow) {
      return;
    }
    var d = ev.data;
    if (!d || typeof d !== "object" || d.type !== MSG_TYPE) {
      return;
    }
    switch (d.action) {
      case "ready":
        hideStatus();
        break;
      case "logout":
        stopped = true;
        window.location.replace(cfg.logoutUrl);
        break;
      case "reload":
        reenter();
        break;
      default:
        break;
    }
  });

  // Re-mint by reloading. Guarded so a portal that keeps asking (e.g. a
  // token the ERP rejects for a reason a reload cannot fix) cannot spin the
  // page: at most one automatic reload per 30 s, then a manual button.
  function reenter() {
    var now = Date.now();
    var last = 0;
    try {
      last = Number(window.sessionStorage.getItem(RELOAD_GUARD_KEY)) || 0;
    } catch (e) {
      /* storage unavailable */
    }
    if (now - last < RELOAD_GUARD_MS) {
      showError("Your session has ended. Reload to continue.");
      return;
    }
    try {
      window.sessionStorage.setItem(RELOAD_GUARD_KEY, String(now));
    } catch (e) {
      /* storage unavailable */
    }
    window.location.reload();
  }

  // ── page → frame: WordPress-session heartbeat ────────────────────────────

  function heartbeat() {
    if (
      stopped ||
      document.visibilityState !== "visible" ||
      !cfg.heartbeatUrl
    ) {
      return;
    }
    window
      .fetch(cfg.heartbeatUrl, {
        credentials: "same-origin",
        cache: "no-store",
        headers: { Accept: "application/json" },
      })
      .then(function (res) {
        return res.ok ? res.json() : null;
      })
      .then(function (json) {
        if (!json || stopped) {
          return; // transient failure — try again next tick
        }
        var sameUser = !cfg.userId || Number(json.user) === Number(cfg.userId);
        if (json.logged_in && sameUser) {
          return;
        }
        stopped = true;
        postToFrame("logout");
        showError("You have been logged out.");
        window.setTimeout(function () {
          window.location.replace(cfg.loginUrl);
        }, LOGOUT_GRACE_MS);
      })
      .catch(function () {
        /* network blip — ignore */
      });
  }

  window.setInterval(heartbeat, cfg.heartbeatInterval || 60000);
  document.addEventListener("visibilitychange", function () {
    if (document.visibilityState === "visible") {
      heartbeat();
    }
  });
  // Back/forward-cache restore: the WordPress session may have ended since.
  window.addEventListener("pageshow", function (ev) {
    if (ev.persisted) {
      heartbeat();
    }
  });
})();
