(function () {
  var STORAGE_KEY = 'vip_pending_ops';
  var CHECK_INTERVAL = 30000;
  var banner = null;
  var bodyReady = false;

  function ready(fn) {
    if (document.body) {
      bodyReady = true;
      fn();
    } else {
      document.addEventListener('DOMContentLoaded', function () {
        bodyReady = true;
        fn();
      });
    }
  }

  function createBanner() {
    if (banner) return;
    banner = document.createElement('div');
    banner.id = 'vip-offline-banner';
    banner.style.cssText = 'position:fixed;top:0;left:0;right:0;z-index:99999;padding:10px 16px;text-align:center;font-size:14px;font-weight:700;display:none;font-family:sans-serif;';
    document.body.prepend(banner);
  }

  function showOffline(count) {
    ready(function () {
      if (!banner) createBanner();
      var pending = count > 0 ? ' \u00b7 ' + count + ' pending ops' : '';
      banner.textContent = 'You are offline' + pending + '. Reconnecting...';
      banner.style.background = '#dc2626';
      banner.style.color = '#fff';
      banner.style.display = 'block';
    });
  }

  function showOnline() {
    if (banner) banner.style.display = 'none';
  }

  function getPendingOps() {
    try {
      return JSON.parse(localStorage.getItem(STORAGE_KEY) || '[]');
    } catch (e) { return []; }
  }

  function setPendingOps(ops) {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(ops));
  }

  function queueOp(url, body) {
    var ops = getPendingOps();
    ops.push({ url: url, body: body, ts: Date.now() });
    setPendingOps(ops);
    if (banner) {
      banner.textContent = 'You are offline \u00b7 ' + ops.length + ' pending ops';
    }
  }

  function retryPendingOps() {
    var ops = getPendingOps();
    if (ops.length === 0) return Promise.resolve();

    var remaining = [];
    var sequence = Promise.resolve();

    ops.forEach(function (op) {
      sequence = sequence.then(function () {
        return fetch(op.url, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: op.body
        }).then(function (res) {
          if (!res.ok) remaining.push(op);
        }).catch(function () {
          remaining.push(op);
        });
      });
    });

    return sequence.then(function () {
      setPendingOps(remaining);
      if (remaining.length === 0 && navigator.onLine) {
        showOnline();
      }
    });
  }

  function onOnline() {
    retryPendingOps().then(function () {
      var count = getPendingOps().length;
      if (count === 0) showOnline();
      location.reload();
    });
  }

  function onOffline() {
    var count = getPendingOps().length;
    showOffline(count);
  }

  var origFetch = window.fetch;
  window.fetch = function (input, init) {
    var method = (init && init.method) || 'GET';
    return origFetch.apply(this, arguments).then(function (response) {
      if (response.status === 503) {
        return response.clone().json().then(function (data) {
          if (data && data.offline_queue) {
            var url = typeof input === 'string' ? input : input.url;
            var body = init && init.body ? init.body : null;
            queueOp(url, body);
          }
          return response;
        }).catch(function () {
          return response;
        });
      }
      return response;
    }).catch(function (err) {
      var url = typeof input === 'string' ? input : input.url;
      var body = init && init.body ? init.body : null;
      if (method !== 'GET' && body) {
        queueOp(url, body);
      }
      throw err;
    });
  };

  window.addEventListener('online', onOnline);
  window.addEventListener('offline', onOffline);

  ready(function () {
    createBanner();
    var count = getPendingOps().length;
    if (!navigator.onLine) {
      showOffline(count);
    } else if (count > 0) {
      retryPendingOps();
    }

    setInterval(function () {
      if (navigator.onLine) {
        retryPendingOps();
      }
    }, CHECK_INTERVAL);
  });
})();
