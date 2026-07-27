/**
 * EPIC-12.1 — Application bootstrap, state, packet routing, animation queue
 */
(function () {
  'use strict';

  const UI = () => window.LottoUI;
  const I18n = () => window.LottoI18n;
  const WS = () => window.LottoWS;

  const STORAGE_TOKEN = 'lotto_session_token';

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
    immune: false,
  };

  let socket;

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
      drainQueue();
    }
  }

  async function animateBarrelsDrawn(pkt) {
    const nums = pkt.numbers || [];

    UI().setDrawButton(false, false);
    state.drawLocked = true;

    const display = [null, null, null];
    for (let i = 0; i < nums.length; i++) {
      display[i] = nums[i];
      UI().setSlotNumbers(display, true);
      await sleep(500);
      const n = nums[i];
      state.myMasks = UI().markNumberOnCards(state.myCards, state.myMasks, n);
      if (!state.drawnAll.includes(n)) state.drawnAll.push(n);
      UI().renderDrawnHistory(state.drawnAll);
      UI().renderCards(state.myCards, state.myMasks, state.cardIndex, [n]);
      UI().updateWinChance(state.myMasks);
      updatePlayersWinChance();
    }

    state.currentDrawer = pkt.next_drawer;
    state.drawLocked = false;
    UI().renderGameHeader(state.bank, state.currentDrawer, pkt.remaining);
    UI().setDrawButton(state.isMyTurn && !state.drawLocked, state.isMyTurn);

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

  // --- Packet handlers ---
  function onHello(pkt) {
    console.info('Protocol version', pkt.protocol_version);
  }

  function onAuthResult(pkt) {
    if (!pkt.success) return;
    state.user = {
      id: pkt.user_id,
      username: pkt.username,
      coins: pkt.coins,
      is_admin: pkt.is_admin,
    };
    localStorage.setItem(STORAGE_TOKEN, pkt.session_token);
    socket.setSessionToken(pkt.session_token);
    UI().updateLobbyUser(state.user);
    UI().showScreen('lobby');
    UI().setMessage('#auth-message', '');
    socket.sendAction('room_list');
  }

  function onError(pkt) {
    const msg = I18n().translateError(pkt);
    if (state.inGame) UI().showToast(msg);
    else if (UI().$('#lobby-screen')?.classList.contains('active')) UI().setMessage('#lobby-message', msg, 'error');
    else UI().setMessage('#auth-message', msg, 'error');
  }

  function onBanned(pkt) {
    localStorage.removeItem(STORAGE_TOKEN);
    socket.setSessionToken(null);
    socket.disconnect();
    state.user = null;
    state.room = null;
    state.inGame = false;
    const until = pkt.until ? new Date(pkt.until * 1000).toLocaleString() : '';
    UI().showScreen('auth');
    UI().setMessage('#auth-message', I18n().t('auth.banned', { until }), 'error');
  }

  function onRoomList(pkt) {
    state.rooms = pkt.rooms || [];
    UI().renderRooms(state.rooms, promptJoinRoom);
    if (state.user?.is_admin) UI().renderAdminRooms(state.rooms, (id) => {
      socket.sendAction('admin_close_room', { room_id: id });
    });
  }

  function onRoomJoined(pkt) {
    state.room = {
      room_id: pkt.room_id,
      host: pkt.host,
      status: pkt.status,
      bank: pkt.bank,
      players: pkt.players || [],
    };
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
    UI().showRoomPanel(state.room, state.user?.username);
  }

  function onPlayerLeft(pkt) {
    if (pkt.username === state.user?.username) {
      if (['kicked', 'banned', 'admin_close', 'afk', 'refuse'].includes(pkt.reason)) {
        resetToLobby();
        UI().showToast(I18n().t('lobby.leftReason', { reason: pkt.reason }));
      }
      return;
    }
    if (!state.room) return;
    state.room.players = state.room.players.filter((p) => p.username !== pkt.username);
    UI().showRoomPanel(state.room, state.user?.username);
    if (state.inGame) {
      state.players = state.players.filter((p) => p.username !== pkt.username);
      UI().renderGamePlayers(state.players);
    }
  }

  function onGameStarted(pkt) {
    state.inGame = true;
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
      cards_count: p.cards?.length || (p.is_self ? state.myCards.length : 1),
      masks: p.masks,
      winChance: p.is_self ? UI().calcWinChance(state.myMasks) : UI().calcWinChance(p.masks),
    }));

    UI().showScreen('game');
    UI().renderGameHeader(state.bank, state.drawerOrder[0], 90);
    UI().renderDrawnHistory([]);
    UI().renderCards(state.myCards, state.myMasks, 0, null);
    UI().updateWinChance(state.myMasks);
    UI().renderGamePlayers(state.players);
    UI().setSlotNumbers([null, null, null], false);
    UI().setDrawButton(false, false);
    state.currentDrawer = state.drawerOrder[0];
  }

  function onYourTurn() {
    state.isMyTurn = true;
    if (!state.animating && !state.drawLocked) {
      UI().setDrawButton(true, true);
    }
  }

  function onBarrelsDrawn(pkt) {
    state.isMyTurn = false;
    state.nextDrawer = pkt.next_drawer;
    enqueueAnimation(async () => {
      await animateBarrelsDrawn(pkt);
    });
  }

  function onAfkWarning(pkt) {
    UI().showToast(I18n().t('game.afkWarning', { strike: pkt.strike }));
  }

  function onApartmentAlert(pkt) {
    state.immune = !pkt.required;
    enqueueAnimation(async () => {
      await new Promise((resolve) => {
        UI().showApartment(
          pkt.required,
          pkt.time_left || 10,
          () => socket.sendAction('apartment_choice', { choice: 'agree' }),
          () => socket.sendAction('apartment_choice', { choice: 'refuse' }),
          () => socket.sendAction('apartment_choice', { choice: 'refuse' })
        );
        const check = setInterval(() => {
          if (UI().$('#apartment-modal')?.classList.contains('hidden')) {
            clearInterval(check);
            resolve();
          }
        }, 200);
      });
    });
  }

  function onGameOver(pkt) {
    const job = async () => {
      UI().hideApartment();
      UI().showGameOver(pkt);
      if (pkt.statistics) {
        const me = pkt.statistics.find((s) => s.username === state.user?.username);
        if (me) state.user.coins = (state.user.coins || 0) - me.paid + me.received;
        UI().updateLobbyUser(state.user);
      }
    };
    job._type = 'game_over';
    enqueueAnimation(job);
  }

  function onReconnectState(pkt) {
    UI().showReconnecting(false);
    if (pkt.status === 'waiting') {
      state.inGame = false;
      state.room = {
        room_id: pkt.room_id,
        host: state.room?.host || '',
        status: 'waiting',
        bank: pkt.bank || 0,
        players: state.room?.players || [],
      };
      state.myCards = [];
      state.myMasks = [];
      UI().showScreen('lobby');
      UI().showRoomPanel(state.room, state.user?.username);
    } else if (pkt.status === 'playing') {
      state.inGame = true;
      state.bank = pkt.bank || 0;
      state.drawnAll = pkt.drawn_all || [];
      state.myCards = pkt.my_cards || [];
      state.myMasks = pkt.my_masks || (state.myCards.map((c) =>
        c.map((row) => row.map(() => false))
      ));
      UI().showScreen('game');
      UI().renderGameHeader(state.bank, state.currentDrawer, null);
      UI().renderDrawnHistory(state.drawnAll);
      UI().renderCards(state.myCards, state.myMasks, state.cardIndex, null);
      UI().updateWinChance(state.myMasks);
      UI().setDrawButton(false, false);
      UI().showToast(I18n().t('reconnect.restored'));
    }
  }

  function onAdminLogs(pkt) {
    UI().setAdminLogs(pkt.lines);
  }

  function updatePlayersWinChance() {
    state.players = state.players.map((p) => {
      if (p.username === state.user?.username) {
        return { ...p, winChance: UI().calcWinChance(state.myMasks) };
      }
      return p;
    });
    UI().renderGamePlayers(state.players);
  }

  // --- User actions ---
  function promptJoinRoom(room) {
    let password = '';
    if (room.has_password) {
      password = prompt(I18n().t('lobby.enterPassword')) || '';
    }
    const cards = prompt(I18n().t('lobby.cardsPrompt'), '1');
    const cards_count = cards === '2' ? 2 : 1;
    socket.sendAction('join_room', { room_id: room.room_id, password, cards_count });
  }

  function doQuickStart() {
    const open = state.rooms.find((r) =>
      r.status === 'waiting' && !r.has_password && r.players < r.max_players);
    if (open) {
      socket.sendAction('join_room', { room_id: open.room_id, password: '', cards_count: 1 });
    } else {
      socket.sendAction('create_room', { max_players: 10, password: '', cards_count: 1 });
    }
  }

  function resetToLobby() {
    state.inGame = false;
    state.room = null;
    state.myCards = [];
    state.myMasks = [];
    state.players = [];
    state.animationQueue = [];
    state.animating = false;
    UI().toggleOverlay('#game-over-modal', false);
    UI().hideApartment();
    UI().showScreen('lobby');
    UI().showRoomPanel(null);
    socket.sendAction('room_list');
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
      localStorage.removeItem(STORAGE_TOKEN);
      socket.setSessionToken(null);
      socket.disconnect();
      state.user = null;
      UI().showScreen('auth');
      socket.connect();
    });

    UI().$('#refresh-rooms-btn')?.addEventListener('click', () => socket.sendAction('room_list'));
    UI().$('#create-room-btn')?.addEventListener('click', () => {
      UI().$('#create-room-panel')?.classList.remove('hidden');
    });
    UI().$('#create-room-cancel')?.addEventListener('click', () => {
      UI().$('#create-room-panel')?.classList.add('hidden');
    });
    UI().$('#create-room-submit')?.addEventListener('click', () => {
      socket.sendAction('create_room', {
        max_players: parseInt(UI().$('#create-max-players').value, 10) || 10,
        password: UI().$('#create-password').value || '',
        cards_count: parseInt(UI().$('#create-cards-count').value, 10) || 1,
      });
      UI().$('#create-room-panel')?.classList.add('hidden');
    });
    UI().$('#quick-start-btn')?.addEventListener('click', doQuickStart);
    UI().$('#leave-room-btn')?.addEventListener('click', () => {
      socket.sendAction('leave_room');
      state.room = null;
      UI().showRoomPanel(null);
      socket.sendAction('room_list');
    });
    UI().$('#start-game-btn')?.addEventListener('click', () => socket.sendAction('start_game'));

    UI().$('#draw-barrel-btn')?.addEventListener('click', () => {
      if (state.drawLocked || state.animating) return;
      state.drawLocked = true;
      UI().setDrawButton(false, false);
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
    UI().$('#rules-close-btn')?.addEventListener('click', () => UI().toggleOverlay('#rules-modal', false));

    const openLang = () => {
      UI().renderLangPicker(async (code) => {
        await I18n().load(code);
        UI().renderRules();
        UI().toggleOverlay('#lang-picker', false);
      });
      UI().toggleOverlay('#lang-picker', true);
    };
    UI().$('#auth-lang-btn')?.addEventListener('click', openLang);
    UI().$('#lobby-lang-btn')?.addEventListener('click', openLang);
    UI().$('#lang-close')?.addEventListener('click', () => UI().toggleOverlay('#lang-picker', false));

    UI().$('#admin-open-btn')?.addEventListener('click', () => {
      UI().toggleOverlay('#admin-panel', true);
      socket.sendAction('admin_get_logs');
      socket.sendAction('room_list');
    });
    UI().$('#admin-close-btn')?.addEventListener('click', () => UI().toggleOverlay('#admin-panel', false));
    UI().$('#admin-refresh-logs')?.addEventListener('click', () => socket.sendAction('admin_get_logs'));
    UI().$('#admin-ban-btn')?.addEventListener('click', () => {
      const uid = parseInt(UI().$('#admin-user-id').value, 10);
      if (!uid) return;
      socket.sendAction('admin_ban_user', { user_id: uid, duration: UI().$('#admin-ban-duration').value });
    });
    UI().$('#admin-unban-btn')?.addEventListener('click', () => {
      const uid = parseInt(UI().$('#admin-user-id').value, 10);
      if (!uid) return;
      socket.sendAction('admin_unban_user', { user_id: uid });
    });
    UI().$('#admin-kick-btn')?.addEventListener('click', () => {
      const uid = parseInt(UI().$('#admin-user-id').value, 10);
      if (!uid) return;
      socket.sendAction('admin_kick_user', { user_id: uid });
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
      player_left: onPlayerLeft,
      game_started: onGameStarted,
      your_turn: onYourTurn,
      barrels_drawn: onBarrelsDrawn,
      afk_warning: onAfkWarning,
      apartment_alert: onApartmentAlert,
      game_over: onGameOver,
      reconnect_state: onReconnectState,
      admin_logs_data: onAdminLogs,
    };
    Object.entries(handlers).forEach(([type, fn]) => socket.on(type, fn));

    socket.on('reconnecting', () => UI().showReconnecting(true));
    socket.on('open', () => {
      UI().showReconnecting(false);
      const token = localStorage.getItem(STORAGE_TOKEN);
      if (token) {
        socket.setSessionToken(token);
        socket.sendAction('reconnect', { token });
      }
    });
    socket.on('close', () => {
      if (socket.sessionToken && !socket.intentionalClose) {
        UI().showReconnecting(true);
      }
    });
  }

  async function init() {
    await I18n().load(I18n().detectLang());
    bindEvents();
    socket = new WS().LottoSocket();
    wireSocket();
    socket.connect();
    UI().showScreen('auth');
  }

  document.addEventListener('DOMContentLoaded', () => {
    init().catch((e) => console.error('Init failed', e));
  });

  // Test exports
  window.LottoApp = { state, markNumberOnCards: UI().markNumberOnCards, calcWinChance: UI().calcWinChance };
})();
