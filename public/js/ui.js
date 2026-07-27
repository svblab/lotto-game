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

  function renderRooms(rooms, onJoin) {
    const tbody = $('#rooms-tbody');
    if (!tbody) return;
    tbody.innerHTML = '';
    const t = global.LottoI18n.t;
    rooms.forEach((room) => {
      const tr = document.createElement('tr');
      const canJoin = room.status === 'waiting' && room.players < room.max_players;
      tr.innerHTML = `
        <td>#${room.room_id}</td>
        <td>${room.players}/${room.max_players}</td>
        <td>${t(`status.${room.status}`) || room.status}</td>
        <td>${room.has_password ? '🔒' : '—'}</td>
        <td></td>`;
      if (canJoin) {
        const btn = document.createElement('button');
        btn.className = 'btn small';
        btn.textContent = t('lobby.join');
        btn.onclick = () => onJoin(room);
        tr.lastElementChild.appendChild(btn);
      }
      tbody.appendChild(tr);
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
    $,
    showScreen,
    showToast,
    setMessage,
    toggleOverlay,
    setAuthTab,
    updateLobbyUser,
    renderRooms,
    showRoomPanel,
    renderPlayerList,
    renderGameHeader,
    renderCards,
    renderDrawnHistory,
    setDrawButton,
    setSlotNumbers,
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
