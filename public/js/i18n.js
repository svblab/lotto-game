/**
 * EPIC-12.5 — Localization (en, ru, es, fr, zh, tr)
 */
(function (global) {
  'use strict';

  const SUPPORTED = ['en', 'ru', 'es', 'fr', 'zh', 'tr'];
  const STORAGE_KEY = 'lotto_lang';
  let currentLang = 'en';
  let strings = {};

  function detectLang() {
    const saved = localStorage.getItem(STORAGE_KEY);
    if (saved && SUPPORTED.includes(saved)) return saved;
    const nav = (navigator.language || 'en').slice(0, 2).toLowerCase();
    return SUPPORTED.includes(nav) ? nav : 'en';
  }

  async function load(lang) {
    const code = SUPPORTED.includes(lang) ? lang : 'en';
    const res = await fetch(`locales/${code}.json`);
    if (!res.ok) throw new Error(`Locale ${code} not found`);
    strings = await res.json();
    currentLang = code;
    localStorage.setItem(STORAGE_KEY, code);
    document.documentElement.lang = code;
    applyDom();
    return code;
  }

  function t(key, vars = {}) {
    const parts = key.split('.');
    let node = strings;
    for (const p of parts) {
      node = node?.[p];
    }
    let text = typeof node === 'string' ? node : key;
    Object.entries(vars).forEach(([k, v]) => {
      text = text.replace(new RegExp(`\\{${k}\\}`, 'g'), String(v));
    });
    return text;
  }

  function translateError(pkt) {
    if (!pkt) return '';
    const code = pkt.code || '';
    if (code.startsWith('error.')) {
      const key = `errors.${code.replace('error.', '')}`;
      const translated = t(key);
      if (translated !== key) return translated;
    }
    return pkt.message || code || t('errors.unknown');
  }

  function applyDom() {
    document.querySelectorAll('[data-i18n]').forEach((el) => {
      const key = el.getAttribute('data-i18n');
      if (key) el.textContent = t(key);
    });
    document.querySelectorAll('[data-i18n-placeholder]').forEach((el) => {
      el.placeholder = t(el.getAttribute('data-i18n-placeholder'));
    });
  }

  function getLang() { return currentLang; }
  function getSupported() { return [...SUPPORTED]; }

  global.LottoI18n = {
    load,
    t,
    translateError,
    applyDom,
    getLang,
    getSupported,
    detectLang,
  };
})(window);
