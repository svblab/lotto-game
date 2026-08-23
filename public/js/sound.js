/**
 * Client-side game sound effects — optional assets in public/audio/.
 * Missing files degrade silently (no thrown errors, no broken game flow).
 */
(function (global) {
  'use strict';

  const STORAGE_SOUND_MUTED = 'lotto_sound_muted';
  const STORAGE_SOUND_VOLUME = 'lotto_sound_volume';
  const DEFAULT_VOLUME = 0.7;

  const FILES = {
    spin: 'audio/spin.mp3',
    reveal: 'audio/reveal.mp3',
    match: 'audio/match.mp3',
    defeat: 'audio/defeat.mp3',
    victory: 'audio/victory.mp3',
    apartment: 'audio/apartment.mp3',
  };

  const NUDGE_FALLBACK_LANG = 'en';
  const NUDGE_LANGS = ['en', 'ru', 'es', 'fr', 'zh', 'tr'];

  /** @type {Record<string, { audio: HTMLAudioElement, ok: boolean }>} */
  const cache = {};
  let muted = false;
  let volume = DEFAULT_VOLUME;

  function loadMutedPreference() {
    try {
      muted = localStorage.getItem(STORAGE_SOUND_MUTED) === '1';
    } catch (_) {
      muted = false;
    }
    return muted;
  }

  function loadVolumePreference() {
    try {
      const stored = localStorage.getItem(STORAGE_SOUND_VOLUME);
      if (stored != null) {
        const pct = parseInt(stored, 10);
        if (!Number.isNaN(pct)) {
          volume = Math.max(0, Math.min(100, pct)) / 100;
        }
      }
    } catch (_) {
      volume = DEFAULT_VOLUME;
    }
    return volume;
  }

  function persistMuted() {
    try {
      localStorage.setItem(STORAGE_SOUND_MUTED, muted ? '1' : '0');
    } catch (_) {}
  }

  function persistVolume() {
    try {
      localStorage.setItem(STORAGE_SOUND_VOLUME, String(Math.round(volume * 100)));
    } catch (_) {}
  }

  function applyVolumeToAudio(audio) {
    if (!audio) return;
    audio.volume = volume;
  }

  function applyMuteToAudio(audio) {
    if (!audio) return;
    audio.muted = muted;
  }

  function applyMuteToAll() {
    Object.keys(cache).forEach((key) => {
      applyMuteToAudio(cache[key]?.audio);
    });
  }

  function updateMuteButton() {
    const btn = document.getElementById('sound-mute-btn');
    if (!btn) return;
    btn.textContent = muted ? '🔇' : '🔊';
    btn.setAttribute('aria-pressed', muted ? 'true' : 'false');
    btn.setAttribute('aria-label', muted ? 'Unmute sound' : 'Mute sound');
    btn.title = muted ? 'Sound off' : 'Sound on';
  }

  function updateVolumeSlider() {
    const slider = document.getElementById('sound-volume-slider');
    if (!slider) return;
    slider.value = String(Math.round(volume * 100));
  }

  function getNudgeLang() {
    const lang = global.LottoI18n?.getLang?.();
    return NUDGE_LANGS.includes(lang) ? lang : NUDGE_FALLBACK_LANG;
  }

  function nudgeCacheKey(lang) {
    return `nudge_${lang}`;
  }

  function nudgeUrl(lang) {
    return `audio/nudge_${lang}.mp3`;
  }

  function preloadEntry(cacheKey, url) {
    if (cache[cacheKey]) return cache[cacheKey];
    const audio = new Audio(url);
    audio.preload = 'auto';
    applyVolumeToAudio(audio);
    applyMuteToAudio(audio);
    const entry = { audio, ok: true };
    audio.addEventListener('error', () => {
      entry.ok = false;
    });
    cache[cacheKey] = entry;
    try {
      audio.load();
    } catch (_) {
      entry.ok = false;
    }
    return entry;
  }

  function preload(key) {
    const url = FILES[key];
    if (!url) return null;
    return preloadEntry(key, url);
  }

  function preloadNudge(lang) {
    return preloadEntry(nudgeCacheKey(lang), nudgeUrl(lang));
  }

  function preloadAll() {
    Object.keys(FILES).forEach((key) => preload(key));
    preloadNudge(NUDGE_FALLBACK_LANG);
  }

  function playOneShot(entry, onFail) {
    if (!entry || !entry.ok) {
      onFail?.();
      return;
    }
    try {
      // One-shot overlay: clone so reveal/match never pause or reset the spin loop element.
      const audio = new Audio(entry.audio.src);
      applyVolumeToAudio(audio);
      applyMuteToAudio(audio);
      audio.currentTime = 0;
      audio.addEventListener('error', () => {
        entry.ok = false;
        onFail?.();
      }, { once: true });
      const promise = audio.play();
      if (promise && typeof promise.catch === 'function') {
        promise.catch(() => {
          entry.ok = false;
          onFail?.();
        });
      }
      audio.addEventListener('ended', () => {
        audio.src = '';
      }, { once: true });
    } catch (_) {
      entry.ok = false;
      onFail?.();
    }
  }

  function playNudgeForLang(lang, allowFallback) {
    const entry = preloadNudge(lang);
    playOneShot(entry, () => {
      if (allowFallback && lang !== NUDGE_FALLBACK_LANG) {
        playNudgeForLang(NUDGE_FALLBACK_LANG, false);
      }
    });
  }

  function play(key) {
    if (muted) return;
    if (key === 'nudge') {
      playNudgeForLang(getNudgeLang(), true);
      return;
    }
    playOneShot(preload(key));
  }

  function startLoop(key) {
    if (muted) return;
    const entry = preload(key);
    if (!entry || !entry.ok) return;
    try {
      const audio = entry.audio;
      applyVolumeToAudio(audio);
      applyMuteToAudio(audio);
      // Already looping this clip — do not restart from 0 (avoids an audible stutter).
      if (audio.loop && !audio.paused) return;
      audio.loop = true;
      if (audio.paused) {
        audio.currentTime = 0;
        const promise = audio.play();
        if (promise && typeof promise.catch === 'function') {
          promise.catch(() => {});
        }
      }
    } catch (_) {}
  }

  function stopLoop(key) {
    const entry = cache[key];
    if (!entry || !entry.audio) return;
    try {
      const audio = entry.audio;
      audio.pause();
      audio.currentTime = 0;
      audio.loop = false;
    } catch (_) {}
  }

  function setVolume(value) {
    const v = Number(value);
    if (!Number.isFinite(v)) return;
    volume = Math.max(0, Math.min(1, v));
    Object.keys(cache).forEach((key) => {
      applyVolumeToAudio(cache[key]?.audio);
    });
    persistVolume();
    updateVolumeSlider();
  }

  function getVolume() {
    return volume;
  }

  function setMuted(value) {
    muted = !!value;
    persistMuted();
    applyMuteToAll();
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

  function bindVolumeSlider() {
    const slider = document.getElementById('sound-volume-slider');
    if (!slider || slider.dataset.soundBound === '1') return;
    slider.dataset.soundBound = '1';
    slider.addEventListener('input', () => {
      setVolume(parseInt(slider.value, 10) / 100);
    });
    updateVolumeSlider();
  }

  function init() {
    loadMutedPreference();
    loadVolumePreference();
    bindMuteButton();
    bindVolumeSlider();
  }

  global.LottoSound = {
    init,
    preloadAll,
    play,
    startLoop,
    stopLoop,
    setVolume,
    getVolume,
    setMuted,
    toggleMuted,
    isMuted,
    STORAGE_SOUND_MUTED,
    STORAGE_SOUND_VOLUME,
  };
})(window);
