/**
 * EPIC-12.1 — Application bootstrap, state, packet routing, animation queue
 */
(function () {
  'use strict';

  const UI = () => window.LottoUI;
  const I18n = () => window.LottoI18n;
  const WS = () => window.LottoWS;

  const STORAGE_TOKEN = 'lotto_session_token';
  const STORAGE_USER = 'lotto_user_profile';
  const STORAGE_ACTIVE = 'lotto_active_ws';
  const STORAGE_TAB_ID = 'lotto_tab_id';
  const STORAGE_OWNER_TAB = 'lotto_active_tab_id';

  const Sound = () => window.LottoSound;

  const state = {
    user: null,
    room: null,
    rooms: [],
    inGame: false,
    myCards: [],
    myMasks: [],
    players: [],
    drawerOrder: [],
    currentDrawer: null,
    drawnAll: [],
    bank: 0,
    cardIndex: 0,
    drawLocked: false,
    animating: false,
    animationQueue: [],
    nextDrawer: null,
    isMyTurn: false,
    nudgedThisTurn: false,
    speedMode: 'slow',
    slotsSpinStartedAt: 0,
    adminReturnScreen: 'lobby',
    immune: false,
    inApartment: false,
    pendingTurnPkt: null,
    turnReadySent: false,
    /** ADR-030: pending outbound file after offer (bytes held client-side until accept). */
    pendingFileOffer: null,
    incomingFileOfferId: null,
    incomingFileOfferMeta: null,
    pendingFileSaveHandle: null,
    pendingFileSaveFallback: false,
  };

  let socket;
  let reconnectGuardTimer = null;
  let supersededReloadTimer = null;

  const SUPERSEDED_RELOAD_MS = 10000;

  function clearReconnectGuard() {
    if (reconnectGuardTimer) {
      clearTimeout(reconnectGuardTimer);
      reconnectGuardTimer = null;
    }
  }

  function startReconnectGuard() {
    clearReconnectGuard();
    ensureUserProfile();
    reconnectGuardTimer = setTimeout(() => {
      reconnectGuardTimer = null;
      if (!shouldAttemptReconnect()) return;
      ensureUserProfile();
      if (state.user) return;
      clearClientSession();
      UI().showReconnecting(false);
      UI().showScreen('auth');
      UI().setMessage('#auth-message', I18n().t('errors.auth_invalid_token'), 'error');
    }, 10000);
  }

  function persistUser(user) {
    if (!user) return;
    localStorage.setItem(STORAGE_USER, JSON.stringify(user));
  }

  function loadPersistedUser() {
    try {
      const raw = localStorage.getItem(STORAGE_USER);
      return raw ? JSON.parse(raw) : null;
    } catch {
      return null;
    }
  }

  function hasPersistedSession() {
    return !!localStorage.getItem(STORAGE_TOKEN);
  }

  function ensureTabId() {
    let tabId = sessionStorage.getItem(STORAGE_TAB_ID);
    if (!tabId) {
      tabId = (globalThis.crypto?.randomUUID?.() || `${Date.now()}-${Math.random()}`);
      sessionStorage.setItem(STORAGE_TAB_ID, tabId);
    }
    return tabId;
  }

  function currentUserId() {
    if (state.user?.id != null) return Number(state.user.id);
    const saved = loadPersistedUser();
    return saved?.id != null ? Number(saved.id) : null;
  }

  /** ADR-031: owner record is JSON {tabId, userId}; legacy bare tab id is not same-account provable. */
  function parseOwnerRecord(raw) {
    if (raw == null || raw === '') return null;
    try {
      const parsed = JSON.parse(raw);
      if (parsed && typeof parsed.tabId === 'string' && parsed.userId != null) {
        return { tabId: parsed.tabId, userId: Number(parsed.userId) };
      }
    } catch {
      // not JSON — may be legacy bare tab id
    }
    if (typeof raw === 'string' && raw.length > 0 && raw[0] !== '{') {
      return { tabId: raw, userId: null };
    }
    return null;
  }

  function readOwnerRecord() {
    return parseOwnerRecord(localStorage.getItem(STORAGE_OWNER_TAB));
  }

  function writeOwnerRecord(tabId, userId) {
    localStorage.setItem(
      STORAGE_OWNER_TAB,
      JSON.stringify({ tabId, userId: Number(userId) })
    );
  }

  function isSessionOwnerTab() {
    const tabId = sessionStorage.getItem(STORAGE_TAB_ID);
    if (!tabId) return false;
    const owner = readOwnerRecord();
    if (!owner || owner.userId == null) return false;
    const myUserId = currentUserId();
    if (myUserId == null) return false;
    return tabId === owner.tabId && myUserId === owner.userId;
  }

  function shouldRelinquishToOtherTab() {
    if (isSessionOwnerTab()) return false;
    const owner = readOwnerRecord();
    const myUserId = currentUserId();
    if (myUserId == null || !owner || owner.userId == null) return false;
    return owner.userId === myUserId;
  }

  function claimSessionOwnership() {
    const tabId = ensureTabId();
    const userId = currentUserId();
    sessionStorage.setItem(STORAGE_ACTIVE, '1');
    if (userId != null) {
      writeOwnerRecord(tabId, userId);
    }
  }

  function markActiveSession() {
    claimSessionOwnership();
  }

  function shouldAttemptReconnect() {
    return hasPersistedSession()
      && sessionStorage.getItem(STORAGE_ACTIVE) === '1'
      && isSessionOwnerTab();
  }

  function relinquishSessionToOtherTab() {
    sessionStorage.removeItem(STORAGE_ACTIVE);
    state.user = null;
    state.room = null;
    state.inGame = false;
    if (socket) {
      socket.setSessionToken(null);
      socket.cancelReconnect?.();
      socket.intentionalClose = true;
      socket.disconnect();
      socket.intentionalClose = false;
      socket.connect();
    }
    UI().showReconnecting(false);
    UI().showScreen('auth');
  }

  function clearStoredSession() {
    if (isSessionOwnerTab()) {
      localStorage.removeItem(STORAGE_OWNER_TAB);
    }
    localStorage.removeItem(STORAGE_TOKEN);
    localStorage.removeItem(STORAGE_USER);
    sessionStorage.removeItem(STORAGE_ACTIVE);
  }

  function clearSupersededReload() {
    if (supersededReloadTimer) {
      clearTimeout(supersededReloadTimer);
      supersededReloadTimer = null;
    }
    UI().hideSessionSupersededOverlay?.();
  }

  function handleSessionSuperseded() {
    clearReconnectGuard();
    clearSupersededReload();
    clearClientSession();
    if (socket) {
      socket.intentionalClose = true;
      socket.disconnect();
    }
    UI().showReconnecting(false);
    UI().showScreen('auth');

    const msg = I18n().t('errors.auth_session_superseded');
    UI().showSessionSupersededOverlay(
      msg,
      SUPERSEDED_RELOAD_MS / 1000,
      (key, params) => I18n().t(key, params),
    );

    supersededReloadTimer = setTimeout(() => {
      supersededReloadTimer = null;
      location.reload();
    }, SUPERSEDED_RELOAD_MS);
  }

  function clearClientSession() {
    clearStoredSession();
    state.user = null;
    state.room = null;
    state.inGame = false;
    if (socket) {
      socket.invalidateSession?.();
    }
  }

  function ensureUserProfile() {
    if (state.user) return state.user;
    const saved = loadPersistedUser();
    if (saved) {
      state.user = saved;
      UI().updateLobbyUser(state.user);
    }
    return state.user;
  }

  function buildInitialWinChances(drawerOrder) {
    const names = drawerOrder || [];
    if (!names.length) return {};
    const base = Math.floor(100 / names.length);
    let extra = 100 - base * names.length;
    const out = {};
    names.forEach((u) => {
      out[u] = base + (extra > 0 ? 1 : 0);
      if (extra > 0) extra--;
    });
    return out;
  }

  function applyMyWinChance(chances) {
    const me = state.user?.username;
    if (me && chances && chances[me] != null) {
      UI().updateWinChanceBar(chances[me]);
    }
  }

  // --- Animation queue (max 3) ---
  function enqueueAnimation(job) {
    if (state.animationQueue.length >= 3) state.animationQueue.shift();
    state.animationQueue.push(job);
    drainQueue();
  }

  async function drainQueue() {
    if (state.animating || !state.animationQueue.length) return;
    state.animating = true;
    const job = state.animationQueue.shift();
    try {
      await job();
    } finally {
      state.animating = false;
      syncTurnUi();
      drainQueue();
    }
  }

  async function animateBarrelsDrawn(pkt) {
    const nums = pkt.numbers || [];
    const fast = state.speedMode === 'fast';

    UI().hideTurnControls();
    state.drawLocked = true;

    // Кнопка уже запустила все 3 барабана; иначе (ход соперника / автоход) — крутим с нуля.
    if (!UI().isSlotsSpinning()) {
      UI().startSlotsWaiting();
      state.slotsSpinStartedAt = Date.now();
      if (!fast) await sleep(300);
    }

    if (fast) {
      // ADR-035: 1.0s spin-up + 0.5s full spin, then L→R stops every 0.5s (~3s total).
      const started = state.slotsSpinStartedAt || Date.now();
      const waitBeforeFirstStop = Math.max(0, 1500 - (Date.now() - started));
      await sleep(waitBeforeFirstStop);

      for (let i = 0; i < 3; i++) {
        if (i < nums.length) {
          const stopStarted = Date.now();
          await UI().revealSlot(i, nums[i], { mode: 'fast' });
          const n = nums[i];
          state.myMasks = UI().markNumberOnCards(state.myCards, state.myMasks, n);
          if (isNumberOnMyCards(state.myCards, n)) Sound()?.play('match');
          if (!state.drawnAll.includes(n)) state.drawnAll.push(n);
          UI().renderDrawnHistory(state.drawnAll, state.myCards, state.myMasks);
          // Fast mode: mark instantly, omit gold pulse (no flashNums).
          UI().renderCards(state.myCards, state.myMasks, state.cardIndex, null);
          const remaining = 500 - (Date.now() - stopStarted);
          if (remaining > 0) await sleep(remaining);
        } else {
          UI().idleSlot(i);
        }
      }
    } else {
      // Slow: stop left-to-right with ~3s spacing (existing decelerating reveal).
      for (let i = 0; i < 3; i++) {
        if (i < nums.length) {
          await UI().revealSlot(i, nums[i]);
          const n = nums[i];
          state.myMasks = UI().markNumberOnCards(state.myCards, state.myMasks, n);
          if (isNumberOnMyCards(state.myCards, n)) Sound()?.play('match');
          if (!state.drawnAll.includes(n)) state.drawnAll.push(n);
          UI().renderDrawnHistory(state.drawnAll, state.myCards, state.myMasks);
          UI().renderCards(state.myCards, state.myMasks, state.cardIndex, [n]);
          await sleep(100);
        } else {
          UI().idleSlot(i);
        }
      }
    }

    state.slotsSpinStartedAt = 0;

    if (pkt.win_chances) {
      applyMyWinChance(pkt.win_chances);
    }

    UI().stopSlotsWaiting();

    state.currentDrawer = pkt.next_drawer;
    state.drawLocked = false;
    if (pkt.bank != null) state.bank = pkt.bank;
    UI().renderGameHeader(state.bank, state.currentDrawer, pkt.remaining);

    if (pkt.is_final) {
      const idx = state.animationQueue.findIndex((j) => j._type === 'game_over');
      if (idx > 0) {
        const [go] = state.animationQueue.splice(idx, 1);
        state.animationQueue.unshift(go);
      }
    }
  }

  function sleep(ms) {
    return new Promise((r) => setTimeout(r, ms));
  }

  function isNumberOnMyCards(cards, n) {
    if (!cards?.length || n == null) return false;
    return cards.some((card) => card.some((row) => row.some((cell) => cell === n)));
  }

  async function playGameOverSound(pkt) {
    if (!Sound() || pkt.reason === 'no_survivors') return;
    const me = pkt.statistics?.find((s) => s.username === state.user?.username);
    if (!me) return;
    if (me.received > 0) {
      await Sound().playAndWait('victory');
    } else if (pkt.reason === 'victory' || pkt.reason === 'bot_win') {
      await Sound().playAndWait('defeat');
    }
  }

  function enterGameAudio() {
    Sound()?.preloadAll();
  }

  // --- Packet handlers ---
  function onHello(pkt) {
    console.info('Protocol version', pkt.protocol_version);
    if (pkt.server_time != null) {
      UI().setServerClockSkew(Number(pkt.server_time) - Math.floor(Date.now() / 1000));
    }
  }

  function onAuthResult(pkt) {
    if (!pkt.success) return;
    clearReconnectGuard();
    state.user = {
      id: pkt.user_id,
      username: pkt.username,
      coins: pkt.coins,
      is_admin: pkt.is_admin,
    };
    persistUser(state.user);
    localStorage.setItem(STORAGE_TOKEN, pkt.session_token);
    markActiveSession();
    socket.setSessionToken(pkt.session_token);
    UI().showReconnecting(false);
    state.room = null;
    state.inGame = false;
    UI().updateLobbyMembershipUI(false);
    UI().showRoomPanel(null);
    UI().updateLobbyUser(state.user);
    UI().showScreen('lobby');
    UI().setMessage('#auth-message', '');
    socket.sendAction('room_list');
  }

  function onError(pkt) {
    if ((pkt.code ?? '') === 'error.auth_invalid_token') {
      const superseded = String(pkt.message ?? '').toLowerCase().includes('superseded');
      if (superseded) {
        handleSessionSuperseded();
        return;
      }
      clearReconnectGuard();
      clearClientSession();
      UI().showReconnecting(false);
      UI().showScreen('auth');
      UI().setMessage('#auth-message', I18n().translateError(pkt), 'error');
      return;
    }
    const msg = I18n().translateError(pkt);
    if ((pkt.code ?? '') === 'error.already_nudged') {
      state.nudgedThisTurn = true;
      syncTurnUi();
      return;
    }
    if (
      UI().$('#admin-password-modal') && !UI().$('#admin-password-modal').classList.contains('hidden')
      && ['error.admin_wrong_current_password', 'error.admin_password_invalid'].includes(pkt.code ?? '')
    ) {
      UI().setMessage('#admin-password-message', msg, 'error');
      return;
    }
    if (state.inGame) UI().showToast(msg);
    else if (UI().$('#admin-screen')?.classList.contains('active')) {
      UI().setMessage('#admin-message', msg, 'error');
    } else if (UI().$('#lobby-screen')?.classList.contains('active')) {
      UI().setMessage('#lobby-message', msg, 'error');
    } else {
      UI().setMessage('#auth-message', msg, 'error');
    }
    if (state.inGame && state.drawLocked) {
      UI().resetSlots();
      state.drawLocked = false;
      syncTurnUi();
    }
  }

  function onBanned(pkt) {
    clearClientSession();
    socket.disconnect();
    const until = pkt.until ? new Date(pkt.until * 1000).toLocaleString() : '';
    UI().showScreen('auth');
    UI().setMessage('#auth-message', I18n().t('auth.banned', { until }), 'error');
  }

  function onRoomList(pkt) {
    state.rooms = pkt.rooms || [];
    UI().renderRooms(state.rooms, promptJoinRoom, state.room?.room_id ?? null);
    if (UI().$('#admin-screen')?.classList.contains('active')) {
      UI().renderAdminRooms(state.rooms);
    }
  }

  function onRoomJoined(pkt) {
    state.speedMode = pkt.speed_mode === 'fast' ? 'fast' : 'slow';
    state.room = {
      room_id: pkt.room_id,
      host: pkt.host,
      status: pkt.status,
      bank: pkt.bank,
      bet_per_card: pkt.bet_per_card,
      has_password: !!pkt.has_password,
      speed_mode: state.speedMode,
      players: pkt.players || [],
      host_timeout_start: pkt.host_timeout_start ?? null,
      host_timeout_seconds: pkt.host_timeout_seconds ?? null,
    };
    UI().clearChatLogs();
    UI().updateLobbyMembershipUI(true);
    UI().renderRooms(state.rooms, promptJoinRoom, state.room.room_id);
    UI().showRoomPanel(state.room, state.user?.username);
    UI().setMessage('#lobby-message', I18n().t('lobby.joined', { id: pkt.room_id }), 'success');
  }

  function onPlayerJoined(pkt) {
    if (!state.room) return;
    state.room.players.push({
      username: pkt.username,
      cards_count: pkt.cards_count,
      status: 'active',
    });
    if (pkt.host_timeout_start) {
      state.room.host_timeout_start = pkt.host_timeout_start;
      state.room.host_timeout_seconds = pkt.host_timeout_seconds ?? state.room.host_timeout_seconds;
    }
    if (pkt.host) {
      state.room.host = pkt.host;
    }
    UI().showRoomPanel(state.room, state.user?.username);
  }

  function onHostChanged(pkt) {
    if (!state.room || state.inGame) return;
    state.room.host = pkt.host;
    state.room.host_timeout_start = pkt.host_timeout_start ?? null;
    state.room.host_timeout_seconds = pkt.host_timeout_seconds ?? null;
    UI().showRoomPanel(state.room, state.user?.username);
  }

  function onPlayerStatusChanged(pkt) {
    if (!state.inGame) return;
    const idx = state.players.findIndex((p) => p.username === pkt.username);
    if (idx === -1) return;
    state.players[idx] = { ...state.players[idx], status: pkt.status };
    UI().renderGamePlayers(state.players);
  }

  function onBankUpdated(pkt) {
    if (pkt.bank == null) return;
    state.bank = pkt.bank;
    if (state.inApartment) {
      state.inApartment = false;
      UI().hideApartment();
    }
    UI().renderGameHeader(state.bank, state.currentDrawer, null);
  }

  function onBalanceUpdated(pkt) {
    if (pkt.coins == null || !state.user) return;
    state.user.coins = pkt.coins;
    persistUser(state.user);
    UI().updateLobbyUser(state.user);
  }

  function onPlayerLeft(pkt) {
    const myUserId = state.user?.id;
    const isSelfRemoval = state.room && (
      (myUserId != null && pkt.user_id != null && pkt.user_id === myUserId)
      || (pkt.username && pkt.username === state.user?.username
        && ['kicked', 'banned', 'admin_close', 'afk', 'refuse', 'leave', 'disconnect'].includes(pkt.reason))
    );
    if (isSelfRemoval) {
      if (['kicked', 'banned', 'admin_close', 'afk', 'refuse', 'leave', 'disconnect'].includes(pkt.reason)) {
        resetToLobby();
        UI().showToast(I18n().t('lobby.leftReason', { reason: pkt.reason }));
      }
      return;
    }
    if (!state.room) return;
    state.room.players = state.room.players.filter((p) => p.username !== pkt.username);
    UI().showRoomPanel(state.room, state.user?.username);
    if (state.inGame) {
      const idx = state.players.findIndex((p) => p.username === pkt.username);
      if (idx !== -1) {
        state.players[idx] = {
          ...state.players[idx],
          status: 'removed',
          reason: pkt.reason ?? null,
        };
      }
      UI().renderGamePlayers(state.players);
    }
  }

  function onGameStarted(pkt) {
    state.inGame = true;
    if (state.room) {
      state.room.status = 'playing';
      state.room.bank = pkt.bank;
    }
    state.bank = pkt.bank;
    state.drawerOrder = pkt.drawer_order || [];
    state.drawnAll = [];
    state.cardIndex = 0;
    state.drawLocked = false;

    const self = (pkt.players || []).find((p) => p.is_self);
    state.myCards = self?.cards || [];
    state.myMasks = self?.masks || [];
    state.players = (pkt.players || []).map((p) => ({
      username: p.username,
      status: 'active',
      cards_count: p.cards_count ?? p.cards?.length ?? p.masks?.length ?? 1,
    }));

    const initialChances = buildInitialWinChances(state.drawerOrder);
    applyMyWinChance(initialChances);

    UI().showScreen('game');
    if (state.room?.has_password) {
      UI().setChatVisible(true);
      UI().refreshChatRecipients(state.players, state.user?.username);
    }
    enterGameAudio();
    UI().renderGameHeader(state.bank, state.drawerOrder[0], 90);
    UI().renderDrawnHistory([], state.myCards, state.myMasks);
    UI().renderCards(state.myCards, state.myMasks, 0, null);
    UI().renderGamePlayers(state.players);
    UI().resetSlots();
    UI().hideTurnControls();
    UI().hideLobbyHostCountdown();
    state.isMyTurn = false;
    state.nudgedThisTurn = false;
    state.pendingTurnPkt = null;
    state.turnReadySent = false;
    state.currentDrawer = state.drawerOrder[0] || null;
    syncTurnUi();
  }

  function syncTurnUi() {
    if (state.inApartment) {
      UI().hideTurnControls();
      return;
    }

    if (state.animating || state.drawLocked) {
      UI().hideTurnControls();
      return;
    }

    if (state.isMyTurn) {
      UI().showActiveTurnControls();
      UI().setDrawButton(true, true);
      UI().setNudgeButton(false, false);

      const pkt = state.pendingTurnPkt;
      if (!pkt) return;

      if (pkt.afk_start) {
        UI().startAfkCountdown(pkt.afk_start, pkt.turn_seconds, pkt.auto_draws ?? 0);
        state.pendingTurnPkt = null;
        state.turnReadySent = false;
        return;
      }

      if (!state.turnReadySent) {
        state.turnReadySent = true;
        socket.sendAction('turn_ready');
      }
      return;
    }

    const drawer = state.currentDrawer || state.nextDrawer;
    if (drawer) {
      UI().showWaitingTurnControls(drawer);
      UI().setNudgeButton(true, !state.nudgedThisTurn);
    } else {
      UI().hideTurnControls();
    }
  }

  function onYourTurn(pkt) {
    state.isMyTurn = true;
    state.pendingTurnPkt = pkt;
    state.turnReadySent = false;
    syncTurnUi();
  }

  function onBarrelsDrawn(pkt) {
    state.isMyTurn = false;
    state.nudgedThisTurn = false;
    state.pendingTurnPkt = null;
    state.turnReadySent = false;
    UI().hideTurnControls();
    state.nextDrawer = pkt.next_drawer;
    enqueueAnimation(async () => {
      await animateBarrelsDrawn(pkt);
    });
  }

  function onAfkWarning(pkt) {
    UI().syncAfkWarning(pkt);
    UI().showToast(I18n().t('game.afkWarning', { strike: pkt.strike }));
  }

  function onNudgeReceived(pkt) {
    UI().showToast(I18n().t('game.nudgedBy', { player: pkt.from || '' }));
    Sound()?.play('nudge');
  }

  function onApartmentAlert(pkt) {
    state.inApartment = true;
    state.immune = !pkt.required;
    // Server apartment timer starts on send — do not queue behind barrel animation.
    UI().showApartment(pkt.required, pkt.time_left || 10, {
      onChoice: (choice) => socket.sendAction('apartment_choice', { choice }),
      onTimeout: () => socket.sendAction('apartment_choice', { choice: 'refuse' }),
      onTimerEnd: () => {
        state.inApartment = false;
        UI().hideApartment();
        syncTurnUi();
      },
    });
  }

  function onGameOver(pkt) {
    const job = async () => {
      UI().hideApartment();
      UI().hideTurnControls();
      UI().renderCards(state.myCards, state.myMasks, state.cardIndex, null);
      await playGameOverSound(pkt);
      UI().showGameOver(pkt, { winChanceHistory: pkt.win_chance_history || [] });
      if (pkt.statistics) {
        const me = pkt.statistics.find((s) => s.username === state.user?.username);
        if (me && state.user && me.coins != null) {
          state.user.coins = me.coins;
          persistUser(state.user);
        }
        UI().updateLobbyUser(state.user);
      }
    };
    job._type = 'game_over';
    enqueueAnimation(job);
  }

  function onReconnectState(pkt) {
    clearReconnectGuard();
    UI().showReconnecting(false);
    markActiveSession();
    ensureUserProfile();
    if (!state.user) {
      clearClientSession();
      UI().showScreen('auth');
      UI().setMessage('#auth-message', I18n().t('errors.auth_invalid_token'), 'error');
      return;
    }
    if (pkt.coins != null && state.user) {
      state.user.coins = pkt.coins;
      persistUser(state.user);
      UI().updateLobbyUser(state.user);
    }
    if (pkt.status === 'waiting') {
      state.inGame = false;
      state.speedMode = pkt.speed_mode === 'fast' ? 'fast' : 'slow';
      state.room = {
        room_id: pkt.room_id,
        host: pkt.host ?? '',
        status: 'waiting',
        bank: pkt.bank || 0,
        bet_per_card: pkt.bet_per_card ?? state.room?.bet_per_card,
        has_password: !!(pkt.has_password ?? state.room?.has_password),
        speed_mode: state.speedMode,
        players: pkt.players || [],
        host_timeout_start: pkt.host_timeout_start ?? null,
        host_timeout_seconds: pkt.host_timeout_seconds ?? null,
      };
      state.myCards = [];
      state.myMasks = [];
      UI().updateLobbyMembershipUI(true);
      UI().renderRooms(state.rooms, promptJoinRoom, state.room.room_id);
      UI().showScreen('lobby');
      UI().showRoomPanel(state.room, state.user?.username);
    } else if (pkt.status === 'playing') {
      state.inGame = true;
      state.speedMode = pkt.speed_mode === 'fast' ? 'fast' : 'slow';
      state.room = {
        room_id: pkt.room_id,
        host: pkt.host ?? state.room?.host ?? '',
        status: 'playing',
        bank: pkt.bank || 0,
        has_password: !!(pkt.has_password ?? state.room?.has_password),
        speed_mode: state.speedMode,
        players: pkt.players || [],
      };
      state.bank = pkt.bank || 0;
      state.drawerOrder = pkt.drawer_order || [];
      state.drawnAll = pkt.drawn_all || [];
      state.myCards = pkt.my_cards || [];
      state.myMasks = pkt.my_masks || (state.myCards.map((c) =>
        c.map((row) => row.map(() => false))
      ));
      state.currentDrawer = pkt.current_drawer || state.drawerOrder[0] || null;
      state.cardIndex = 0;
      UI().showScreen('game');
      if (state.room.has_password) {
        UI().setChatVisible(true);
        UI().refreshChatRecipients(state.players.length ? state.players : state.room.players, state.user?.username);
      }
      enterGameAudio();
      UI().renderGameHeader(state.bank, state.currentDrawer, null);
      UI().renderDrawnHistory(state.drawnAll, state.myCards, state.myMasks);
      UI().renderCards(state.myCards, state.myMasks, state.cardIndex, null);
      state.players = (pkt.players || []).map((p) => ({
        username: p.username,
        status: p.status || 'active',
        cards_count: p.cards_count || 1,
        reason: p.reason ?? null,
      }));
      UI().renderGamePlayers(state.players);
      if (pkt.win_chances) {
        applyMyWinChance(pkt.win_chances);
      }
      if (pkt.is_my_turn) {
        state.isMyTurn = true;
        state.pendingTurnPkt = {
          afk_start: pkt.afk_start,
          turn_seconds: pkt.turn_seconds,
          auto_draws: pkt.auto_draws ?? 0,
        };
        state.turnReadySent = false;
      } else {
        state.isMyTurn = false;
        state.pendingTurnPkt = null;
      }
      syncTurnUi();
      UI().showToast(I18n().t('reconnect.restored'));
    }
  }

  function onAdminLogs(pkt) {
    UI().setAdminLogs(pkt.lines);
  }

  function onAdminStats(pkt) {
    UI().setAdminStats(pkt.online, pkt.memory_mb);
    if (pkt.rooms) {
      UI().renderAdminRooms(pkt.rooms);
    }
  }

  function onAdminSettings(pkt) {
    UI().setAdminSettings(pkt);
    if (adminPendingSettingsSave) {
      UI().setMessage('#admin-settings-message', I18n().t('admin.settingsSaved'), 'success');
      adminPendingSettingsSave = false;
    }
  }

  function onAdminRestartResult(pkt) {
    UI().toggleOverlay('#admin-restart-modal', false);
    if (pkt.success) {
      UI().setMessage('#admin-message', pkt.message || I18n().t('admin.restartStarted'), 'success');
      UI().showReconnecting(true);
    } else {
      UI().setMessage('#admin-message', pkt.message || I18n().t('admin.restartFailed'), 'error');
    }
  }

  function onAdminChangePasswordResult(pkt) {
    if (pkt.success) {
      UI().setMessage('#admin-password-message', pkt.message || I18n().t('admin.passwordUpdated'), 'success');
      UI().$('#admin-password-form')?.reset();
      setTimeout(() => UI().toggleOverlay('#admin-password-modal', false), 600);
      UI().setMessage('#admin-settings-message', I18n().t('admin.passwordUpdated'), 'success');
    } else {
      UI().setMessage('#admin-password-message', pkt.message || I18n().t('errors.unknown'), 'error');
    }
  }

  function onAdminUsers(pkt) {
    UI().renderAdminUsersTable(pkt.users || []);
  }

  function openAdminPanel() {
    if (!state.user?.is_admin) return;
    state.adminReturnScreen = state.inGame ? 'game' : 'lobby';
    UI().updateLobbyUser(state.user);
    UI().showScreen('admin');
    UI().setMessage('#admin-message', '');
    UI().setMessage('#admin-settings-message', '');
    refreshAdminData();
  }

  function leaveAdminPanel() {
    UI().showScreen(state.adminReturnScreen === 'game' ? 'game' : 'lobby');
  }

  function refreshAdminData() {
    socket.sendAction('admin_get_settings');
    socket.sendAction('admin_get_logs');
    socket.sendAction('admin_get_stats');
    requestAdminUsers();
  }

  function requestAdminUsers() {
    socket.sendAction('admin_get_users', {
      search: UI().$('#admin-user-search')?.value?.trim() || '',
      online_only: UI().$('#admin-filter-online')?.checked || false,
      banned_only: UI().$('#admin-filter-banned')?.checked || false,
    });
  }

  let adminUserSearchTimer = null;
  let adminPendingSettingsSave = false;

  // --- User actions ---
  function guardAlreadyInRoom() {
    if (!state.room) return false;
    UI().setMessage('#lobby-message', I18n().t('lobby.alreadyInRoom'), 'error');
    return true;
  }

  function promptJoinRoom(room) {
    if (guardAlreadyInRoom()) return;
    if (state.room && room.room_id === state.room.room_id) return;
    if (room.status !== 'waiting') {
      UI().setMessage('#lobby-message', I18n().t('lobby.roomNotJoinable'), 'error');
      return;
    }
    UI().showJoinRoomModal(room, (cards_count, password) => {
      socket.sendAction('join_room', { room_id: room.room_id, password, cards_count });
    });
  }

  function doQuickStart() {
    if (guardAlreadyInRoom()) return;
    const open = UI().pickQuickStartRoom(state.rooms);
    if (!open) {
      UI().setMessage('#lobby-message', I18n().t('lobby.noQuickStartRoom'), 'error');
      return;
    }
    UI().showJoinRoomModal(open, (cards_count, password) => {
      socket.sendAction('join_room', { room_id: open.room_id, password, cards_count });
    });
  }

  function resetToLobby() {
    state.inGame = false;
    state.inApartment = false;
    state.room = null;
    state.speedMode = 'slow';
    state.slotsSpinStartedAt = 0;
    state.myCards = [];
    state.myMasks = [];
    state.players = [];
    state.animationQueue = [];
    state.animating = false;
    state.pendingFileOffer = null;
    state.incomingFileOfferId = null;
    UI().resetSlots();
    UI().hideTurnControls();
    UI().hideLobbyHostCountdown();
    UI().toggleOverlay('#game-over-modal', false);
    UI().hideApartment();
    UI().hideFileOfferModal();
    UI().setChatVisible(false);
    UI().updateLobbyMembershipUI(false);
    UI().showScreen('lobby');
    UI().showRoomPanel(null);
    UI().setMessage('#lobby-message', '');
    socket.sendAction('room_list');
  }

  function onRoomMessage(pkt) {
    if (!state.room?.has_password) return;
    UI().appendChatMessage(pkt.from || '?', pkt.text || '');
  }

  function onFileOffer(pkt) {
    if (!state.room?.has_password) return;
    state.incomingFileOfferId = pkt.offer_id;
    state.incomingFileOfferMeta = {
      from: pkt.from || '?',
      filename: pkt.filename || 'file',
      size_bytes: pkt.size_bytes ?? 0,
    };
    UI().showFileOfferModal(pkt.from || '?', pkt.filename || 'file', pkt.size_bytes ?? 0);
  }

  function onFileAccepted(pkt) {
    const pending = state.pendingFileOffer;
    if (!pending || !pkt.offer_id) return;
    socket.sendAction('file_data', {
      offer_id: pkt.offer_id,
      data: pending.data,
    });
    state.pendingFileOffer = null;
    UI().showToast(I18n().t('chat.fileSending'));
  }

  function onFileRejected(pkt) {
    state.pendingFileOffer = null;
    UI().showToast(I18n().t('chat.fileDeclined'));
  }

  async function onFileData(pkt) {
    if (!state.room?.has_password) return;
    const filename = pkt.filename || 'file';
    const data = pkt.data || '';
    const handle = state.pendingFileSaveHandle;
    state.pendingFileSaveHandle = null;
    const useFallback = state.pendingFileSaveFallback;
    state.pendingFileSaveFallback = false;

    if (handle) {
      const saved = await UI().writeFileToHandle(handle, data);
      if (saved) {
        UI().showToast(I18n().t('chat.fileSaved', { filename: UI().sanitizeDownloadName(filename) }));
        return;
      }
    }

    if (useFallback || !handle) {
      if (UI().promptSaveDownload(filename, data)) {
        UI().showToast(I18n().t('chat.fileReceived', { from: pkt.from || '?' }));
        return;
      }
    }

    UI().addForcedDownloadLink(filename, data);
    UI().showToast(I18n().t('chat.fileReceived', { from: pkt.from || '?' }));
  }

  async function acceptIncomingFile() {
    if (!state.incomingFileOfferId) return;
    const offerId = state.incomingFileOfferId;
    const meta = state.incomingFileOfferMeta;
    const safeName = UI().sanitizeDownloadName(meta?.filename || 'file');

    state.pendingFileSaveHandle = null;
    state.pendingFileSaveFallback = false;

    if (typeof window.showSaveFilePicker === 'function') {
      try {
        state.pendingFileSaveHandle = await window.showSaveFilePicker({
          suggestedName: safeName,
        });
      } catch (e) {
        if (e?.name === 'AbortError') return;
        state.pendingFileSaveFallback = true;
      }
    } else {
      state.pendingFileSaveFallback = true;
    }

    socket.sendAction('file_accept', { offer_id: offerId });
    state.incomingFileOfferId = null;
    state.incomingFileOfferMeta = null;
    UI().hideFileOfferModal();
  }

  function onFileOfferExpired() {
    state.pendingFileOffer = null;
    state.incomingFileOfferId = null;
    state.incomingFileOfferMeta = null;
    state.pendingFileSaveHandle = null;
    state.pendingFileSaveFallback = false;
    UI().hideFileOfferModal();
    UI().showToast(I18n().t('chat.fileExpired'));
  }

  function arrayBufferToBase64(buffer) {
    const bytes = new Uint8Array(buffer);
    let binary = '';
    const chunk = 0x8000;
    for (let i = 0; i < bytes.length; i += chunk) {
      binary += String.fromCharCode.apply(null, bytes.subarray(i, i + chunk));
    }
    return btoa(binary);
  }

  function offerSelectedFile(selectId, inputId) {
    if (!state.room?.has_password) return;
    const to = UI().$(selectId)?.value;
    const input = UI().$(inputId);
    const file = input?.files?.[0];
    if (!to) {
      UI().showToast(I18n().t('chat.pickRecipient'));
      return;
    }
    if (!file) {
      UI().showToast(I18n().t('chat.pickFile'));
      return;
    }
    if (file.size < 1 || file.size > 1048576) {
      UI().showToast(I18n().t('chat.fileTooLarge'));
      return;
    }
    const reader = new FileReader();
    reader.onload = () => {
      const data = arrayBufferToBase64(reader.result);
      state.pendingFileOffer = {
        to_username: to,
        filename: file.name,
        size_bytes: file.size,
        data,
      };
      socket.sendAction('file_offer', {
        to_username: to,
        filename: file.name,
        size_bytes: file.size,
      });
      UI().showToast(I18n().t('chat.fileOffered'));
      if (input) input.value = '';
    };
    reader.readAsArrayBuffer(file);
  }

  function sendChatFrom(inputId) {
    if (!state.room?.has_password) return;
    const input = UI().$(inputId);
    const text = (input?.value || '').trim();
    if (!text) return;
    socket.sendAction('room_message', { text });
    if (input) input.value = '';
  }

  function leaveRoom() {
    socket.sendAction('leave_room');
    resetToLobby();
  }

  // --- Wire DOM ---
  function bindEvents() {
    UI().$$('.tab').forEach((tab) => {
      tab.addEventListener('click', () => UI().setAuthTab(tab.dataset.tab));
    });

    UI().$('#login-form')?.addEventListener('submit', (e) => {
      e.preventDefault();
      socket.sendAction('login', {
        username: UI().$('#login-username').value.trim(),
        password: UI().$('#login-password').value,
      });
    });

    UI().$('#register-form')?.addEventListener('submit', (e) => {
      e.preventDefault();
      socket.sendAction('register', {
        username: UI().$('#register-username').value.trim(),
        password: UI().$('#register-password').value,
      });
    });

    UI().$('#logout-btn')?.addEventListener('click', () => {
      clearClientSession();
      socket.disconnect();
      UI().showScreen('auth');
      socket.connect();
    });

    UI().$('#refresh-rooms-btn')?.addEventListener('click', () => socket.sendAction('room_list'));
    UI().$('#create-room-btn')?.addEventListener('click', () => {
      if (guardAlreadyInRoom()) return;
      UI().$('#create-room-panel')?.classList.remove('hidden');
    });
    UI().$('#create-room-cancel')?.addEventListener('click', () => {
      UI().$('#create-room-panel')?.classList.add('hidden');
    });
    UI().$('#create-room-submit')?.addEventListener('click', () => {
      if (guardAlreadyInRoom()) return;
      socket.sendAction('create_room', {
        max_players: parseInt(UI().$('#create-max-players').value, 10) || 10,
        password: UI().$('#create-password').value || '',
        cards_count: parseInt(UI().$('#create-cards-count').value, 10) || 1,
        speed_mode: UI().$('#create-speed-mode')?.value === 'fast' ? 'fast' : 'slow',
      });
      UI().$('#create-room-panel')?.classList.add('hidden');
    });
    UI().$('#quick-start-btn')?.addEventListener('click', doQuickStart);
    UI().$('#leave-room-btn')?.addEventListener('click', leaveRoom);
    UI().$('#leave-game-btn')?.addEventListener('click', leaveRoom);
    UI().$('#start-game-btn')?.addEventListener('click', () => socket.sendAction('start_game'));
    UI().$('#play-vs-bot-btn')?.addEventListener('click', () => {
      if (UI().$('#play-vs-bot-btn')?.disabled) return;
      socket.sendAction('play_vs_bot');
    });

    UI().$('#lobby-chat-form')?.addEventListener('submit', (e) => {
      e.preventDefault();
      sendChatFrom('#lobby-chat-input');
    });
    UI().$('#game-chat-form')?.addEventListener('submit', (e) => {
      e.preventDefault();
      sendChatFrom('#game-chat-input');
    });
    UI().$('#lobby-chat-file-btn')?.addEventListener('click', () => {
      offerSelectedFile('#lobby-chat-file-to', '#lobby-chat-file-input');
    });
    UI().$('#game-chat-file-btn')?.addEventListener('click', () => {
      offerSelectedFile('#game-chat-file-to', '#game-chat-file-input');
    });
    UI().$('#file-offer-accept')?.addEventListener('click', () => {
      acceptIncomingFile();
    });
    UI().$('#file-offer-reject')?.addEventListener('click', () => {
      if (!state.incomingFileOfferId) return;
      socket.sendAction('file_reject', { offer_id: state.incomingFileOfferId });
      state.incomingFileOfferId = null;
      state.incomingFileOfferMeta = null;
      state.pendingFileSaveHandle = null;
      state.pendingFileSaveFallback = false;
      UI().hideFileOfferModal();
    });

    UI().$$('.chat-panel-toggle').forEach((btn) => {
      btn.addEventListener('click', () => UI().toggleChatPanel());
    });

    UI().$('#nudge-turn-btn')?.addEventListener('click', () => {
      if (state.isMyTurn || state.nudgedThisTurn || state.inApartment) return;
      state.nudgedThisTurn = true;
      UI().setNudgeButton(true, false);
      socket.sendAction('nudge_turn');
    });

    UI().$('#draw-barrel-btn')?.addEventListener('click', () => {
      if (state.drawLocked || state.animating) return;
      state.drawLocked = true;
      UI().hideTurnControls();
      UI().startSlotsWaiting();
      state.slotsSpinStartedAt = Date.now();
      socket.sendAction('draw_barrel');
    });

    UI().$('#card-prev')?.addEventListener('click', () => {
      if (state.cardIndex > 0) {
        state.cardIndex--;
        UI().renderCards(state.myCards, state.myMasks, state.cardIndex, null);
      }
    });
    UI().$('#card-next')?.addEventListener('click', () => {
      if (state.cardIndex < state.myCards.length - 1) {
        state.cardIndex++;
        UI().renderCards(state.myCards, state.myMasks, state.cardIndex, null);
      }
    });

    let touchStartX = 0;
    UI().$('#cards-stack')?.addEventListener('touchstart', (e) => {
      touchStartX = e.changedTouches[0].screenX;
    }, { passive: true });
    UI().$('#cards-stack')?.addEventListener('touchend', (e) => {
      const dx = e.changedTouches[0].screenX - touchStartX;
      if (dx > 50) UI().$('#card-prev')?.click();
      if (dx < -50) UI().$('#card-next')?.click();
    }, { passive: true });

    UI().$('#game-over-close')?.addEventListener('click', resetToLobby);

    const openRules = () => { UI().renderRules(); UI().toggleOverlay('#rules-modal', true); };
    UI().$('#rules-btn-auth')?.addEventListener('click', openRules);
    UI().$('#rules-btn-lobby')?.addEventListener('click', openRules);
    UI().$('#rules-btn-game')?.addEventListener('click', openRules);
    UI().$('#rules-close-btn')?.addEventListener('click', () => UI().toggleOverlay('#rules-modal', false));

    const openLang = () => {
      UI().renderLangPicker(async (code) => {
        await I18n().load(code);
        Sound()?.preloadNudgeLangs?.();
        UI().renderRules();
        UI().syncChatToggleButtons();
        UI().toggleOverlay('#lang-picker', false);
      });
      UI().toggleOverlay('#lang-picker', true);
    };
    UI().$('#auth-lang-btn')?.addEventListener('click', openLang);
    UI().$('#lobby-lang-btn')?.addEventListener('click', openLang);
    UI().$('#lang-close')?.addEventListener('click', () => UI().toggleOverlay('#lang-picker', false));

    UI().$('#admin-open-btn')?.addEventListener('click', openAdminPanel);
    UI().$('#admin-open-game-btn')?.addEventListener('click', openAdminPanel);
    UI().$('#admin-back-btn')?.addEventListener('click', leaveAdminPanel);

    UI().$('#admin-settings-form')?.addEventListener('submit', (e) => {
      e.preventDefault();
      adminPendingSettingsSave = true;
      const settings = UI().readAdminSettingsForm();
      socket.sendAction('admin_set_settings', settings);
    });

    UI().$('#admin-restart-btn')?.addEventListener('click', () => {
      if (UI().$('#admin-restart-btn')?.disabled) return;
      UI().toggleOverlay('#admin-restart-modal', true);
    });
    UI().$('#admin-restart-cancel')?.addEventListener('click', () => {
      UI().toggleOverlay('#admin-restart-modal', false);
    });
    UI().$('#admin-restart-confirm')?.addEventListener('click', () => {
      socket.sendAction('admin_restart_server');
    });

    UI().$('#admin-change-password-btn')?.addEventListener('click', () => {
      UI().setMessage('#admin-password-message', '');
      UI().$('#admin-password-form')?.reset();
      UI().toggleOverlay('#admin-password-modal', true);
    });
    UI().$('#admin-password-cancel')?.addEventListener('click', () => {
      UI().toggleOverlay('#admin-password-modal', false);
    });
    UI().$('#admin-password-form')?.addEventListener('submit', (e) => {
      e.preventDefault();
      const current = UI().$('#admin-password-current')?.value || '';
      const next = UI().$('#admin-password-new')?.value || '';
      const confirm = UI().$('#admin-password-confirm')?.value || '';
      if (next !== confirm) {
        UI().setMessage('#admin-password-message', I18n().t('admin.passwordMismatch'), 'error');
        return;
      }
      socket.sendAction('admin_change_password', {
        current_password: current,
        new_password: next,
      });
    });

    UI().$('#admin-refresh-logs')?.addEventListener('click', () => socket.sendAction('admin_get_logs'));
    UI().$('#admin-refresh-rooms')?.addEventListener('click', () => socket.sendAction('admin_get_stats'));
    UI().$('#admin-rooms-select')?.addEventListener('change', (e) => {
      UI().selectAdminRoom(parseInt(e.target.value, 10) || 0);
    });
    UI().$('#admin-close-room-btn')?.addEventListener('click', () => {
      const id = UI().getSelectedAdminRoomId();
      if (!id) return;
      socket.sendAction('admin_close_room', { room_id: id });
    });
    UI().$('#admin-refresh-users')?.addEventListener('click', () => requestAdminUsers());
    UI().$('#admin-users-select')?.addEventListener('change', (e) => {
      UI().selectAdminUser(parseInt(e.target.value, 10) || 0);
    });
    UI().$('#admin-user-search')?.addEventListener('input', () => {
      clearTimeout(adminUserSearchTimer);
      adminUserSearchTimer = setTimeout(() => requestAdminUsers(), 300);
    });
    UI().$('#admin-filter-online')?.addEventListener('change', () => requestAdminUsers());
    UI().$('#admin-filter-banned')?.addEventListener('change', () => requestAdminUsers());
    UI().$('#admin-ban-btn')?.addEventListener('click', () => {
      const uid = UI().getSelectedAdminUserId();
      if (!uid) return;
      socket.sendAction('admin_ban_user', { user_id: uid, duration: UI().$('#admin-ban-duration').value });
      setTimeout(() => requestAdminUsers(), 400);
    });
    UI().$('#admin-unban-btn')?.addEventListener('click', () => {
      const uid = UI().getSelectedAdminUserId();
      if (!uid) return;
      socket.sendAction('admin_unban_user', { user_id: uid });
      setTimeout(() => requestAdminUsers(), 400);
    });
    UI().$('#admin-kick-btn')?.addEventListener('click', () => {
      const uid = UI().getSelectedAdminUserId();
      if (!uid) return;
      socket.sendAction('admin_kick_user', { user_id: uid });
      setTimeout(() => requestAdminUsers(), 400);
    });
    UI().$('#admin-delete-user-btn')?.addEventListener('click', () => {
      const uid = UI().getSelectedAdminUserId();
      if (!uid) return;
      const user = UI().getAdminUsersCache().find((u) => u.id === uid);
      if (!user || user.is_admin) return;
      const label = user.username || String(uid);
      if (!window.confirm(I18n().t('admin.deleteConfirm', { username: label }))) return;
      socket.sendAction('admin_delete_user', { user_id: uid });
      setTimeout(() => requestAdminUsers(), 400);
    });
    UI().$('#admin-bulk-delete-btn')?.addEventListener('click', () => {
      const candidates = UI().getDeletableAdminUsers();
      if (!candidates.length) {
        UI().setMessage('#admin-message', I18n().t('admin.bulkDeleteEmpty'), 'error');
        return;
      }
      UI().$('#admin-bulk-delete-summary').textContent = I18n().t('admin.bulkDeleteSummary', {
        count: candidates.length,
      });
      UI().$('#admin-bulk-delete-list').textContent = candidates
        .map((u) => `${u.id}: ${u.username}`)
        .join('\n');
      UI().toggleOverlay('#admin-bulk-delete-modal', true);
    });
    UI().$('#admin-bulk-delete-cancel')?.addEventListener('click', () => {
      UI().toggleOverlay('#admin-bulk-delete-modal', false);
    });
    UI().$('#admin-bulk-delete-confirm')?.addEventListener('click', () => {
      const candidates = UI().getDeletableAdminUsers();
      if (!candidates.length) {
        UI().toggleOverlay('#admin-bulk-delete-modal', false);
        return;
      }
      socket.sendAction('admin_bulk_delete_users', {
        user_ids: candidates.map((u) => u.id),
      });
      UI().toggleOverlay('#admin-bulk-delete-modal', false);
      setTimeout(() => requestAdminUsers(), 400);
    });

    document.addEventListener('keydown', (e) => {
      if (e.key !== 'F2' || e.repeat) return;
      if (!socket?.sessionToken) return;
      if (!state.inGame) {
        UI().showToast(I18n().t('dev.f2PlayingOnly'));
        return;
      }
      e.preventDefault();
      if (socket.simulateTransportDrop()) {
        UI().showToast(I18n().t('dev.f2Disconnect'));
      }
    });
  }

  function wireSocket() {
    const handlers = {
      hello: onHello,
      auth_result: onAuthResult,
      error: onError,
      banned: onBanned,
      room_list: onRoomList,
      room_joined: onRoomJoined,
      player_joined: onPlayerJoined,
      host_changed: onHostChanged,
      player_status_changed: onPlayerStatusChanged,
      player_left: onPlayerLeft,
      game_started: onGameStarted,
      your_turn: onYourTurn,
      barrels_drawn: onBarrelsDrawn,
      afk_warning: onAfkWarning,
      nudge_received: onNudgeReceived,
      apartment_alert: onApartmentAlert,
      bank_updated: onBankUpdated,
      balance_updated: onBalanceUpdated,
      game_over: onGameOver,
      reconnect_state: onReconnectState,
      admin_logs_data: onAdminLogs,
      admin_stats_data: onAdminStats,
      admin_settings_data: onAdminSettings,
      admin_restart_result: onAdminRestartResult,
      admin_change_password_result: onAdminChangePasswordResult,
      admin_users_data: onAdminUsers,
      room_message: onRoomMessage,
      file_offer: onFileOffer,
      file_accepted: onFileAccepted,
      file_rejected: onFileRejected,
      file_data: onFileData,
      file_offer_expired: onFileOfferExpired,
    };
    Object.entries(handlers).forEach(([type, fn]) => socket.on(type, fn));

    socket.on('transport_error', () => {
      UI().showReconnecting(false);
    });
    socket.on('reconnecting', () => {
      if (shouldAttemptReconnect()) {
        UI().showReconnecting(true);
      }
    });
    socket.on('open', () => {
      if (shouldAttemptReconnect()) {
        ensureUserProfile();
        const token = localStorage.getItem(STORAGE_TOKEN);
        socket.setSessionToken(token);
        UI().showReconnecting(true);
        startReconnectGuard();
        socket.sendAction('reconnect', { token });
        return;
      }
      UI().showReconnecting(false);
      if (!state.user) {
        UI().showScreen('auth');
      }
    });
    socket.on('close', () => {
      if (shouldAttemptReconnect() && socket.sessionToken && !socket.intentionalClose) {
        UI().showReconnecting(true);
      }
    });
  }

  async function init() {
    await I18n().load(I18n().detectLang());
    Sound()?.init();
    UI().bindJoinRoomModal();
    bindEvents();
    window.addEventListener('storage', (e) => {
      if (e.key !== STORAGE_OWNER_TAB && e.key !== STORAGE_TOKEN) return;
      if (e.key === STORAGE_TOKEN && e.newValue === e.oldValue) return;
      if (shouldRelinquishToOtherTab()) {
        relinquishSessionToOtherTab();
      }
    });
    if (shouldAttemptReconnect()) {
      ensureUserProfile();
      UI().showReconnecting(true);
    } else {
      state.user = null;
      state.room = null;
      state.inGame = false;
      UI().showScreen('auth');
    }
    socket = new (WS().LottoSocket)();
    wireSocket();
    socket.connect();
  }

  document.addEventListener('DOMContentLoaded', () => {
    init().catch((e) => console.error('Init failed', e));
  });

  // Test exports
  window.LottoApp = { state, markNumberOnCards: UI().markNumberOnCards, calcWinChance: UI().calcWinChance };
})();
