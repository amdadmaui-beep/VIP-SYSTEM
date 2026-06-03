var notificationCtx = null;

function getNotificationCtx() {
    if (!notificationCtx) {
        var Ctor = window.AudioContext || window.webkitAudioContext;
        if (!Ctor) { console.warn('Notification sound: AudioContext not supported'); return null; }
        notificationCtx = new Ctor();
    }
    if (notificationCtx.state === 'suspended') {
        notificationCtx.resume();
    }
    return notificationCtx;
}

function playFallbackBeep() {
    try {
        var sr = 8000, dur = 0.22, ns = Math.floor(sr * dur);
        var buf = new ArrayBuffer(44 + ns);
        var v = new DataView(buf);
        function wS(o, s) { for (var i = 0; i < s.length; i++) v.setUint8(o + i, s.charCodeAt(i)); }
        wS(0, 'RIFF'); v.setUint32(4, 36 + ns, true); wS(8, 'WAVE'); wS(12, 'fmt ');
        v.setUint32(16, 16, true); v.setUint16(20, 1, true); v.setUint16(22, 1, true);
        v.setUint32(24, sr, true); v.setUint32(28, sr, true); v.setUint16(32, 1, true);
        v.setUint16(34, 8, true); wS(36, 'data'); v.setUint32(40, ns, true);
        for (var i = 0; i < ns; i++) {
            var t = i / sr, val = Math.sin(2 * Math.PI * 880 * t) * 0.3;
            if (t > dur * 0.7) val *= (1 - (t - dur * 0.7) / (dur * 0.3));
            v.setUint8(44 + i, 128 + Math.round(val * 127));
        }
        var blob = new Blob([buf], {type:'audio/wav'}), url = URL.createObjectURL(blob);
        new Audio(url).play().then(function(){URL.revokeObjectURL(url)}).catch(function(){URL.revokeObjectURL(url)});
    } catch(e) {}
}

function playNotificationSound() {
    // Try Web Audio first
    var ctx = getNotificationCtx();
    if (ctx) {
        try {
            var now = ctx.currentTime;
            [880, 1100].forEach(function(freq, i) {
                var osc = ctx.createOscillator(), gain = ctx.createGain();
                osc.connect(gain); gain.connect(ctx.destination);
                osc.frequency.value = freq; osc.type = 'sine';
                var st = now + i * 0.12;
                gain.gain.setValueAtTime(0.4, st);
                gain.gain.exponentialRampToValueAtTime(0.01, st + 0.15);
                osc.start(st); osc.stop(st + 0.15);
            });
            return;
        } catch(e) {
            console.warn('Notification sound: Web Audio failed, trying fallback', e);
        }
    }
    // Fallback
    playFallbackBeep();
}

// Unlock AudioContext on first user interaction
function unlockNotificationCtx() {
    getNotificationCtx();
    document.removeEventListener('click', unlockNotificationCtx);
    document.removeEventListener('keydown', unlockNotificationCtx);
    document.removeEventListener('touchstart', unlockNotificationCtx);
}
document.addEventListener('click', unlockNotificationCtx);
document.addEventListener('keydown', unlockNotificationCtx);
document.addEventListener('touchstart', unlockNotificationCtx);

function escapeHtml(str) {
    var div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}
