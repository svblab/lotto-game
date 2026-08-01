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
  }

  function updateLobbyMembershipUI(inRoom) {
    $('#create-room-btn')?.toggleAttribute('disabled', !!inRoom);
    $('#quick-start-btn')?.toggleAttribute('disabled', !!inRoom);
    if (inRoom) {
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
    renderPlayerList('#room-players-list', room.players || [], false);
  }

  function renderPlayerList(selector, players, showChance) {
    const ul = $(selector);
    if (!ul) return;
    const t = global.LottoI18n.t;
    ul.innerHTML = '';
    players.forEach((p) => {
      const li = document.createElement('li');
      const statusCls = p.status === 'disconnected' ? 'status-disconnected' : 'status-online';
      const statusText = p.status === 'disconnected' ? t('game.disconnected') : t('game.online');
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

  let afkIntervalId = null;
  let afkState = null;
  const AFK_RING_C = 2 * Math.PI * 42;

  function startAfkCountdown(afkStart, limits) {
    hideAfkCountdown();
    if (!afkStart || !limits) return;
    afkState = {
      afkStart: Number(afkStart),
      limits: {
        strike1: Number(limits.strike1) || 30,
        strike2: Number(limits.strike2) || 45,
        strike3: Number(limits.strike3) || 50,
      },
      strikes: 0,
    };
    $('#afk-timer')?.classList.remove('hidden');
    updateAfkStrikeMarkers(0);
    tickAfkCountdown();
    afkIntervalId = setInterval(tickAfkCountdown, 200);
  }

  function syncAfkWarning(pkt) {
    if (pkt.afk_start && pkt.afk_limits) {
      if (!afkState) {
        startAfkCountdown(pkt.afk_start, pkt.afk_limits);
      } else {
        afkState.afkStart = Number(pkt.afk_start);
        afkState.limits = pkt.afk_limits;
      }
    }
    if (afkState && pkt.strike) {
      afkState.strikes = Number(pkt.strike);
      updateAfkStrikeMarkers(afkState.strikes);
    }
    tickAfkCountdown();
  }

  function tickAfkCountdown() {
    if (!afkState) return;
    const now = Math.floor(Date.now() / 1000);
    const elapsed = now - afkState.afkStart;
    const { strike1, strike2, strike3 } = afkState.limits;
    const strikes = afkState.strikes;

    let phaseStart = 0;
    let phaseEnd = strike1;
    if (strikes >= 2) {
      phaseStart = strike2;
      phaseEnd = strike3;
    } else if (strikes >= 1) {
      phaseStart = strike1;
      phaseEnd = strike2;
    }

    const remaining = Math.max(0, phaseEnd - elapsed);
    const phaseDuration = Math.max(1, phaseEnd - phaseStart);
    const progress = Math.min(1, Math.max(0, (elapsed - phaseStart) / phaseDuration));

    const numEl = $('#afk-countdown');
    if (numEl) numEl.textContent = String(remaining);

    const ring = $('#afk-ring-progress');
    if (ring) {
      ring.setAttribute('stroke-dashoffset', String(AFK_RING_C * (1 - progress)));
    }

    const wrap = $('#afk-timer');
    wrap?.classList.toggle('phase-danger', strikes >= 2);
  }

  function updateAfkStrikeMarkers(activeStrikes) {
    $$('.afk-strike').forEach((el) => {
      const n = parseInt(el.dataset.strike, 10);
      el.classList.toggle('active', n <= activeStrikes);
      el.classList.toggle('pending', n > activeStrikes);
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
    renderPlayerList('#game-players-list', players, true);
  }

  function updateWinChance(masks) {
    $('#win-chance-value').textContent = `${calcWinChance(masks)}%`;
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
  function showGameOver(pkt) {
    const t = global.LottoI18n.t;
    toggleOverlay('#game-over-modal', true);
    const reason = pkt.reason === 'last_survivor'
      ? t('game.lastSurvivor') : t('game.victory');
    $('#game-over-text').textContent = t('game.winnerLine', {
      winner: pkt.winner,
      prize: pkt.prize,
      reason,
    });
    const table = $('#game-over-stats');
    table.innerHTML = `<tr><th>${t('game.player')}</th><th>${t('game.paid')}</th><th>${t('game.received')}</th></tr>`;
    (pkt.statistics || []).forEach((s) => {
      const tr = document.createElement('tr');
      tr.innerHTML = `<td>${s.username}</td><td>${s.paid}</td><td>${s.received}</td>`;
      table.appendChild(tr);
    });
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
    startAfkCountdown,
    syncAfkWarning,
    hideAfkCountdown,
    setSlotNumbers,
    resetSlots,
    startSlotsWaiting,
    stopSlotsWaiting,
    revealSlot,
    idleSlot,
    isSlotsSpinning,
    renderGamePlayers,
    updateWinChance,
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
