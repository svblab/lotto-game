/**
 * EPIC-12.2 / 12.3 / 12.4 — UI rendering layer
 */
(function (global) {
  'use strict';

  const $ = (sel) => document.querySelector(sel);
  const $$ = (sel) => document.querySelectorAll(sel);

  const screens = {
    auth: '#auth-screen',
    lobby: '#lobby-screen',
    game: '#game-screen',
  };

  function showScreen(name) {
    Object.entries(screens).forEach(([key, sel]) => {
      $(sel)?.classList.toggle('active', key === name);
    });
  }

  function showToast(msg, ms = 3500) {
    const el = $('#toast');
    if (!el) return;
    el.textContent = msg;
    el.classList.remove('hidden');
    clearTimeout(el._timer);
    el._timer = setTimeout(() => el.classList.add('hidden'), ms);
  }

  function setMessage(id, text, type = '') {
    const el = $(id);
    if (!el) return;
    el.textContent = text || '';
    el.className = 'message' + (type ? ` ${type}` : '');
  }

  function toggleOverlay(id, show) {
    $(id)?.classList.toggle('hidden', !show);
  }

  // --- Auth ---
  function setAuthTab(tab) {
    $$('.tab').forEach((b) => b.classList.toggle('active', b.dataset.tab === tab));
    $('#login-form')?.classList.toggle('hidden', tab !== 'login');
    $('#register-form')?.classList.toggle('hidden', tab !== 'register');
  }

  // --- Lobby ---
  function updateLobbyUser(user) {
    $('#lobby-username').textContent = user?.username || '';
    $('#lobby-balance').textContent = user?.coins ?? 0;
    $('#admin-open-btn')?.classList.toggle('hidden', !user?.is_admin);
  }

  function isQuickStartRoom(room) {
    return room.status === 'waiting'
      && !room.has_password
      && room.players < room.max_players;
  }

  /**
   * Pick one eligible quick-start room. Single match → that room; multiple → pseudo-random.
   */
  function pickQuickStartRoom(rooms) {
    const eligible = (rooms || []).filter(isQuickStartRoom);
    if (eligible.length === 0) return null;
    if (eligible.length === 1) return eligible[0];
    return eligible[Math.floor(Math.random() * eligible.length)];
  }

  function updateQuickStartBtn(rooms, inRoom) {
    const enabled = !inRoom && rooms.some(isQuickStartRoom);
    $('#quick-start-btn')?.toggleAttribute('disabled', !enabled);
  }

  function renderRooms(rooms, onJoin, currentRoomId) {
    const tbody = $('#rooms-tbody');
    if (!tbody) return;
    tbody.innerHTML = '';
    const t = global.LottoI18n.t;
    const inRoom = currentRoomId != null;
    rooms.forEach((room) => {
      const tr = document.createElement('tr');
      const isOwnRoom = inRoom && room.room_id === currentRoomId;
      const canJoin = !inRoom
        && room.status === 'waiting'
        && room.players < room.max_players;
      if (isOwnRoom) tr.classList.add('room-row-current');
      tr.innerHTML = `
        <td>#${room.room_id}</td>
        <td>${room.players}/${room.max_players}</td>
        <td>${t(`status.${room.status}`) || room.status}</td>
        <td>${room.has_password ? '🔒' : '—'}</td>
        <td></td>`;
      const actionCell = tr.lastElementChild;
      if (isOwnRoom) {
        const badge = document.createElement('span');
        badge.className = 'room-badge';
        badge.textContent = t('lobby.yourRoom');
        actionCell.appendChild(badge);
      } else if (canJoin) {
        const btn = document.createElement('button');
        btn.className = 'btn small';
        btn.textContent = t('lobby.join');
        btn.onclick = () => onJoin(room);
        actionCell.appendChild(btn);
      }
      tbody.appendChild(tr);
    });
    updateQuickStartBtn(rooms, inRoom);
  }

  function updateLobbyMembershipUI(inRoom) {
    $('#create-room-btn')?.toggleAttribute('disabled', !!inRoom);
    if (inRoom) {
      $('#quick-start-btn')?.toggleAttribute('disabled', true);
      $('#create-room-panel')?.classList.add('hidden');
      hideJoinRoomModal();
    }
  }

  let joinRoomConfirmHandler = null;

  function showJoinRoomModal(room, onConfirm) {
    joinRoomConfirmHandler = onConfirm;
    $('#join-room-title').textContent = global.LottoI18n.t('lobby.joinRoomTitle', { id: room.room_id });
    const pwdWrap = $('#join-password-wrap');
    const pwdInput = $('#join-room-password');
    if (room.has_password) {
      pwdWrap?.classList.remove('hidden');
      if (pwdInput) pwdInput.value = '';
    } else {
      pwdWrap?.classList.add('hidden');
      if (pwdInput) pwdInput.value = '';
    }
    $$('.card-choice-btn').forEach((btn) => {
      btn.classList.toggle('selected', btn.dataset.cards === '1');
    });
    toggleOverlay('#join-room-modal', true);
    pwdInput?.focus();
  }

  function hideJoinRoomModal() {
    joinRoomConfirmHandler = null;
    toggleOverlay('#join-room-modal', false);
  }

  function getJoinRoomSelection() {
    const selected = $('.card-choice-btn.selected');
    const cards_count = selected?.dataset.cards === '2' ? 2 : 1;
    const password = $('#join-room-password')?.value || '';
    return { cards_count, password };
  }

  function bindJoinRoomModal() {
    $$('.card-choice-btn').forEach((btn) => {
      btn.addEventListener('click', () => {
        $$('.card-choice-btn').forEach((b) => b.classList.remove('selected'));
        btn.classList.add('selected');
      });
    });
    const confirmJoin = () => {
      if (!joinRoomConfirmHandler) return;
      const { cards_count, password } = getJoinRoomSelection();
      const handler = joinRoomConfirmHandler;
      hideJoinRoomModal();
      handler(cards_count, password);
    };
    $('#join-room-submit')?.addEventListener('click', confirmJoin);
    $('#join-room-cancel')?.addEventListener('click', hideJoinRoomModal);
    $('#join-room-close-btn')?.addEventListener('click', hideJoinRoomModal);
    $('#join-room-modal')?.addEventListener('click', (e) => {
      if (e.target?.id === 'join-room-modal') hideJoinRoomModal();
    });
  }

  function showRoomPanel(room, username) {
    const panel = $('#room-panel');
    if (!room) {
      panel?.classList.add('hidden');
      return;
    }
    panel?.classList.remove('hidden');
    $('#room-id-label').textContent = room.room_id;
    $('#room-host-label').textContent = room.host;
    $('#room-bank-label').textContent = room.bank ?? 0;
    const isHost = room.host === username;
    const canStart = isHost && room.status === 'waiting' && (room.players?.length || 0) >= 2;
    $('#start-game-btn')?.classList.toggle('hidden', !canStart);
    const showLobbyTimer = room.status === 'waiting'
      && (room.players?.length || 0) >= 2
      && room.host_timeout_start
      && room.host_timeout_seconds;
    if (showLobbyTimer) {
      startLobbyHostCountdown(room.host_timeout_start, room.host_timeout_seconds);
    } else {
      hideLobbyHostCountdown();
    }
    renderPlayerList('#room-players-list', room.players || [], false);
  }

  function renderPlayerList(selector, players, showChance) {
    const ul = $(selector);
    if (!ul) return;
    const t = global.LottoI18n.t;
    ul.innerHTML = '';
    players.forEach((p) => {
      const li = document.createElement('li');
      let statusCls = 'status-online';
      let statusText = t('game.online');
      if (p.status === 'disconnected') {
        statusCls = 'status-disconnected';
        statusText = t('game.disconnected');
      } else if (p.status === 'removed') {
        statusCls = 'status-removed';
        statusText = t('game.removed');
      }
      let extra = p.cards_count ? ` (${p.cards_count} ${t('lobby.cards')})` : '';
      if (showChance && p.winChance != null) extra += ` — ${p.winChance}%`;
      li.innerHTML = `<span>${p.username}${extra}</span><span class="${statusCls}">${statusText}</span>`;
      ul.appendChild(li);
    });
  }

  // --- Game ---
  function renderGameHeader(bank, drawer, remaining) {
    $('#game-bank').textContent = bank ?? 0;
    const t = global.LottoI18n.t;
    $('#game-drawer-info').textContent = drawer
      ? `${t('game.currentDrawer')}: ${drawer}` : '';
    $('#game-remaining').textContent = remaining != null
      ? `${t('game.remaining')}: ${remaining}` : '';
  }

  function countMarked(masks) {
    let n = 0;
    (masks || []).forEach((card) => {
      card.forEach((row) => row.forEach((cell) => { if (cell) n++; }));
    });
    return n;
  }

  function calcWinChance(masks) {
    const marked = countMarked(masks);
    return Math.round((marked / 15) * 100);
  }

  function renderLottoCard(card, mask, flashNums) {
    const table = document.createElement('table');
    table.className = 'lotto-card';
    table.setAttribute('dir', 'ltr');
    for (let r = 0; r < 3; r++) {
      const tr = document.createElement('tr');
      for (let c = 0; c < 9; c++) {
        const td = document.createElement('td');
        const num = card?.[r]?.[c];
        if (num == null) {
          td.classList.add('empty');
          td.innerHTML = '&nbsp;';
        } else {
          td.textContent = num;
          if (mask?.[r]?.[c]) td.classList.add('marked');
          if (flashNums?.includes(num)) td.classList.add('match-flash');
        }
        tr.appendChild(td);
      }
      table.appendChild(tr);
    }
    return table;
  }

  function renderCards(cards, masks, activeIndex, flashNums) {
    const stack = $('#cards-stack');
    if (!stack) return;
    stack.innerHTML = '';
    const count = cards?.length || 0;
    if (!count) return;
    const idx = Math.min(activeIndex, count - 1);
    cards.forEach((card, i) => {
      const el = renderLottoCard(card, masks?.[i], i === idx ? flashNums : null);
      if (count > 1) {
        el.classList.add('stacked');
        const offset = (i - idx) * 12;
        el.style.zIndex = String(10 - Math.abs(i - idx));
        el.style.transform = `translate(${offset}px, ${Math.abs(i - idx) * 6}px)`;
        el.style.opacity = i === idx ? '1' : '0.55';
        if (i !== idx) el.style.pointerEvents = 'none';
      }
      stack.appendChild(el);
    });
    $('#card-prev')?.classList.toggle('hidden', count <= 1);
    $('#card-next')?.classList.toggle('hidden', count <= 1);
  }

  function renderDrawnHistory(nums) {
    const box = $('#drawn-history');
    if (!box) return;
    box.innerHTML = '';
    (nums || []).forEach((n) => {
      const span = document.createElement('span');
      span.className = 'drawn-chip';
      span.textContent = n;
      box.appendChild(span);
    });
  }

  function setDrawButton(enabled, myTurn) {
    const btn = $('#draw-barrel-btn');
    if (!btn) return;
    btn.disabled = !enabled;
    btn.classList.toggle('my-turn', !!myTurn && enabled);
  }

  function hideTurnControls() {
    $('#turn-controls-active')?.classList.add('hidden');
    $('#turn-wait-msg')?.classList.add('hidden');
    hideAfkCountdown();
    setDrawButton(false, false);
  }

  function showActiveTurnControls() {
    $('#turn-wait-msg')?.classList.add('hidden');
    $('#turn-controls-active')?.classList.remove('hidden');
  }

  function showWaitingTurnControls(drawerUsername) {
    hideAfkCountdown();
    setDrawButton(false, false);
    $('#turn-controls-active')?.classList.add('hidden');
    const el = $('#turn-wait-msg');
    if (!el) return;
    const name = drawerUsername || '';
    el.textContent = name
      ? global.LottoI18n.t('game.waitingForPlayer', { player: name })
      : '';
    el.classList.toggle('hidden', !name);
  }

  let serverClockSkew = 0;

  function setServerClockSkew(skewSec) {
    serverClockSkew = Number.isFinite(skewSec) ? skewSec : 0;
  }

  function serverNowSec() {
    return Math.floor(Date.now() / 1000) + serverClockSkew;
  }

  let afkIntervalId = null;
  let afkState = null;
  const AFK_RING_C = 2 * Math.PI * 42;

  function startAfkCountdown(afkStart, turnSeconds, autoDraws) {
    hideAfkCountdown();
    if (!afkStart || !turnSeconds) return;
    afkState = {
      afkStart: Number(afkStart),
      turnSeconds: Number(turnSeconds) || 30,
      autoDraws: Number(autoDraws) || 0,
    };
    $('#afk-timer')?.classList.remove('hidden');
    updateAfkStrikeMarkers(afkState.autoDraws);
    tickAfkCountdown();
    afkIntervalId = setInterval(tickAfkCountdown, 200);
  }

  function syncAfkWarning(pkt) {
    if (pkt.afk_start) {
      if (!afkState) {
        startAfkCountdown(pkt.afk_start, pkt.turn_seconds, pkt.auto_draws);
      } else {
        afkState.afkStart = Number(pkt.afk_start);
        if (pkt.turn_seconds) afkState.turnSeconds = Number(pkt.turn_seconds);
        if (pkt.auto_draws !== undefined) afkState.autoDraws = Number(pkt.auto_draws);
      }
    }
    if (afkState && pkt.strike) {
      updateAfkStrikeMarkers(Number(pkt.strike));
    }
    tickAfkCountdown();
  }

  function tickAfkCountdown() {
    if (!afkState) return;
    const now = serverNowSec();
    const elapsed = now - afkState.afkStart;
    const turnSeconds = afkState.turnSeconds;
    const remaining = Math.max(0, turnSeconds - elapsed);
    const progress = Math.min(1, Math.max(0, elapsed / turnSeconds));

    const numEl = $('#afk-countdown');
    if (numEl) numEl.textContent = String(remaining);

    const ring = $('#afk-ring-progress');
    if (ring) {
      ring.setAttribute('stroke-dashoffset', String(AFK_RING_C * (1 - progress)));
    }

    const wrap = $('#afk-timer');
    wrap?.classList.toggle('phase-danger', remaining <= 5 && remaining > 0);
  }

  function updateAfkStrikeMarkers(activeStrikes) {
    const strikes = Math.min(2, Math.max(0, Number(activeStrikes) || 0));
    $$('.afk-strike').forEach((el) => {
      const n = parseInt(el.dataset.strike, 10);
      el.classList.toggle('active', n <= strikes);
      el.classList.toggle('pending', n > strikes);
    });
  }

  function hideAfkCountdown() {
    if (afkIntervalId) {
      clearInterval(afkIntervalId);
      afkIntervalId = null;
    }
    afkState = null;
    $('#afk-timer')?.classList.add('hidden');
    $('#afk-timer')?.classList.remove('phase-danger');
  }

  let lobbyHostIntervalId = null;
  let lobbyHostState = null;
  const LOBBY_HOST_RING_C = 2 * Math.PI * 42;

  function startLobbyHostCountdown(timeoutStart, timeoutSeconds) {
    hideLobbyHostCountdown();
    if (!timeoutStart || !timeoutSeconds) return;
    lobbyHostState = {
      timeoutStart: Number(timeoutStart),
      timeoutSeconds: Number(timeoutSeconds) || 120,
    };
    $('#lobby-host-timer')?.classList.remove('hidden');
    tickLobbyHostCountdown();
    lobbyHostIntervalId = setInterval(tickLobbyHostCountdown, 200);
  }

  function tickLobbyHostCountdown() {
    if (!lobbyHostState) return;
    const now = serverNowSec();
    const elapsed = now - lobbyHostState.timeoutStart;
    const timeoutSeconds = lobbyHostState.timeoutSeconds;
    const remaining = Math.max(0, timeoutSeconds - elapsed);
    const progress = Math.min(1, Math.max(0, elapsed / timeoutSeconds));

    const numEl = $('#lobby-host-countdown');
    if (numEl) numEl.textContent = String(remaining);

    const ring = $('#lobby-host-ring-progress');
    if (ring) {
      ring.setAttribute('stroke-dashoffset', String(LOBBY_HOST_RING_C * (1 - progress)));
    }

    const wrap = $('#lobby-host-timer');
    wrap?.classList.toggle('phase-danger', remaining <= 15 && remaining > 0);
  }

  function hideLobbyHostCountdown() {
    if (lobbyHostIntervalId) {
      clearInterval(lobbyHostIntervalId);
      lobbyHostIntervalId = null;
    }
    lobbyHostState = null;
    $('#lobby-host-timer')?.classList.add('hidden');
    $('#lobby-host-timer')?.classList.remove('phase-danger');
  }

  const slotSpinTimers = new Map();

  function _slotWindows() {
    return Array.from($$('#slot-machine .slot-window'));
  }

  function _clearSlotTimer(win) {
    const id = slotSpinTimers.get(win);
    if (id) {
      clearInterval(id);
      slotSpinTimers.delete(win);
    }
  }

  function resetSlots() {
    _slotWindows().forEach((win) => {
      _clearSlotTimer(win);
      win.classList.remove('spinning', 'reveal', 'decel');
      const span = win.querySelector('.slot-num');
      if (span) span.textContent = '—';
    });
  }

  function idleSlot(index) {
    const win = _slotWindows()[index];
    if (!win) return;
    _clearSlotTimer(win);
    win.classList.remove('spinning', 'reveal', 'decel');
    const span = win.querySelector('.slot-num');
    if (span) span.textContent = '—';
  }

  function isSlotsSpinning() {
    return slotSpinTimers.size > 0 || _slotWindows().some((w) => w.classList.contains('spinning'));
  }

  function startSlotsWaiting() {
    _slotWindows().forEach((win, index) => {
      _clearSlotTimer(win);
      win.classList.remove('reveal', 'decel');
      win.classList.add('spinning');
      const span = win.querySelector('.slot-num');
      if (!span) return;
      const id = setInterval(() => {
        span.textContent = String(Math.floor(Math.random() * 90) + 1);
      }, 70 + index * 10);
      slotSpinTimers.set(win, id);
    });
  }

  function stopSlotsWaiting() {
    _slotWindows().forEach((win) => {
      _clearSlotTimer(win);
      win.classList.remove('spinning');
    });
  }

  function revealSlot(index, number) {
    return new Promise((resolve) => {
      const win = _slotWindows()[index];
      if (!win) {
        resolve();
        return;
      }
      // Останавливаем только этот барабан; соседние продолжают крутиться.
      _clearSlotTimer(win);
      win.classList.remove('reveal');
      if (!win.classList.contains('spinning')) {
        win.classList.add('spinning');
      }
      win.classList.add('decel');
      const span = win.querySelector('.slot-num');
      if (!span) {
        resolve();
        return;
      }

      let delay = 50;
      let ticks = 0;
      const stopAfter = 12 + index * 4;

      const step = () => {
        ticks += 1;
        if (ticks >= stopAfter) {
          win.classList.remove('spinning', 'decel');
          win.classList.add('reveal');
          span.textContent = String(number);
          setTimeout(() => {
            win.classList.remove('reveal');
            resolve();
          }, 450);
          return;
        }
        span.textContent = String(Math.floor(Math.random() * 90) + 1);
        delay = Math.min(delay + 30, 260);
        setTimeout(step, delay);
      };
      step();
    });
  }

  function setSlotNumbers(nums, animate) {
    const windows = $$('#slot-machine .slot-window');
    windows.forEach((win, i) => {
      const span = win.querySelector('.slot-num');
      win.classList.remove('spinning', 'reveal');
      if (animate && nums[i] == null) {
        win.classList.add('spinning');
        span.textContent = '?';
      } else if (nums[i] != null) {
        span.textContent = nums[i];
        if (animate) win.classList.add('reveal');
      } else {
        span.textContent = '—';
      }
    });
  }

  function renderGamePlayers(players) {
    renderPlayerList('#game-players-list', players, false);
  }

  /** Dark red (0%) → bright blue (100%) for the personal win-chance bar. */
  function winChanceBarColor(percent) {
    const t = Math.max(0, Math.min(100, percent)) / 100;
    const r = Math.round(139 + (0 - 139) * t);
    const g = Math.round(0 + (191 - 0) * t);
    const b = Math.round(0 + (255 - 0) * t);
    return `rgb(${r}, ${g}, ${b})`;
  }

  function updateWinChanceBar(percent) {
    const target = Math.max(0, Math.min(100, Number(percent) || 0));
    const rounded = Math.round(target * 10) / 10;
    const fill = $('#win-chance-fill');
    const label = $('#win-chance-value');
    if (fill) {
      fill.style.width = `${rounded}%`;
      fill.style.backgroundColor = winChanceBarColor(rounded);
    }
    if (label) label.textContent = `${rounded}%`;
  }

  const CHART_LINE_COLORS = ['#00BFFF', '#FF6B6B', '#FFD93D', '#6BCB77', '#C084FC', '#FB923C', '#F472B6', '#38BDF8'];

  function renderWinChanceChart(history) {
    const wrap = $('#game-over-chart-wrap');
    const chartEl = $('#game-over-chart');
    const legend = $('#game-over-chart-legend');
    if (!wrap || !chartEl) return;

    if (!history || history.length < 2) {
      wrap.classList.add('hidden');
      chartEl.innerHTML = '';
      return;
    }
    wrap.classList.remove('hidden');

    const players = [];
    history.forEach((snap) => {
      Object.keys(snap.chances || {}).forEach((u) => {
        if (!players.includes(u)) players.push(u);
      });
    });

    const w = 520;
    const h = 220;
    const pad = { l: 40, r: 12, t: 12, b: 32 };
    const plotW = w - pad.l - pad.r;
    const plotH = h - pad.t - pad.b;
    const maxTurn = history.length - 1;
    const svgNs = 'http://www.w3.org/2000/svg';

    const svg = document.createElementNS(svgNs, 'svg');
    svg.setAttribute('viewBox', `0 0 ${w} ${h}`);
    svg.setAttribute('class', 'win-chance-line-chart');
    svg.setAttribute('aria-hidden', 'true');

    const bg = document.createElementNS(svgNs, 'rect');
    bg.setAttribute('width', String(w));
    bg.setAttribute('height', String(h));
    bg.setAttribute('fill', 'rgba(0,0,0,0.35)');
    svg.appendChild(bg);

    for (let yVal = 0; yVal <= 100; yVal += 25) {
      const py = pad.t + plotH * (1 - yVal / 100);
      const grid = document.createElementNS(svgNs, 'line');
      grid.setAttribute('x1', String(pad.l));
      grid.setAttribute('y1', String(py));
      grid.setAttribute('x2', String(pad.l + plotW));
      grid.setAttribute('y2', String(py));
      grid.setAttribute('stroke', 'rgba(255,255,255,0.12)');
      svg.appendChild(grid);

      const yLabel = document.createElementNS(svgNs, 'text');
      yLabel.setAttribute('x', String(pad.l - 6));
      yLabel.setAttribute('y', String(py + 3));
      yLabel.setAttribute('fill', 'rgba(255,255,255,0.55)');
      yLabel.setAttribute('font-size', '10');
      yLabel.setAttribute('text-anchor', 'end');
      yLabel.textContent = `${yVal}%`;
      svg.appendChild(yLabel);
    }

    history.forEach((_, i) => {
      const px = pad.l + (maxTurn > 0 ? (i / maxTurn) * plotW : plotW / 2);
      const xLabel = document.createElementNS(svgNs, 'text');
      xLabel.setAttribute('x', String(px));
      xLabel.setAttribute('y', String(h - 8));
      xLabel.setAttribute('fill', 'rgba(255,255,255,0.55)');
      xLabel.setAttribute('font-size', '10');
      xLabel.setAttribute('text-anchor', 'middle');
      xLabel.textContent = String(i);
      svg.appendChild(xLabel);
    });

    const turnAxis = (global.LottoI18n && global.LottoI18n.t('game.chartTurn')) || 'Turn';
    const axisLabel = document.createElementNS(svgNs, 'text');
    axisLabel.setAttribute('x', String(pad.l + plotW / 2));
    axisLabel.setAttribute('y', String(h - 2));
    axisLabel.setAttribute('fill', 'rgba(255,255,255,0.45)');
    axisLabel.setAttribute('font-size', '9');
    axisLabel.setAttribute('text-anchor', 'middle');
    axisLabel.textContent = turnAxis;
    svg.appendChild(axisLabel);

    players.forEach((name, pi) => {
      const color = CHART_LINE_COLORS[pi % CHART_LINE_COLORS.length];
      const points = history.map((snap, i) => {
        const val = snap.chances[name] ?? 0;
        const px = pad.l + (maxTurn > 0 ? (i / maxTurn) * plotW : plotW / 2);
        const py = pad.t + plotH * (1 - val / 100);
        return `${px},${py}`;
      }).join(' ');

      const poly = document.createElementNS(svgNs, 'polyline');
      poly.setAttribute('fill', 'none');
      poly.setAttribute('stroke', color);
      poly.setAttribute('stroke-width', '2');
      poly.setAttribute('stroke-linejoin', 'round');
      poly.setAttribute('stroke-linecap', 'round');
      poly.setAttribute('points', points);
      svg.appendChild(poly);

      history.forEach((snap, i) => {
        const val = snap.chances[name] ?? 0;
        const px = pad.l + (maxTurn > 0 ? (i / maxTurn) * plotW : plotW / 2);
        const py = pad.t + plotH * (1 - val / 100);
        const dot = document.createElementNS(svgNs, 'circle');
        dot.setAttribute('cx', String(px));
        dot.setAttribute('cy', String(py));
        dot.setAttribute('r', '3');
        dot.setAttribute('fill', color);
        svg.appendChild(dot);
      });
    });

    chartEl.innerHTML = '';
    chartEl.appendChild(svg);

    if (legend) {
      legend.innerHTML = '';
      players.forEach((name, pi) => {
        const li = document.createElement('li');
        const swatch = document.createElement('span');
        swatch.className = 'swatch';
        swatch.style.background = CHART_LINE_COLORS[pi % CHART_LINE_COLORS.length];
        li.appendChild(swatch);
        li.appendChild(document.createTextNode(name));
        legend.appendChild(li);
      });
    }
  }

  // --- Apartment ---
  function showApartment(required, timeLeft, onAgree, onRefuse, onTimeout) {
    const t = global.LottoI18n.t;
    toggleOverlay('#apartment-modal', true);
    $('#apartment-text').textContent = required
      ? t('apartment.required')
      : t('apartment.immune');
    $('#apartment-actions').classList.toggle('hidden', !required);
    let left = timeLeft || 10;
    $('#apartment-timer').textContent = left;
    clearInterval(global._aptTimer);
    global._aptTimer = setInterval(() => {
      left--;
      $('#apartment-timer').textContent = Math.max(0, left);
      if (left <= 0) {
        clearInterval(global._aptTimer);
        toggleOverlay('#apartment-modal', false);
        if (required) onTimeout?.();
      }
    }, 1000);
    $('#apartment-agree').onclick = () => {
      clearInterval(global._aptTimer);
      toggleOverlay('#apartment-modal', false);
      onAgree?.();
    };
    $('#apartment-refuse').onclick = () => {
      clearInterval(global._aptTimer);
      toggleOverlay('#apartment-modal', false);
      onRefuse?.();
    };
  }

  function hideApartment() {
    clearInterval(global._aptTimer);
    toggleOverlay('#apartment-modal', false);
  }

  // --- Game over ---
  function showGameOver(pkt, options = {}) {
    const t = global.LottoI18n.t;
    toggleOverlay('#game-over-modal', true);
    let reasonText;
    if (pkt.reason === 'no_survivors') {
      reasonText = t('game.noSurvivors');
    } else if (pkt.reason === 'last_survivor') {
      reasonText = t('game.lastSurvivor');
    } else {
      reasonText = t('game.victory');
    }
    if (pkt.reason === 'no_survivors') {
      $('#game-over-text').textContent = t('game.noSurvivorsLine');
    } else {
      const winners = (pkt.statistics || [])
        .filter((s) => (s.received ?? 0) > 0)
        .map((s) => s.username);
      const winnerLabel = winners.length > 0 ? winners.join(', ') : (pkt.winner || '');
      const prizeAmount = winners.length > 1
        ? (pkt.final_bank ?? pkt.prize ?? 0)
        : (pkt.prize ?? 0);
      const lineKey = winners.length > 1 ? 'game.winnersLine' : 'game.winnerLine';
      $('#game-over-text').textContent = t(lineKey, {
        winner: winnerLabel,
        winners: winnerLabel,
        prize: prizeAmount,
        reason: reasonText,
      });
    }
    const receivedLabel = pkt.reason === 'no_survivors' ? t('game.returned') : t('game.received');
    const table = $('#game-over-stats');
    table.innerHTML = `<tr><th>${t('game.player')}</th><th>${t('game.paid')}</th><th>${receivedLabel}</th></tr>`;
    (pkt.statistics || []).forEach((s) => {
      const tr = document.createElement('tr');
      tr.innerHTML = `<td>${s.username}</td><td>${s.paid}</td><td>${s.received}</td>`;
      table.appendChild(tr);
    });
    renderWinChanceChart(options.winChanceHistory || []);
  }

  // --- Admin ---
  function renderAdminRooms(rooms, onClose) {
    const ul = $('#admin-rooms-list');
    if (!ul) return;
    ul.innerHTML = '';
    const t = global.LottoI18n.t;
    rooms.forEach((r) => {
      const li = document.createElement('li');
      li.textContent = `#${r.room_id} — ${r.players}/${r.max_players} (${r.status})`;
      const btn = document.createElement('button');
      btn.className = 'btn small';
      btn.textContent = t('admin.closeRoom');
      btn.onclick = () => onClose(r.room_id);
      li.appendChild(btn);
      ul.appendChild(li);
    });
  }

  function setAdminLogs(lines) {
    const el = $('#admin-logs');
    if (el) el.textContent = (lines || []).join('\n') || '—';
  }

  // --- Rules ---
  function renderRules() {
    const box = $('#rules-content');
    if (!box) return;
    const t = global.LottoI18n.t;
    const sections = ['intro', 'economy', 'cards', 'apartment', 'victory', 'reconnect'];
    box.innerHTML = sections.map((s) => `<h4>${t(`rules.${s}Title`)}</h4><p>${t(`rules.${s}Body`)}</p>`).join('');
  }

  // --- Lang picker ---
  function renderLangPicker(onSelect) {
    const box = $('#lang-options');
    if (!box) return;
    box.innerHTML = '';
    global.LottoI18n.getSupported().forEach((code) => {
      const btn = document.createElement('button');
      btn.className = 'btn';
      btn.textContent = tLangName(code);
      btn.onclick = () => onSelect(code);
      box.appendChild(btn);
    });
  }

  function tLangName(code) {
    const names = { en: 'English', ru: 'Русский', es: 'Español', fr: 'Français', zh: '中文', tr: 'Türkçe' };
    return names[code] || code;
  }

  // --- Reconnect ---
  function showReconnecting(show) {
    toggleOverlay('#reconnect-overlay', show);
  }

  // --- Pure helpers (testable) ---
  function markNumberOnCards(cards, masks, number) {
    const col = number === 90 ? 8 : Math.floor((number - 1) / 10);
    const next = masks.map((cardMask, ci) =>
      cardMask.map((row, ri) =>
        row.map((cell, ci2) => {
          if (cards[ci]?.[ri]?.[ci2] === number) return true;
          return cell;
        })
      )
    );
    return next;
  }

  global.LottoUI = {
    $$,
    $,
    showScreen,
    showToast,
    setMessage,
    toggleOverlay,
    setAuthTab,
    updateLobbyUser,
    renderRooms,
    isQuickStartRoom,
    pickQuickStartRoom,
    updateQuickStartBtn,
    updateLobbyMembershipUI,
    showJoinRoomModal,
    hideJoinRoomModal,
    bindJoinRoomModal,
    showRoomPanel,
    renderPlayerList,
    renderGameHeader,
    renderCards,
    renderDrawnHistory,
    setDrawButton,
    hideTurnControls,
    showActiveTurnControls,
    showWaitingTurnControls,
    startAfkCountdown,
    syncAfkWarning,
    hideAfkCountdown,
    setServerClockSkew,
    startLobbyHostCountdown,
    hideLobbyHostCountdown,
    setSlotNumbers,
    resetSlots,
    startSlotsWaiting,
    stopSlotsWaiting,
    revealSlot,
    idleSlot,
    isSlotsSpinning,
    renderGamePlayers,
    updateWinChanceBar,
    winChanceBarColor,
    showApartment,
    hideApartment,
    showGameOver,
    renderAdminRooms,
    setAdminLogs,
    renderRules,
    renderLangPicker,
    showReconnecting,
    calcWinChance,
    countMarked,
    markNumberOnCards,
  };
})(window);
