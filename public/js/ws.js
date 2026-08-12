/**
 * EPIC-12.0 — WebSocket layer
 * Handles connection, ping, reconnect, and outbound actions.
 */
(function (global) {
  'use strict';

  const PING_INTERVAL_MS = 2500;
  const RECONNECT_DELAY_MS = 1500;

  /**
   * Read deploy-time WS endpoint hints from index.html meta tags (ADR-027).
   * @returns {{ portAttr: string|null, path: string }}
   */
  function readWsDeployConfig() {
    const doc = global.document;
    if (!doc) {
      return { portAttr: null, path: '' };
    }
    const portMeta = doc.querySelector('meta[name="lotto-ws-port"]');
    const pathMeta = doc.querySelector('meta[name="lotto-ws-path"]');
    return {
      portAttr: portMeta ? portMeta.getAttribute('content') : null,
      path: (pathMeta?.getAttribute('content') || '').trim(),
    };
  }

  /**
   * Build WebSocket URL from page origin + deploy meta (ADR-027).
   * HTTP dev default: ws://host:8080. HTTPS behind reverse proxy: wss://host/ws
   * when lotto-ws-port is empty and lotto-ws-path is "/ws".
   */
  function resolveWsUrl(deployConfig) {
    const cfg = deployConfig ?? readWsDeployConfig();
    const host = global.location?.hostname || 'localhost';
    const isHttps = global.location?.protocol === 'https:';
    const proto = isHttps ? 'wss:' : 'ws:';

    let portSuffix = '';
    if (cfg.portAttr !== null && cfg.portAttr !== '') {
      portSuffix = ':' + cfg.portAttr;
    } else if (cfg.portAttr === null && !isHttps) {
      portSuffix = ':8080';
    }

    const path = cfg.path;
    const pathSuffix = path ? (path.startsWith('/') ? path : '/' + path) : '';

    return `${proto}//${host}${portSuffix}${pathSuffix}`;
  }

  class LottoSocket {
    constructor() {
      this.url = resolveWsUrl();
      this.ws = null;
      this.handlers = new Map();
      this.pingTimer = null;
      this.reconnectTimer = null;
      this.sessionToken = null;
      this.sessionInvalidated = false;
      this.intentionalClose = false;
      this.connected = false;
    }

    on(type, fn) {
      if (!this.handlers.has(type)) this.handlers.set(type, []);
      this.handlers.get(type).push(fn);
      return () => this.off(type, fn);
    }

    off(type, fn) {
      const list = this.handlers.get(type);
      if (!list) return;
      const idx = list.indexOf(fn);
      if (idx >= 0) list.splice(idx, 1);
    }

    emit(type, payload) {
      const list = this.handlers.get(type) || [];
      list.forEach((fn) => {
        try { fn(payload); } catch (e) { console.error('Handler error', type, e); }
      });
    }

    connect() {
      this.intentionalClose = false;
      if (this.ws && (this.ws.readyState === WebSocket.OPEN || this.ws.readyState === WebSocket.CONNECTING)) {
        return;
      }
      this.ws = new WebSocket(this.url);
      this.ws.onopen = () => {
        this.connected = true;
        this.emit('open');
        this._startPing();
      };
      this.ws.onmessage = (ev) => this._onMessage(ev.data);
      this.ws.onclose = (ev) => this._onClose(ev);
      this.ws.onerror = () => this.emit('transport_error', { message: 'WebSocket error' });
    }

    disconnect() {
      this.intentionalClose = true;
      this._stopPing();
      clearTimeout(this.reconnectTimer);
      if (this.ws) {
        this.ws.close();
        this.ws = null;
      }
      this.connected = false;
    }

    setSessionToken(token) {
      this.sessionToken = token || null;
      if (token) {
        this.sessionInvalidated = false;
      }
    }

    invalidateSession() {
      this.sessionInvalidated = true;
      this.sessionToken = null;
      this.cancelReconnect();
    }

    cancelReconnect() {
      clearTimeout(this.reconnectTimer);
      this.reconnectTimer = null;
    }

    /**
     * Dev/QA: drop transport without intentionalClose so auto-reconnect runs.
     * Used by manual reconnect test F2 (in-game disconnect within 15s window).
     */
    simulateTransportDrop() {
      if (!this.ws || this.ws.readyState !== WebSocket.OPEN) {
        return false;
      }
      this._stopPing();
      this.ws.close();
      return true;
    }

    sendAction(action, extra = {}) {
      const payload = { action, ...extra };
      this._send(payload);
    }

    _send(obj) {
      if (!this.ws || this.ws.readyState !== WebSocket.OPEN) {
        this.emit('error', { code: 'error.not_connected', message: 'Not connected' });
        return false;
      }
      this.ws.send(JSON.stringify(obj));
      return true;
    }

    _onMessage(raw) {
      let pkt;
      try {
        pkt = JSON.parse(raw);
      } catch {
        this.emit('error', { code: 'error.invalid_json', message: 'Invalid JSON from server' });
        return;
      }
      if (!pkt || !pkt.type) {
        this.emit('error', { code: 'error.invalid_json', message: 'Missing packet type' });
        return;
      }
      this.emit(pkt.type, pkt);
      this.emit('packet', pkt);
    }

    _onClose(ev) {
      this.connected = false;
      this._stopPing();
      this.emit('close', { code: ev.code, reason: ev.reason });
      if (!this.intentionalClose && this.sessionToken && !this.sessionInvalidated) {
        this.emit('reconnecting');
        clearTimeout(this.reconnectTimer);
        this.reconnectTimer = setTimeout(() => this.connect(), RECONNECT_DELAY_MS);
      }
    }

    _startPing() {
      this._stopPing();
      this.pingTimer = setInterval(() => {
        if (this.ws?.readyState === WebSocket.OPEN) {
          this.sendAction('ping');
        }
      }, PING_INTERVAL_MS);
    }

    _stopPing() {
      if (this.pingTimer) {
        clearInterval(this.pingTimer);
        this.pingTimer = null;
      }
    }
  }

  global.LottoWS = {
    LottoSocket,
    readWsDeployConfig,
    resolveWsUrl,
    PING_INTERVAL_MS,
  };
})(window);
