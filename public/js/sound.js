/**
 * Client-side game sound effects — optional assets in public/audio/.
 * Missing files degrade silently (no thrown errors, no broken game flow).
 */
(function (global) {
  'use strict';

  const STORAGE_SOUND_MUTED = 'lotto_sound_muted';

  const FILES = {
    spin: 'audio/spin.mp3',
    reveal: 'audio/reveal.mp3',
    match: 'audio/match.mp3',
    defeat: 'audio/defeat.mp3',
    victory: 'audio/victory.mp3',
    nudge: 'audio/nudge.mp3',
  };

  /** @type {Record<string, { audio: HTMLAudioElement, ok: boolean }>} */
  const cache = {};
  let muted = false;

  function loadMutedPreference() {
    try {
      muted = localStorage.getItem(STORAGE_SOUND_MUTED) === '1';
    } catch (_) {
      muted = false;
    }
    return muted;
  }

  function persistMuted() {
    try {
      localStorage.setItem(STORAGE_SOUND_MUTED, muted ? '1' : '0');
    } catch (_) {}
  }

  function updateMuteButton() {
    const btn = document.getElementById('sound-mute-btn');
    if (!btn) return;
    btn.textContent = muted ? '🔇' : '🔊';
    btn.setAttribute('aria-pressed', muted ? 'true' : 'false');
    btn.setAttribute('aria-label', muted ? 'Unmute sound' : 'Mute sound');
    btn.title = muted ? 'Sound off' : 'Sound on';
  }

  function preload(key) {
    if (cache[key]) return cache[key];
    const url = FILES[key];
    if (!url) return null;
    const audio = new Audio(url);
    audio.preload = 'auto';
    const entry = { audio, ok: true };
    audio.addEventListener('error', () => {
      entry.ok = false;
    });
    cache[key] = entry;
    try {
      audio.load();
    } catch (_) {
      entry.ok = false;
    }
    return entry;
  }

  function preloadAll() {
    Object.keys(FILES).forEach((key) => preload(key));
  }

  function play(key) {
    if (muted) return;
    const entry = preload(key);
    if (!entry || !entry.ok) return;
    try {
      entry.audio.currentTime = 0;
      const promise = entry.audio.play();
      if (promise && typeof promise.catch === 'function') {
        promise.catch(() => {});
      }
    } catch (_) {}
  }

  function setMuted(value) {
    muted = !!value;
    persistMuted();
    updateMuteButton();
  }

  function toggleMuted() {
    setMuted(!muted);
    return muted;
  }

  function isMuted() {
    return muted;
  }

  function bindMuteButton() {
    const btn = document.getElementById('sound-mute-btn');
    if (!btn || btn.dataset.soundBound === '1') return;
    btn.dataset.soundBound = '1';
    btn.addEventListener('click', () => toggleMuted());
    updateMuteButton();
  }

  function init() {
    loadMutedPreference();
    bindMuteButton();
  }

  global.LottoSound = {
    init,
    preloadAll,
    play,
    setMuted,
    toggleMuted,
    isMuted,
    STORAGE_SOUND_MUTED,
  };
})(window);
