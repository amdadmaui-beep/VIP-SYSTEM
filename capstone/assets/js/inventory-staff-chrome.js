/**
 * Shared bell dropdown + mobile nav drawer for inventory staff shell pages.
 */
(function () {
  'use strict';

  var notifCtx = null;

  function getNotifCtx() {
    if (!notifCtx) {
      var Ctor = window.AudioContext || window.webkitAudioContext;
      if (!Ctor) return null;
      notifCtx = new Ctor();
    }
    if (notifCtx.state === 'suspended') {
      notifCtx.resume();
    }
    return notifCtx;
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
    var ctx = getNotifCtx();
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
      } catch(e) {}
    }
    playFallbackBeep();
  }

  // Unlock on first user interaction
  function unlockCtx() { getNotifCtx(); document.removeEventListener('click', unlockCtx); document.removeEventListener('keydown', unlockCtx); document.removeEventListener('touchstart', unlockCtx); }
  document.addEventListener('click', unlockCtx);
  document.addEventListener('keydown', unlockCtx);
  document.addEventListener('touchstart', unlockCtx);

  var BELL_TOP_CLEAR_MS = 280;
  var bellTopClearTimer = null;

  function cancelBellTopClearTimer() {
    if (bellTopClearTimer) {
      clearTimeout(bellTopClearTimer);
      bellTopClearTimer = null;
    }
  }

  /** Clear inline placement after close — early clear snaps to CSS `top:0` / `left:0` inside backdrop-filter CB. */
  function scheduleBellPanelPlacementClear(panel) {
    if (!panel) return;
    cancelBellTopClearTimer();
    bellTopClearTimer = setTimeout(function () {
      bellTopClearTimer = null;
      panel.style.top = '';
      panel.style.left = '';
      panel.style.right = '';
    }, BELL_TOP_CLEAR_MS);
  }

  function closeBell(wrap) {
    if (!wrap) return;
    wrap.classList.remove('inv-dd-open');
    var btn = wrap.querySelector('.inv-bell-btn');
    var panel = wrap.querySelector('.inv-bell-dd-panel');
    if (btn) btn.setAttribute('aria-expanded', 'false');
    if (panel) {
      if (window.matchMedia('(max-width: 767px)').matches) {
        scheduleBellPanelPlacementClear(panel);
      } else {
        cancelBellTopClearTimer();
        panel.style.top = '';
        panel.style.left = '';
        panel.style.right = '';
      }
    }
  }

  /** Viewport-centered `left` inside fixed containing block (e.g. header with backdrop-filter). */
  function bellPanelViewportCenterLeft(panel) {
    var vw = window.innerWidth;
    var w = panel.getBoundingClientRect().width;
    if (!w || w < 40) {
      w = Math.min(320, vw - 24);
    }
    var wantViewport = (vw - w) / 2;
    var cb =
      panel.closest('.glass-header') ||
      panel.closest('header') ||
      panel.offsetParent ||
      document.documentElement;
    var cr = cb.getBoundingClientRect ? cb.getBoundingClientRect() : { left: 0, top: 0 };
    return Math.round(wantViewport - cr.left);
  }

  /** On narrow viewports: fixed panel; top under bell; left = screen center within CB. */
  function positionBellDropdown(btn, panel, isOpen) {
    if (!btn || !panel) return;
    var mobile = window.matchMedia('(max-width: 767px)').matches;
    if (mobile) {
      if (isOpen) {
        cancelBellTopClearTimer();
        var r = btn.getBoundingClientRect();
        panel.style.top = Math.round(r.bottom + 8) + 'px';
        panel.style.right = 'auto';
        panel.style.left = bellPanelViewportCenterLeft(panel) + 'px';
      }
      /* On mobile close: do not clear placement here — wait for transition. */
    } else {
      cancelBellTopClearTimer();
      panel.style.top = '';
      panel.style.left = '';
      panel.style.right = '';
    }
  }

  function initBell() {
    var wrap = document.querySelector('.inv-bell-wrap');
    if (!wrap) return;

    var btn = wrap.querySelector('.inv-bell-btn');
    var panel = wrap.querySelector('.inv-bell-dd-panel');
    var list = document.getElementById('invStaffBellList');

    var onResize = function () {
      if (wrap.classList.contains('inv-dd-open')) {
        positionBellDropdown(btn, panel, true);
      }
    };

    if (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var open = wrap.classList.toggle('inv-dd-open');
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        positionBellDropdown(btn, panel, open);
        if (open) {
          var badge = btn.querySelector('span.bg-red-500');
          if (badge) {
            badge.style.display = 'none';
          }
          fetch('../api/mark_notifications_read.php')
            .then(function(r) { return r.json(); })
            .catch(function(err) { console.error('Failed to mark notifications read:', err); });
        }
        if (open && window.matchMedia('(max-width: 767px)').matches) {
          requestAnimationFrame(function () {
            if (wrap.classList.contains('inv-dd-open')) {
              positionBellDropdown(btn, panel, true);
            }
          });
        }
        if (open && list && list.getAttribute('data-loaded') !== '1') {
          list.setAttribute('data-loaded', '1');
          loadBellActivities(list);
        }
        if (!open && window.matchMedia('(max-width: 767px)').matches) {
          scheduleBellPanelPlacementClear(panel);
        }
      });
    }

    window.addEventListener('resize', onResize);
    window.addEventListener('scroll', onResize, true);

    document.addEventListener('click', function () {
      closeBell(wrap);
    });

    if (panel) {
      panel.addEventListener('click', function (e) {
        e.stopPropagation();
      });
    }

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') closeBell(wrap);
    });

    initRealtimeNotifications();
  }

  function initRealtimeNotifications() {
    var protocol = location.protocol === 'https:' ? 'wss' : 'ws';
    var socketUrl = protocol + '://' + location.hostname + ':8090';
    var socket = null;
    var reconnectTimer = null;

    function connect() {
      if (socket) return;
      socket = new WebSocket(socketUrl);

      socket.onopen = function () {
        console.log('[Realtime] Connected to notification server');
        if (reconnectTimer) {
          clearInterval(reconnectTimer);
          reconnectTimer = null;
        }
      };

      socket.onmessage = function (e) {
        try {
          var payload = JSON.parse(e.data);
          handleRealtimeEvent(payload);
        } catch (err) {
          console.error('[Realtime] Message parse error', err);
        }
      };

      socket.onclose = function () {
        console.warn('[Realtime] Connection closed. Retrying...');
        socket = null;
        if (!reconnectTimer) {
          reconnectTimer = setInterval(connect, 5000);
        }
      };

      socket.onerror = function () {
        socket.close();
      };
    }

    connect();
  }

  function handleRealtimeEvent(payload) {
    if (!payload || !payload.event) return;

    // Handle preparation task status updates (concurrency/multi-staff updates)
    if (payload.event === 'prep_task.status_updated') {
        updatePrepTaskCardDOM(payload);
        return;
    }

    var relevantEvents = ['order.created', 'order.scheduled', 'delivery.damage_report'];
    if (relevantEvents.indexOf(payload.event) === -1) return;

    // Notify of new order to prepare if on preparation queue page
    if ((payload.event === 'order.created' || payload.event === 'order.scheduled')) {
      try {
        playNotificationSound();
      } catch (e) {}

      if (typeof Swal !== 'undefined' && document.querySelector('[data-prep-order-id]')) {
        Swal.fire({
          toast: true,
          position: 'top-end',
          icon: 'info',
          title: 'New order #' + (payload.data.order_id || '') + ' to prepare!',
          showCancelButton: true,
          confirmButtonText: 'Refresh Queue',
          cancelButtonText: 'Dismiss',
          confirmButtonColor: '#6366f1',
          timer: 10000,
          timerProgressBar: true
        }).then(function(result) {
          if (result.isConfirmed) {
            window.location.reload();
          }
        });
      }
    }

    // Increment notification badge
    updateBellBadge(1);

    // If panel is open and loaded, prepend the new activity
    var list = document.getElementById('invStaffBellList');
    if (list && list.getAttribute('data-loaded') === '1') {
      var row = document.createElement('div');
      row.className = 'px-4 py-3 border-b border-slate-50 bg-indigo-50/30 hover:bg-slate-50/80 transition-colors animate-pulse';
      var title = 'Notification';
      var details = payload.data.message || 'New activity recorded';
      
      if (payload.event.indexOf('order') === 0) title = 'Order Update';
      else if (payload.event.indexOf('delivery') === 0) title = 'Delivery Alert';

      row.innerHTML =
        '<div class="text-[10px] font-black text-indigo-600 uppercase tracking-wider mb-1">' + title + '</div>' +
        '<div class="text-xs text-slate-700 font-semibold leading-snug">' + details + '</div>';
      
      if (list.firstChild) {
        list.insertBefore(row, list.firstChild);
      } else {
        list.appendChild(row);
      }
      
      // Remove pulse after a few seconds
      setTimeout(function() {
        row.classList.remove('animate-pulse');
        row.classList.remove('bg-indigo-50/30');
      }, 3000);
    }
    
    // Play a subtle sound or trigger vibration if possible
    if (window.navigator && window.navigator.vibrate) {
        window.navigator.vibrate(100);
    }
  }

  function updatePrepTaskCardDOM(payload) {
    if (!payload || !payload.data) return;
    var orderId = payload.data.order_id;
    var status = payload.data.status;
    var userName = payload.data.user_name;

    // Play sound notification
    try {
      playNotificationSound();
    } catch (e) {}

    // Find the preparation card in the DOM
    var card = document.querySelector('[data-prep-order-id="' + orderId + '"]');
    if (card) {
      // Update accent line
      var accentLine = card.querySelector('[data-prep-accent-line]');
      if (accentLine) {
        if (status === 'ready') {
          accentLine.className = 'absolute top-0 left-0 w-1 h-full bg-emerald-400 opacity-80';
        } else {
          accentLine.className = 'absolute top-0 left-0 w-1 h-full bg-indigo-400 opacity-80';
        }
      }

      // Update badge
      var badge = card.querySelector('[data-prep-status-badge]');
      if (badge) {
        var icon = badge.querySelector('i');
        var textSpan = badge.querySelector('span');

        var prepConfig = {
          'preparing':   { cls: ['bg-amber-100','text-amber-700','border','border-amber-200'], icon: 'fa-spinner fa-spin', label: 'Preparing' },
          'ready':       { cls: ['bg-emerald-100','text-emerald-700','border','border-emerald-200'], icon: 'fa-circle-check', label: 'Ready for Pickup' },
          'short_stock': { cls: ['bg-rose-100','text-rose-700','border','border-rose-200'], icon: 'fa-triangle-exclamation', label: 'Short Stock' },
        };
        var cfg = prepConfig[status] || { cls: ['bg-slate-100','text-slate-600','border','border-slate-200'], icon: 'fa-hourglass-half', label: 'Not Started' };

        badge.className = 'inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-[10px] font-black uppercase tracking-wider';
        cfg.cls.forEach(function(c) { badge.classList.add(c); });
        if (icon) icon.className = 'fas ' + cfg.icon;
        if (textSpan) textSpan.textContent = cfg.label;
      }

      // Update action buttons (Concurrency Collision Prevention)
      var startBtn = card.querySelector('[data-prep-start-btn]');
      var readyBtn = card.querySelector('[data-prep-ready-btn]');

      var btnCfg = {
        'preparing': { startDisabled: true,  readyDisabled: false },
        'ready':     { startDisabled: true,  readyDisabled: true  },
      };
      var btns = btnCfg[status] || { startDisabled: false, readyDisabled: false };

      if (startBtn) {
        startBtn.disabled = btns.startDisabled;
        startBtn.className = btns.startDisabled
          ? 'w-full flex justify-center items-center gap-2 py-3 rounded-xl text-xs font-bold transition-all bg-slate-100 text-slate-400 cursor-not-allowed'
          : 'w-full flex justify-center items-center gap-2 py-3 rounded-xl text-xs font-bold transition-all bg-indigo-600 text-white hover:bg-indigo-700 shadow-md shadow-indigo-200/50 hover:shadow-lg hover:shadow-indigo-300/50 active:scale-[0.98]';
      }
      if (readyBtn) {
        readyBtn.disabled = btns.readyDisabled;
        readyBtn.className = btns.readyDisabled
          ? 'w-full flex justify-center items-center gap-2 py-3 rounded-xl text-xs font-bold transition-all bg-slate-100 text-slate-400 cursor-not-allowed'
          : 'w-full flex justify-center items-center gap-2 py-3 rounded-xl text-xs font-bold transition-all bg-emerald-500 text-white hover:bg-emerald-600 shadow-md shadow-emerald-200/50 hover:shadow-lg hover:shadow-emerald-300/50 active:scale-[0.98]';
      }
    }

    // Show SweetAlert2 Toast if loaded
    if (typeof Swal !== 'undefined') {
      var toastCfg = {
        'preparing':   { text: 'Staff ' + userName + ' is preparing Order #' + orderId, icon: 'info' },
        'ready':       { text: 'Order #' + orderId + ' is now Ready for Pickup!', icon: 'success' },
        'short_stock': { text: 'Order #' + orderId + ' is marked Short Stock!', icon: 'info' },
      };
      var tCfg = toastCfg[status];
      if (tCfg) {
        Swal.fire({
          toast: true,
          position: 'top-end',
          icon: tCfg.icon,
          title: tCfg.text,
          showConfirmButton: false,
          timer: 4000,
          timerProgressBar: true
        });
      }
    }
  }

  function updateBellBadge(delta) {
    var btn = document.querySelector('.inv-bell-btn');
    if (!btn) return;

    var badge = btn.querySelector('span.bg-red-500');
    if (badge) {
      var current = 0;
      if (badge.style.display !== 'none') {
        current = badge.textContent.indexOf('+') !== -1 ? 100 : parseInt(badge.textContent, 10);
      } else {
        badge.style.display = '';
      }
      var newVal = current + delta;
      badge.textContent = newVal > 99 ? '99+' : newVal;
    } else {
      badge = document.createElement('span');
      badge.className = 'pointer-events-none absolute -top-1 -right-1 flex h-[1.125rem] min-w-[1.125rem] items-center justify-center rounded-full bg-red-500 px-0.5 text-[9px] font-black leading-none text-white shadow-sm ring-2 ring-white';
      badge.textContent = delta > 99 ? '99+' : delta;
      btn.appendChild(badge);
    }
  }

  function loadBellActivities(listEl) {
    if (!listEl) return;
    listEl.innerHTML =
      '<div class="px-4 py-6 text-center text-xs text-slate-400">Loading activity…</div>';
    // Remove the filter to show more relevant activities, or adjust it
    fetch('../api/get_recent_activities.php?limit=15')
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (data.status === 'success' && data.logs && data.logs.length > 0) {
          listEl.innerHTML = '';
          data.logs.forEach(function (log) {
            var typeLabel = log.Activity_Type || 'Activity';
            var textColor = 'text-indigo-600';
            if (typeLabel === 'DAMAGE') textColor = 'text-orange-600';
            if (typeLabel === 'ORDER') textColor = 'text-emerald-600';
            if (typeLabel === 'OVERDUE') textColor = 'text-rose-600';

            var row = document.createElement('div');
            if (typeLabel === 'OVERDUE') {
              row.className = 'px-4 py-3 border-b border-rose-100 bg-rose-50 border-l-4 border-l-rose-500 hover:bg-rose-100/70 transition-colors';
            } else {
              row.className = 'px-4 py-3 border-b border-slate-50 hover:bg-slate-50/80 transition-colors';
            }

            row.innerHTML =
              '<div class="text-[10px] font-black ' + textColor + ' uppercase tracking-wider mb-1">' +
              (log.user_name || 'System') + ' • ' + typeLabel +
              '</div>' +
              '<div class="text-xs text-slate-700 font-bold leading-snug">' +
              (log.Action_Details || log.Activity || '') +
              '</div>';
            listEl.appendChild(row);
          });
        } else {
          listEl.innerHTML =
            '<div class="px-4 py-8 text-center text-slate-400 text-xs font-medium">No recent activity.</div>';
        }
      })
      .catch(function () {
        listEl.innerHTML =
          '<div class="px-4 py-8 text-center text-rose-500 text-xs font-medium">Could not load activity.</div>';
      });
  }

  function setDrawerOpen(open) {
    var backdrop = document.getElementById('invStaffDrawerBackdrop');
    var panel = document.getElementById('invStaffDrawerPanel');
    var toggle = document.getElementById('invStaffMenuToggle');
    if (!backdrop || !panel) return;

    if (open) {
      backdrop.classList.add('inv-drawer-visible');
      panel.classList.remove('is-closed');
      panel.classList.add('is-open');
      document.body.classList.add('inv-drawer-locked');
      if (toggle) toggle.setAttribute('aria-expanded', 'true');
    } else {
      backdrop.classList.remove('inv-drawer-visible');
      panel.classList.remove('is-open');
      panel.classList.add('is-closed');
      document.body.classList.remove('inv-drawer-locked');
      if (toggle) toggle.setAttribute('aria-expanded', 'false');
    }
  }

  function initDrawer() {
    var toggle = document.getElementById('invStaffMenuToggle');
    var backdrop = document.getElementById('invStaffDrawerBackdrop');
    var closeBtn = document.getElementById('invStaffDrawerClose');
    if (!toggle || !backdrop) return;

    toggle.addEventListener('click', function (e) {
      e.stopPropagation();
      var open = !backdrop.classList.contains('inv-drawer-visible');
      setDrawerOpen(open);
    });

    backdrop.addEventListener('click', function (e) {
      if (e.target === backdrop) setDrawerOpen(false);
    });

    if (closeBtn) {
      closeBtn.addEventListener('click', function () {
        setDrawerOpen(false);
      });
    }

    document.querySelectorAll('[data-inv-drawer-link]').forEach(function (a) {
      a.addEventListener('click', function () {
        setDrawerOpen(false);
      });
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && backdrop.classList.contains('inv-drawer-visible')) {
        setDrawerOpen(false);
      }
    });
  }

  var notifLastId = 0;

  function showInventoryToast(message) {
    if (typeof Swal !== 'undefined') {
      Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'info',
        title: message,
        showConfirmButton: false,
        timer: 5000,
        timerProgressBar: true
      });
    }
  }

  function pollNotifications() {
    fetch('../api/get_recent_activities.php?limit=5&last_id=' + notifLastId)
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.status !== 'success' || !data.logs || data.logs.length === 0) return;
        var pkField = data.pk || 'Log_ID';
        data.logs.forEach(function (log) {
          var logId = parseInt(log[pkField] || log.Log_ID || 0, 10);
          if (notifLastId > 0 && logId > notifLastId) {
            playNotificationSound();
            showInventoryToast(log.Action_Details || log.Activity || 'Notification');
            updateBellBadge(1);
          }
          if (logId > notifLastId) notifLastId = logId;
        });
      }).catch(function () {});
  }

  document.addEventListener('DOMContentLoaded', function () {
    initBell();
    initDrawer();
    initDragScroll();
    pollNotifications();
    setInterval(pollNotifications, 10000);
  });

  function initDragScroll() {
    const slider = document.querySelector('.hide-scroll');
    if (!slider) return;

    let isDown = false;
    let startX;
    let scrollLeft;

    slider.addEventListener('mousedown', (e) => {
      isDown = true;
      slider.classList.add('active');
      slider.style.cursor = 'grabbing';
      startX = e.pageX - slider.offsetLeft;
      scrollLeft = slider.scrollLeft;
    });

    slider.addEventListener('mouseleave', () => {
      isDown = false;
      slider.classList.remove('active');
      slider.style.cursor = 'default';
    });

    slider.addEventListener('mouseup', () => {
      isDown = false;
      slider.classList.remove('active');
      slider.style.cursor = 'default';
    });

    slider.addEventListener('mousemove', (e) => {
      if (!isDown) return;
      e.preventDefault();
      const x = e.pageX - slider.offsetLeft;
      const walk = (x - startX) * 2; // scroll-fast
      slider.scrollLeft = scrollLeft - walk;
    });
  }
})();
