(function () {
    if (window.__doctorNotifierLoaded) return;
    window.__doctorNotifierLoaded = true;

    const POLL_URL = '../common/notifications_poll.php';
    const POLL_MS = 8000;
    const SINCE_KEY = 'doctor_notify_since_ms_v1';
    const SEEN_KEY = 'doctor_notify_seen_ids_v1';
    const ENABLE_NOTIFY_SOUND = true;
    const isEhealthDoctorPage = window.location.pathname.includes('/ehealth_center_doctor/');
    const scriptEl = document.currentScript || document.querySelector('script[src*="doctor_notifications.js"]');
    const SOUND_URLS = scriptEl && scriptEl.src
        ? [new URL('notification.wav', scriptEl.src).toString()]
        : ['../assets/notification.wav'];

    let sinceMs = Number(localStorage.getItem(SINCE_KEY) || Date.now());
    if (!Number.isFinite(sinceMs) || sinceMs <= 0) sinceMs = Date.now();

    let seenIds = [];
    try {
        seenIds = JSON.parse(sessionStorage.getItem(SEEN_KEY) || '[]');
        if (!Array.isArray(seenIds)) seenIds = [];
    } catch (_) {
        seenIds = [];
    }

    function rememberSeen(id) {
        if (!id) return;
        seenIds.push(id);
        if (seenIds.length > 100) seenIds = seenIds.slice(-100);
        sessionStorage.setItem(SEEN_KEY, JSON.stringify(seenIds));
    }

    function hasSeen(id) {
        return !!id && seenIds.includes(id);
    }

    function saveSince(ms) {
        sinceMs = Math.max(sinceMs, ms || 0);
        localStorage.setItem(SINCE_KEY, String(sinceMs));
    }

    function injectUi() {
        if (!document.getElementById('doc-notify-style')) {
            const style = document.createElement('style');
            style.id = 'doc-notify-style';
            style.textContent = `
            #doc-notify-wrap {
                position: fixed; top: 18px; right: 18px; z-index: 99999;
                display: flex; flex-direction: column; gap: 10px; width: 340px; max-width: calc(100vw - 24px);
            }
            #doc-notify-wrap.doc-notify-center {
                inset: 0; top: 0; right: 0; bottom: 0; left: 0;
                width: auto; max-width: none; padding: 24px; gap: 16px;
                align-items: center; justify-content: center; overflow: auto;
                background: rgba(15, 23, 42, 0.28);
                backdrop-filter: blur(6px);
                -webkit-backdrop-filter: blur(6px);
            }
            .doc-notify-card {
                background: #ffffff; border: 1px solid #d9e6dc; border-left: 5px solid #2e7d32;
                border-radius: 12px; padding: 12px 12px 10px; box-shadow: 0 10px 24px rgba(0,0,0,0.16);
                font-family: Sora, sans-serif; animation: docNotifyIn .22s ease-out;
            }
            #doc-notify-wrap.doc-notify-center .doc-notify-card {
                width: min(460px, 92vw);
                border-left-width: 6px;
                border-radius: 16px;
                padding: 16px 16px 14px;
                box-shadow: 0 20px 50px rgba(0,0,0,0.28);
            }
            .doc-notify-title { font-size: 13px; font-weight: 800; color: #0f172a; margin-bottom: 3px; }
            .doc-notify-msg { font-size: 12px; color: #475569; line-height: 1.4; margin-bottom: 9px; }
            .doc-notify-row { display: flex; gap: 8px; justify-content: flex-end; }
            .doc-notify-btn {
                border: none; border-radius: 8px; padding: 7px 11px; cursor: pointer;
                font-family: Sora, sans-serif; font-size: 12px; font-weight: 700;
            }
            .doc-notify-open { background: #2e7d32; color: #fff; }
            .doc-notify-close { background: #e2e8f0; color: #334155; }
            @keyframes docNotifyIn { from { opacity: 0; transform: translateY(-8px); } to { opacity: 1; transform: translateY(0); } }
            `;
            document.head.appendChild(style);
        }

        if (!document.getElementById('doc-notify-wrap')) {
            const wrap = document.createElement('div');
            wrap.id = 'doc-notify-wrap';
            if (isEhealthDoctorPage) wrap.classList.add('doc-notify-center');
            document.body.appendChild(wrap);
        }
    }

    function cleanupWrapIfEmpty() {
        const wrap = document.getElementById('doc-notify-wrap');
        if (wrap && wrap.children.length === 0) wrap.remove();
    }

    function showCard(evt) {
        injectUi();
        const wrap = document.getElementById('doc-notify-wrap');
        if (!wrap) return;

        const card = document.createElement('div');
        card.className = 'doc-notify-card';
        card.innerHTML = `
            <div class="doc-notify-title"></div>
            <div class="doc-notify-msg"></div>
            <div class="doc-notify-row">
                <button class="doc-notify-btn doc-notify-close" type="button">Dismiss</button>
                <button class="doc-notify-btn doc-notify-open" type="button">Open</button>
            </div>
        `;
        card.querySelector('.doc-notify-title').textContent = evt.title || 'Notification';
        card.querySelector('.doc-notify-msg').textContent = evt.message || '';

        const closeBtn = card.querySelector('.doc-notify-close');
        const openBtn = card.querySelector('.doc-notify-open');
        closeBtn.addEventListener('click', () => {
            card.remove();
            cleanupWrapIfEmpty();
        });
        openBtn.addEventListener('click', () => {
            if (evt.url) window.location.href = evt.url;
            card.remove();
            cleanupWrapIfEmpty();
        });

        wrap.prepend(card);
        setTimeout(() => {
            card.remove();
            cleanupWrapIfEmpty();
        }, 15000);
    }

    let notifyAudio = null;
    let soundIdx = 0;

    function currentSoundUrl() {
        return SOUND_URLS[Math.min(soundIdx, SOUND_URLS.length - 1)];
    }

    function getAudio() {
        if (!ENABLE_NOTIFY_SOUND) return null;
        if (notifyAudio) return notifyAudio;
        try {
            notifyAudio = new Audio(currentSoundUrl());
            notifyAudio.preload = 'auto';
            notifyAudio.addEventListener('error', () => {
                if (soundIdx < SOUND_URLS.length - 1) {
                    soundIdx++;
                    notifyAudio = null;
                }
            }, { once: true });
        } catch (_) {
            notifyAudio = null;
        }
        return notifyAudio;
    }

    function unlockAudio() {
        if (!ENABLE_NOTIFY_SOUND) return;
        const audio = getAudio();
        if (!audio) return;
        const playPromise = audio.play();
        if (playPromise && typeof playPromise.then === 'function') {
            playPromise.then(() => {
                audio.pause();
                audio.currentTime = 0;
            }).catch(() => {});
        }
    }

    if (ENABLE_NOTIFY_SOUND) {
        ['click', 'keydown', 'touchstart'].forEach((evt) => {
            window.addEventListener(evt, unlockAudio, { once: true, passive: true });
        });
    }

    function playSound() {
        if (!ENABLE_NOTIFY_SOUND) return;
        const audio = getAudio();
        if (!audio) return;
        try {
            audio.currentTime = 0;
        } catch (_) {}
        const playPromise = audio.play();
        if (playPromise && typeof playPromise.catch === 'function') {
            playPromise.catch(() => {
                if (soundIdx < SOUND_URLS.length - 1) {
                    soundIdx++;
                    notifyAudio = null;
                    const fallback = getAudio();
                    if (!fallback) return;
                    const p2 = fallback.play();
                    if (p2 && typeof p2.catch === 'function') p2.catch(() => {});
                }
            });
        }
    }

    function maybeSystemNotify(evt) {
        if (!('Notification' in window)) return;
        const shouldSystem = document.hidden || !document.hasFocus();
        if (!shouldSystem) return;

        const show = () => {
            if (Notification.permission !== 'granted') return;
            const n = new Notification(evt.title || 'Notification', {
                body: evt.message || '',
                tag: evt.id || undefined,
                silent: true
            });
            n.onclick = () => {
                window.focus();
                if (evt.url) window.location.href = evt.url;
            };
        };

        if (Notification.permission === 'granted') {
            show();
        } else if (Notification.permission === 'default') {
            Notification.requestPermission().then((p) => {
                if (p === 'granted') show();
            });
        }
    }

    async function pollOnce() {
        try {
            const res = await fetch(POLL_URL + '?since=' + encodeURIComponent(String(sinceMs)), {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store'
            });
            if (!res.ok) return;

            const data = await res.json();
            if (!data || !data.success) return;

            const list = Array.isArray(data.events) ? data.events : [];
            for (const evt of list) {
                if (hasSeen(evt.id)) continue;
                rememberSeen(evt.id);
                showCard(evt);
                maybeSystemNotify(evt);
                if (ENABLE_NOTIFY_SOUND) playSound();
                if (evt.timeMs) saveSince(Number(evt.timeMs));
            }

            if (data.serverTimeMs) saveSince(Number(data.serverTimeMs));
        } catch (_) {
            // Keep polling quietly on network/API errors.
        }
    }

    setInterval(pollOnce, POLL_MS);
    setTimeout(pollOnce, 1800);
})();
