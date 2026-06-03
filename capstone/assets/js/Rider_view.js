(function initRiderNotificationDropdownLayout() {
    var btn = document.getElementById('notificationDropdown');
    var wrap = btn ? btn.closest('.dropdown') : null;
    var menu = wrap ? wrap.querySelector('.dropdown-menu-rider') : null;
    if (!btn || !menu) return;

    function setMenuTop() {
        if (!window.matchMedia('(max-width: 767px)').matches) {
            menu.style.top = '';
            return;
        }
        var r = btn.getBoundingClientRect();
        menu.style.top = Math.round(r.bottom + 8) + 'px';
    }

    function closeMenu() {
        menu.classList.remove('show');
        btn.setAttribute('aria-expanded', 'false');
    }

    btn.addEventListener('click', function (e) {
        e.stopPropagation();
        if (menu.classList.contains('show')) {
            closeMenu();
        } else {
            setMenuTop();
            menu.classList.add('show');
            btn.setAttribute('aria-expanded', 'true');
        }
    });

    document.addEventListener('click', function (e) {
        if (menu.classList.contains('show') && !wrap.contains(e.target)) {
            closeMenu();
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && menu.classList.contains('show')) {
            closeMenu();
        }
    });

    window.addEventListener('resize', function () {
        if (menu.classList.contains('show')) {
            setMenuTop();
        }
    });

    window.addEventListener('scroll', function () {
        if (menu.classList.contains('show')) {
            setMenuTop();
        }
    }, true);
})();

(function applyRiderViewConfig() {
    var cfg = typeof window.RIDER_VIEW_CONFIG === 'object' && window.RIDER_VIEW_CONFIG ? window.RIDER_VIEW_CONFIG : {};
    window.csrfToken = cfg.csrfToken || '';
    window.currentRiderUserId = Number(cfg.currentRiderUserId) || 0;
    window.deliveryCancellationReasons = Array.isArray(cfg.deliveryCancellationReasons) ? cfg.deliveryCancellationReasons : [];
    window.__riderDeliveryIds = Array.isArray(cfg.deliveryIds) ? cfg.deliveryIds.map(function (id) { return parseInt(id, 10) || 0; }) : [];
    window.__riderReadyDeliveryIds = Array.isArray(cfg.readyDeliveryIds) ? cfg.readyDeliveryIds.map(function (id) { return parseInt(id, 10) || 0; }) : [];
    window.RIDER_VIEW_FLAGS = cfg.flags && typeof cfg.flags === 'object' ? cfg.flags : {};
})();
const CAN_RIDER_DASHBOARD = !!(window.RIDER_VIEW_FLAGS && window.RIDER_VIEW_FLAGS.CAN_RIDER_DASHBOARD);
const CAN_RIDER_QUEUE = !!(window.RIDER_VIEW_FLAGS && window.RIDER_VIEW_FLAGS.CAN_RIDER_QUEUE);
const CAN_RIDER_HISTORY = !!(window.RIDER_VIEW_FLAGS && window.RIDER_VIEW_FLAGS.CAN_RIDER_HISTORY);
const HAS_DELIVERY_DAMAGE_REPORTS = !!(window.RIDER_VIEW_FLAGS && window.RIDER_VIEW_FLAGS.HAS_DELIVERY_DAMAGE_REPORTS);
const RIDER_MAPS_ENABLED = !(window.RIDER_VIEW_CONFIG && window.RIDER_VIEW_CONFIG.mapsEnabled === false);

console.log('RIDER_VIEW_BUILD=laragon-2026-04-14-server-geocode-only');
const detailModal = new bootstrap.Modal(document.getElementById('detailModal'));
const codModal = new bootstrap.Modal(document.getElementById('codModal'));
const viewOnlyModal = new bootstrap.Modal(document.getElementById('viewOnlyModal'));
const fullMapModal = new bootstrap.Modal(document.getElementById('fullMapModal'));
const damageReportModalEl = document.getElementById('damageReportModal');
const damageReportModal = damageReportModalEl ? new bootstrap.Modal(damageReportModalEl) : null;

let currentDeliveryId = 0;
let currentOrderId = 0;
let deliveryData = null;
const queueTrackingMaps = new Map();
let queueGeoWatchId = null;
let fullMap = null;
let fullMapDeliveryId = null;
let fullMapRiderMarker = null;
let fullMapDestinationMarker = null;
let fullMapRouteLine = null;
let currentRiderLatLng = null;
let fullMapRenderSeq = 0;
let fullMapPinMode = false;
let mapStyleMode = '2d'; // '2d' | 'realistic'
const fullMapRouteSourceId = 'delivery-route-src';
const fullMapRouteLayerId = 'delivery-route-line';
const routeCache = new Map();
/** Only when Leaflet is loaded (maps enabled). Avoid `L.divIcon` at parse time — script would throw and break tabs/nav. */
let riderIcon = null;
let destinationIcon = null;
if (RIDER_MAPS_ENABLED && typeof L !== 'undefined') {
    riderIcon = L.divIcon({
        html: '<div class="truck-wrap" style="width:38px;height:38px;border-radius:50%;background:#0082ff;border:2px solid #fff;box-shadow:0 2px 10px rgba(16,17,20,.24);display:flex;align-items:center;justify-content:center;"><i class="fas fa-truck truck-glyph" style="color:#fff;font-size:17px;transition:transform .25s ease;"></i></div>',
        className: 'rider-dot-icon',
        iconSize: [38, 38],
        iconAnchor: [19, 19]
    });
    destinationIcon = L.divIcon({
        html: '<div style="width:30px;height:30px;border-radius:50%;background:#ef4444;border:2px solid #fff;box-shadow:0 1px 6px rgba(0,0,0,.35);display:flex;align-items:center;justify-content:center;"><i class="fas fa-store" style="color:#fff;font-size:13px;"></i></div>',
        className: 'destination-dot-icon',
        iconSize: [30, 30],
        iconAnchor: [15, 15]
    });
}

function createBaseLayersForMap(mapInstance) {
    const street = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors'
    });
    const realistic = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
        maxZoom: 19,
        attribution: 'Tiles &copy; Esri'
    });
    return { map: mapInstance, street, realistic, active: null };
}

function getMapLibreStyle(mode) {
    if (mode === 'realistic') {
        return {
            version: 8,
            sources: {
                'esri-imagery': {
                    type: 'raster',
                    tiles: ['https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}'],
                    tileSize: 256,
                    attribution: 'Tiles &copy; Esri'
                }
            },
            layers: [{ id: 'esri-imagery-layer', type: 'raster', source: 'esri-imagery' }]
        };
    }
    return {
        version: 8,
        sources: {
            'osm-raster': {
                type: 'raster',
                tiles: ['https://tile.openstreetmap.org/{z}/{x}/{y}.png'],
                tileSize: 256,
                attribution: '&copy; OpenStreetMap contributors'
            }
        },
        layers: [{ id: 'osm-raster-layer', type: 'raster', source: 'osm-raster' }]
    };
}

function applyMapStyle(layerSet) {
    if (!layerSet || !layerSet.map) return;
    if (layerSet.active) {
        try { layerSet.map.removeLayer(layerSet.active); } catch (e) {}
    }
    layerSet.active = mapStyleMode === 'realistic' ? layerSet.realistic : layerSet.street;
    layerSet.active.addTo(layerSet.map);
}

function refreshMapStyleButton() {
    const btn = document.getElementById('btnMapStyleToggle');
    if (!btn) return;
    btn.innerHTML = mapStyleMode === 'realistic'
        ? '<i class="fas fa-layer-group me-1"></i>2D'
        : '<i class="fas fa-layer-group me-1"></i>Realistic';
}

function toggleMapStyle() {
    mapStyleMode = mapStyleMode === '2d' ? 'realistic' : '2d';
    queueTrackingMaps.forEach((entry) => {
        if (entry.baseLayers) applyMapStyle(entry.baseLayers);
    });
    if (fullMap && typeof maplibregl !== 'undefined' && fullMap instanceof maplibregl.Map) {
        fullMap.setStyle(getMapLibreStyle(mapStyleMode));
        const entry = queueTrackingMaps.get(Number(fullMapDeliveryId));
        if (entry && currentRiderLatLng) {
            fullMap.once('styledata', () => {
                renderFullMapTracking(entry, currentRiderLatLng.lat, currentRiderLatLng.lng);
            });
        }
    } else if (fullMap && fullMap._baseLayers) {
        applyMapStyle(fullMap._baseLayers);
    }
    refreshMapStyleButton();
}

document.getElementById('deliveredTo').addEventListener('change', function() {
    document.getElementById('deliveredToOther').style.display = this.value === '_other_' ? 'block' : 'none';
});
document.getElementById('proofPhoto').addEventListener('change', function() {
    const prev = document.getElementById('proofPreview');
    const grid = document.getElementById('proofPreviewGrid');
    if (!prev || !grid) return;
    grid.innerHTML = '';
    const files = Array.from(this.files || []);
    const imageFiles = files.filter((f) => f && f.type && f.type.startsWith('image/'));
    if (imageFiles.length > 0) {
        imageFiles.forEach((f) => {
            const url = URL.createObjectURL(f);
            const img = document.createElement('img');
            img.src = url;
            img.alt = 'Proof preview';
            img.className = 'w-full h-28 object-cover rounded-[12px] shadow-sm border border-slate-200';
            grid.appendChild(img);
        });
        prev.style.display = 'block';
    } else {
        prev.style.display = 'none';
    }
});

function viewDamagePhoto(path) {
    if (!path) return;
    const fullPath = path.startsWith('http') ? path : '../' + path;
    Swal.fire({
        title: 'Damage Evidence',
        imageUrl: fullPath,
        imageAlt: 'Damage Photo',
        confirmButtonText: 'Close',
        confirmButtonColor: '#2563eb',
        showCloseButton: true,
        customClass: {
            popup: 'rounded-[24px]',
            confirmButton: 'rounded-pill px-4'
        }
    });
}

function previewDamagePhoto(input) {
    const wrap = document.getElementById('ddr_photo_preview_wrap');
    const img = document.getElementById('ddr_photo_preview');
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            img.src = e.target.result;
            wrap.classList.remove('hidden');
        }
        reader.readAsDataURL(input.files[0]);
    } else {
        removeDamagePhoto();
    }
}

function removeDamagePhoto() {
    const input = document.getElementById('ddr_photo');
    const wrap = document.getElementById('ddr_photo_preview_wrap');
    const img = document.getElementById('ddr_photo_preview');
    input.value = '';
    img.src = '';
    wrap.classList.add('hidden');
}

function switchToTab(id) {
    if ((id === 'dashboard' && !CAN_RIDER_DASHBOARD) || (id === 'queue' && !CAN_RIDER_QUEUE) || (id === 'history' && !CAN_RIDER_HISTORY) || (id === 'cancelled' && !CAN_RIDER_HISTORY) || (id === 'damage-reports' && !HAS_DELIVERY_DAMAGE_REPORTS)) {
        Swal.fire('Access Restricted', 'You can’t access this module right now.', 'warning');
        return;
    }
    document.querySelectorAll('.nav-tab-rider').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(c => c.style.display = 'none');
    const tabBtn = document.querySelector('.nav-tab-rider[data-tab="' + id + '"]');
    if (tabBtn) tabBtn.classList.add('active');
    const panel = document.getElementById('tab-' + id);
    if (panel) panel.style.display = 'block';
    if (window.location.hash !== `#${id}`) {
        window.history.replaceState(null, '', `#${id}`);
    }
    if (id === 'history') loadDeliveredHistory();
}

function getInitialTab() {
    const hashTab = (window.location.hash || '').replace('#', '');
    if (hashTab === 'dashboard' && CAN_RIDER_DASHBOARD) return 'dashboard';
    if (hashTab === 'queue' && CAN_RIDER_QUEUE) return 'queue';
    if (hashTab === 'history' && CAN_RIDER_HISTORY) return 'history';
    if (hashTab === 'cancelled' && CAN_RIDER_HISTORY) return 'cancelled';
    if (hashTab === 'damage-reports' && HAS_DELIVERY_DAMAGE_REPORTS) return 'damage-reports';
    if (CAN_RIDER_DASHBOARD) return 'dashboard';
    if (CAN_RIDER_QUEUE) return 'queue';
    if (CAN_RIDER_HISTORY) return 'history';
    if (HAS_DELIVERY_DAMAGE_REPORTS) return 'damage-reports';
    return null;
}

function formatDistanceKm(km) {
    if (!isFinite(km)) return '—';
    return `${km.toFixed(2)} km away`;
}

function estimateEtaMinutes(distanceKm, speedKph = null) {
    if (!isFinite(distanceKm) || distanceKm <= 0) return { min: 1, max: 3 };
    // Base city-delivery assumption when speed is unavailable.
    const fallbackKph = 24;
    const normalizedSpeed = (isFinite(speedKph) && speedKph > 4) ? Math.min(speedKph, 45) : fallbackKph;
    const idealMin = Math.max(1, Math.round((distanceKm / normalizedSpeed) * 60));
    // Add uncertainty buffer for traffic/stops/road conditions.
    const buffer = Math.max(3, Math.round(idealMin * 0.35));
    return { min: idealMin, max: idealMin + buffer };
}

function haversineKm(lat1, lon1, lat2, lon2) {
    const R = 6371;
    const dLat = (lat2 - lat1) * Math.PI / 180;
    const dLon = (lon2 - lon1) * Math.PI / 180;
    const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
              Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
              Math.sin(dLon / 2) * Math.sin(dLon / 2);
    return 2 * R * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

function calculateBearingDeg(lat1, lon1, lat2, lon2) {
    const p1 = lat1 * Math.PI / 180;
    const p2 = lat2 * Math.PI / 180;
    const dLon = (lon2 - lon1) * Math.PI / 180;
    const y = Math.sin(dLon) * Math.cos(p2);
    const x = Math.cos(p1) * Math.sin(p2) - Math.sin(p1) * Math.cos(p2) * Math.cos(dLon);
    const brng = Math.atan2(y, x) * 180 / Math.PI;
    return (brng + 360) % 360;
}

function rotateRiderMarker(marker, bearingDeg) {
    if (!marker || !isFinite(bearingDeg)) return;
    const el = marker.getElement();
    if (!el) return;
    const glyph = el.querySelector('.truck-glyph');
    if (glyph) glyph.style.transform = `rotate(${bearingDeg}deg)`;
}

function pointToSegmentDistanceMeters(px, py, x1, y1, x2, y2) {
    const dx = x2 - x1;
    const dy = y2 - y1;
    if (dx === 0 && dy === 0) return haversineKm(py, px, y1, x1) * 1000;
    const t = Math.max(0, Math.min(1, ((px - x1) * dx + (py - y1) * dy) / (dx * dx + dy * dy)));
    const projX = x1 + t * dx;
    const projY = y1 + t * dy;
    return haversineKm(py, px, projY, projX) * 1000;
}

function minDistanceToPolylineMeters(lat, lng, latLngs) {
    if (!Array.isArray(latLngs) || latLngs.length < 2) return Number.POSITIVE_INFINITY;
    let min = Number.POSITIVE_INFINITY;
    for (let i = 1; i < latLngs.length; i += 1) {
        const a = latLngs[i - 1];
        const b = latLngs[i];
        const d = pointToSegmentDistanceMeters(lng, lat, a[1], a[0], b[1], b[0]);
        if (d < min) min = d;
    }
    return min;
}

function hasValidDestinationLatLng(lat, lng) {
    const dLat = Number(lat);
    const dLng = Number(lng);
    if (!isFinite(dLat) || !isFinite(dLng)) return false;
    if (Math.abs(dLat) < 0.000001 && Math.abs(dLng) < 0.000001) return false; // reject 0,0
    if (Math.abs(dLat) > 90 || Math.abs(dLng) > 180) return false;
    return true;
}

async function fetchRoutePath(fromLat, fromLng, toLat, toLng, force = false) {
    const key = `${fromLat.toFixed(5)},${fromLng.toFixed(5)}|${toLat.toFixed(5)},${toLng.toFixed(5)}`;
    const cached = routeCache.get(key);
    if (cached && Date.now() - cached.ts < 30000) return cached.data;

    try {
        const url = `../api/routing_proxy.php?action=route&from_lat=${fromLat}&from_lng=${fromLng}&to_lat=${toLat}&to_lng=${toLng}&force=${force ? '1' : '0'}`;
        const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
        if (!res.ok) return null;
        const payloadRaw = await res.json();
        const data = payloadRaw?.data;
        const routes = Array.isArray(data?.routes) ? data.routes : [];
        if (!routes.length) return null;
        const route = routes.slice().sort((a, b) => (a?.duration || Number.MAX_VALUE) - (b?.duration || Number.MAX_VALUE))[0];
        const coords = route?.geometry?.coordinates;
        if (!Array.isArray(coords) || coords.length < 2) return null;
        const latLngs = coords.map(c => [c[1], c[0]]);
        const distanceKm = Number(route?.distance || 0) / 1000;
        const durationMin = Number(route?.duration || 0) / 60;
        const rawSteps = route?.legs?.[0]?.steps || [];
        const steps = rawSteps.slice(0, 5).map((s) => {
            const maneuver = (s?.maneuver?.modifier || s?.maneuver?.type || 'Continue').toString();
            const road = (s?.name || '').toString().trim();
            const km = Number(s?.distance || 0) / 1000;
            const roadLabel = road ? `to ${road}` : '';
            return `${maneuver} ${roadLabel} (${km.toFixed(2)} km)`.trim();
        });
        const payload = {
            coords: latLngs,
            distanceKm,
            durationMin,
            steps,
            alternatives: routes.length
        };
        routeCache.set(key, { ts: Date.now(), data: payload });
        return payload;
    } catch (e) {
        return null;
    }
}

async function snapToRoad(lat, lng) {
    const key = `snap:${lat.toFixed(5)},${lng.toFixed(5)}`;
    const cached = routeCache.get(key);
    if (cached && Date.now() - cached.ts < 12000) return cached.data;
    try {
        const url = `../api/routing_proxy.php?action=nearest&lat=${lat}&lng=${lng}`;
        const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
        if (!res.ok) return { lat, lng };
        const payloadRaw = await res.json();
        const data = payloadRaw?.data;
        const loc = data?.waypoints?.[0]?.location;
        const snapped = (Array.isArray(loc) && loc.length === 2)
            ? { lat: Number(loc[1]), lng: Number(loc[0]) }
            : { lat, lng };
        routeCache.set(key, { ts: Date.now(), data: snapped });
        return snapped;
    } catch (e) {
        return { lat, lng };
    }
}

async function geocodeViaProxy(address, nearLat = null, nearLng = null) {
    const q = (address || '').trim();
    if (!q) return null;
    try {
        let url = `../api/routing_proxy.php?action=geocode&q=${encodeURIComponent(q)}`;
        if (nearLat !== null && nearLng !== null && Number.isFinite(Number(nearLat)) && Number.isFinite(Number(nearLng))) {
            url += `&near_lat=${encodeURIComponent(nearLat)}&near_lng=${encodeURIComponent(nearLng)}`;
        }
        const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
        const raw = await res.text();
        let payload = null;
        try {
            payload = JSON.parse(raw);
        } catch (e) {
            return null;
        }
        if (!res.ok) return null;
        if (!payload || !payload.success || !payload.data) return null;
        const lat = Number(payload.data.lat);
        const lng = Number(payload.data.lng);
        if (!hasValidDestinationLatLng(lat, lng)) return null;
        return {
            lat,
            lng,
            confidence: String(payload.data.confidence || 'low'),
            displayName: String(payload.data.display_name || ''),
            distanceKm: isFinite(Number(payload.data.distance_km)) ? Number(payload.data.distance_km) : null
        };
    } catch (e) {
        return null;
    }
}

async function saveDestinationPin(deliveryId, lat, lng) {
    const formData = new FormData();
    formData.append('action', 'save_destination_pin');
    formData.append('delivery_id', String(deliveryId));
    formData.append('lat', String(lat));
    formData.append('lng', String(lng));
    const res = await fetch('../api/routing_proxy.php', { method: 'POST', body: formData });
    const raw = await res.text();
    let data = null;
    try {
        data = JSON.parse(raw);
    } catch (e) {
        throw new Error('Pin API returned non-JSON response');
    }
    if (!res.ok) {
        throw new Error(data?.message || 'Failed to save destination pin');
    }
    if (!data || !data.success) {
        throw new Error(data?.message || 'Failed to save destination pin');
    }
    return data;
}

function setPinModeState(enabled) {
    fullMapPinMode = !!enabled;
    const btn = document.getElementById('btnPinMode');
    const hint = document.getElementById('fullMapHintText');
    if (btn) btn.classList.toggle('active', fullMapPinMode);
    if (hint) {
        hint.textContent = fullMapPinMode
            ? 'Pin mode ON: tap map to set exact destination for this delivery.'
            : 'Tip: Search location or tap Pin then click exact store location on map.';
    }
}

async function applyDestinationToActiveDelivery(lat, lng, label = 'Custom pin') {
    const entry = queueTrackingMaps.get(Number(fullMapDeliveryId));
    if (!entry) return;
    const previousLatLng = Array.isArray(entry.destinationLatLng) ? [...entry.destinationLatLng] : null;
    const hadInlineMarker = !!entry.destinationMarker;
    const hadFullMarker = !!fullMapDestinationMarker;
    const newLatLng = [lat, lng];

    // Optimistic update for immediate feedback.
    entry.destinationLatLng = newLatLng;
    if (entry.destinationMarker) entry.destinationMarker.setLatLng(newLatLng);
    else entry.destinationMarker = L.marker(newLatLng, { icon: destinationIcon }).addTo(entry.map).bindPopup(`Destination: ${entry.customerName}`);
    if (fullMap) {
        const usingMapLibre = (typeof maplibregl !== 'undefined') && (fullMap instanceof maplibregl.Map);
        if (usingMapLibre) {
            if (fullMapDestinationMarker) {
                fullMapDestinationMarker.setLngLat([lng, lat]);
            } else {
                const destEl = document.createElement('div');
                destEl.innerHTML = '<div style="width:30px;height:30px;border-radius:50%;background:#ef4444;border:2px solid #fff;box-shadow:0 1px 6px rgba(0,0,0,.35);display:flex;align-items:center;justify-content:center;color:#fff;"><i class="fas fa-store"></i></div>';
                fullMapDestinationMarker = new maplibregl.Marker({ element: destEl.firstChild }).setLngLat([lng, lat]).addTo(fullMap);
            }
        } else {
            if (fullMapDestinationMarker) fullMapDestinationMarker.setLatLng(newLatLng);
            else fullMapDestinationMarker = L.marker(newLatLng, { icon: destinationIcon }).addTo(fullMap).bindPopup(`Destination: ${entry.customerName}`);
        }
    }
    const metaEl = document.getElementById(`inline-map-meta-${entry.deliveryId}`);
    if (metaEl) metaEl.textContent = `Saving destination pin...`;

    try {
        await saveDestinationPin(entry.deliveryId, lat, lng);
    } catch (err) {
        // Rollback map state if persistence failed.
        entry.destinationLatLng = previousLatLng;
        if (previousLatLng) {
            if (entry.destinationMarker) entry.destinationMarker.setLatLng(previousLatLng);
            if (fullMapDestinationMarker) fullMapDestinationMarker.setLatLng(previousLatLng);
        } else {
            if (!hadInlineMarker && entry.destinationMarker) {
                entry.map.removeLayer(entry.destinationMarker);
                entry.destinationMarker = null;
            }
            if (!hadFullMarker && fullMap && fullMapDestinationMarker) {
                const usingMapLibre = (typeof maplibregl !== 'undefined') && (fullMap instanceof maplibregl.Map);
                if (usingMapLibre) fullMapDestinationMarker.remove();
                else fullMap.removeLayer(fullMapDestinationMarker);
                fullMapDestinationMarker = null;
            }
        }
        if (metaEl) metaEl.textContent = 'Destination pin save failed.';
        throw err;
    }

    if (metaEl) metaEl.textContent = `Destination set: ${label}`;
    if (currentRiderLatLng) {
        renderInlineTracking(entry, currentRiderLatLng.lat, currentRiderLatLng.lng);
        renderFullMapTracking(entry, currentRiderLatLng.lat, currentRiderLatLng.lng);
    } else if (fullMap) {
        const usingMapLibre = (typeof maplibregl !== 'undefined') && (fullMap instanceof maplibregl.Map);
        if (usingMapLibre) fullMap.easeTo({ center: [entry.destinationLatLng[1], entry.destinationLatLng[0]], zoom: 16, duration: 300 });
        else fullMap.setView(entry.destinationLatLng, 16);
    }
}

async function searchDestinationInFullMap() {
    const entry = queueTrackingMaps.get(Number(fullMapDeliveryId));
    const input = document.getElementById('fullMapSearchInput');
    if (!entry || !input) return;
    const query = input.value.trim();
    if (!query) {
        Swal.fire('Search destination', 'Enter a location to search.', 'info');
        return;
    }
    const nearLat = currentRiderLatLng?.lat ?? null;
    const nearLng = currentRiderLatLng?.lng ?? null;
    const found = await geocodeViaProxy(query, nearLat, nearLng);
    if (!found) {
        Swal.fire('Not found', 'Could not resolve that location. Try a more specific address.', 'warning');
        return;
    }
    await applyDestinationToActiveDelivery(found.lat, found.lng, found.displayName || query);
    Swal.fire('Destination updated', `Pinned at ${found.displayName || query}`, 'success');
}

function togglePinMode() {
    setPinModeState(!fullMapPinMode);
}

async function handleFullMapClickForPin(e) {
    if (!fullMapPinMode) return;
    const lat = Number(e?.latlng?.lat ?? e?.lngLat?.lat);
    const lng = Number(e?.latlng?.lng ?? e?.lngLat?.lng);
    if (!hasValidDestinationLatLng(lat, lng)) return;
    try {
        await applyDestinationToActiveDelivery(lat, lng, 'Map pin');
        setPinModeState(false);
        Swal.fire('Pinned', 'Exact destination has been saved for this delivery.', 'success');
    } catch (err) {
        Swal.fire('Pin save failed', err.message || 'Could not save destination pin.', 'error');
    }
}

async function renderInlineTracking(entry, riderLat, riderLng, speedMps = null) {
    entry.inlineRenderSeq = (entry.inlineRenderSeq || 0) + 1;
    const renderSeq = entry.inlineRenderSeq;
    const deliveryId = entry.deliveryId;
    const metaEl = document.getElementById(`inline-map-meta-${deliveryId}`);
    if (!entry.riderMarker) {
        entry.riderMarker = L.marker([riderLat, riderLng], { icon: riderIcon }).addTo(entry.map).bindPopup('Rider Truck');
    } else {
        entry.riderMarker.setLatLng([riderLat, riderLng]);
    }
    if (entry.prevRiderLatLng) {
        const heading = calculateBearingDeg(entry.prevRiderLatLng.lat, entry.prevRiderLatLng.lng, riderLat, riderLng);
        rotateRiderMarker(entry.riderMarker, heading);
    }
    entry.prevRiderLatLng = { lat: riderLat, lng: riderLng };
    if (entry.routeLine) {
        entry.map.removeLayer(entry.routeLine);
        entry.routeLine = null;
    }
    if (entry.destinationLatLng && hasValidDestinationLatLng(entry.destinationLatLng[0], entry.destinationLatLng[1])) {
        let routeData = await fetchRoutePath(riderLat, riderLng, entry.destinationLatLng[0], entry.destinationLatLng[1]);
        if (renderSeq !== entry.inlineRenderSeq) return;
        const deviationMeters = minDistanceToPolylineMeters(riderLat, riderLng, routeData?.coords || []);
        if (isFinite(deviationMeters) && deviationMeters > 120) {
            const now = Date.now();
            if (!entry.lastRerouteAt || now - entry.lastRerouteAt > 15000) {
                entry.lastRerouteAt = now;
                routeData = await fetchRoutePath(riderLat, riderLng, entry.destinationLatLng[0], entry.destinationLatLng[1], true) || routeData;
                if (renderSeq !== entry.inlineRenderSeq) return;
            }
        }
        const routeCoords = routeData?.coords || null;
        entry.routeLine = L.polyline(routeCoords || [[riderLat, riderLng], entry.destinationLatLng], { color: '#1d4ed8', weight: 5, opacity: 0.85 }).addTo(entry.map);
        if (!entry.initialFitDone) {
            entry.map.fitBounds(entry.routeLine.getBounds(), { padding: [18, 18], maxZoom: 16 });
            entry.initialFitDone = true;
        }
        const distKm = (routeData && isFinite(routeData.distanceKm) && routeData.distanceKm > 0)
            ? routeData.distanceKm
            : haversineKm(riderLat, riderLng, entry.destinationLatLng[0], entry.destinationLatLng[1]);
        const speedKph = isFinite(speedMps) && speedMps > 0 ? speedMps * 3.6 : null;
        const eta = estimateEtaMinutes(distKm, speedKph);
        if (metaEl) metaEl.textContent = `Live rider: ${formatDistanceKm(distKm)} • ETA ${eta.min}-${eta.max} min • ${routeData?.alternatives || 1} route option(s)`;
    } else if (metaEl) {
        metaEl.textContent = 'Live rider location active.';
    }
}

async function renderFullMapTracking(entry, riderLat, riderLng, speedMps = null) {
    if (!fullMap || !entry || Number(fullMapDeliveryId) !== Number(entry.deliveryId)) return;
    fullMapRenderSeq += 1;
    const renderSeq = fullMapRenderSeq;
    const metaEl = document.getElementById('fullMapMeta');
    const chip = document.getElementById('fullMapStatusChip');

    const usingMapLibre = (typeof maplibregl !== 'undefined') && (fullMap instanceof maplibregl.Map);

    if (usingMapLibre) {
        if (!fullMapRiderMarker) {
            const riderEl = document.createElement('div');
            riderEl.innerHTML = '<div style="width:38px;height:38px;border-radius:50%;background:#0082ff;border:2px solid #fff;box-shadow:0 2px 10px rgba(16,17,20,.24);display:flex;align-items:center;justify-content:center;color:#fff;"><i class="fas fa-truck"></i></div>';
            fullMapRiderMarker = new maplibregl.Marker({ element: riderEl.firstChild }).setLngLat([riderLng, riderLat]).addTo(fullMap);
        } else {
            fullMapRiderMarker.setLngLat([riderLng, riderLat]);
        }

        if (entry.destinationLatLng && hasValidDestinationLatLng(entry.destinationLatLng[0], entry.destinationLatLng[1])) {
            const dLng = entry.destinationLatLng[1];
            const dLat = entry.destinationLatLng[0];
            if (!fullMapDestinationMarker) {
                const destEl = document.createElement('div');
                destEl.innerHTML = '<div style="width:30px;height:30px;border-radius:50%;background:#ef4444;border:2px solid #fff;box-shadow:0 1px 6px rgba(0,0,0,.35);display:flex;align-items:center;justify-content:center;color:#fff;"><i class="fas fa-store"></i></div>';
                fullMapDestinationMarker = new maplibregl.Marker({ element: destEl.firstChild }).setLngLat([dLng, dLat]).addTo(fullMap);
            } else {
                fullMapDestinationMarker.setLngLat([dLng, dLat]);
            }
        }
    } else {
        if (!fullMapRiderMarker) {
            fullMapRiderMarker = L.marker([riderLat, riderLng], { icon: riderIcon }).addTo(fullMap).bindPopup('Rider Truck');
        } else {
            fullMapRiderMarker.setLatLng([riderLat, riderLng]);
        }
        if (entry.prevRiderLatLng) {
            const heading = calculateBearingDeg(entry.prevRiderLatLng.lat, entry.prevRiderLatLng.lng, riderLat, riderLng);
            rotateRiderMarker(fullMapRiderMarker, heading);
        }
        if (entry.destinationLatLng && hasValidDestinationLatLng(entry.destinationLatLng[0], entry.destinationLatLng[1])) {
            if (!fullMapDestinationMarker) {
                fullMapDestinationMarker = L.marker(entry.destinationLatLng, { icon: destinationIcon }).addTo(fullMap).bindPopup(`Destination: ${entry.customerName || 'Customer'}`);
            } else {
                fullMapDestinationMarker.setLatLng(entry.destinationLatLng);
            }
        }
        if (fullMapRouteLine) {
            fullMap.removeLayer(fullMapRouteLine);
            fullMapRouteLine = null;
        }
    }

    if (entry.destinationLatLng && hasValidDestinationLatLng(entry.destinationLatLng[0], entry.destinationLatLng[1])) {
        let routeData = await fetchRoutePath(riderLat, riderLng, entry.destinationLatLng[0], entry.destinationLatLng[1]);
        if (renderSeq !== fullMapRenderSeq || Number(fullMapDeliveryId) !== Number(entry.deliveryId)) return;
        const deviationMeters = minDistanceToPolylineMeters(riderLat, riderLng, routeData?.coords || []);
        if (isFinite(deviationMeters) && deviationMeters > 120) {
            const now = Date.now();
            if (!entry.lastRerouteAt || now - entry.lastRerouteAt > 15000) {
                entry.lastRerouteAt = now;
                routeData = await fetchRoutePath(riderLat, riderLng, entry.destinationLatLng[0], entry.destinationLatLng[1], true) || routeData;
                if (renderSeq !== fullMapRenderSeq || Number(fullMapDeliveryId) !== Number(entry.deliveryId)) return;
            }
        }
        const routeCoords = routeData?.coords || [[riderLat, riderLng], entry.destinationLatLng];
        if (usingMapLibre) {
            const geo = {
                type: 'FeatureCollection',
                features: [{
                    type: 'Feature',
                    properties: {},
                    geometry: { type: 'LineString', coordinates: routeCoords.map(c => [c[1], c[0]]) }
                }]
            };
            if (fullMap.getSource(fullMapRouteSourceId)) {
                fullMap.getSource(fullMapRouteSourceId).setData(geo);
            } else if (fullMap.isStyleLoaded()) {
                fullMap.addSource(fullMapRouteSourceId, { type: 'geojson', data: geo });
                fullMap.addLayer({
                    id: fullMapRouteLayerId,
                    type: 'line',
                    source: fullMapRouteSourceId,
                    paint: { 'line-color': '#1d4ed8', 'line-width': 6, 'line-opacity': 0.9 }
                });
            }
            const bounds = routeCoords.reduce((b, c) => b.extend([c[1], c[0]]), new maplibregl.LngLatBounds([routeCoords[0][1], routeCoords[0][0]], [routeCoords[0][1], routeCoords[0][0]]));
            if (!entry.fullInitialFitDone) {
                fullMap.fitBounds(bounds, { padding: 28, maxZoom: 16, duration: 300 });
                entry.fullInitialFitDone = true;
            } else {
                fullMap.easeTo({ center: [riderLng, riderLat], duration: 300 });
            }
        } else {
            fullMapRouteLine = L.polyline(routeCoords, { color: '#1d4ed8', weight: 6, opacity: 0.9 }).addTo(fullMap);
            if (!entry.fullInitialFitDone) {
                fullMap.fitBounds(fullMapRouteLine.getBounds(), { padding: [28, 28], maxZoom: 16 });
                entry.fullInitialFitDone = true;
            } else {
                fullMap.panTo([riderLat, riderLng], { animate: true, duration: 0.35 });
            }
        }

        const distKm = (routeData && isFinite(routeData.distanceKm) && routeData.distanceKm > 0)
            ? routeData.distanceKm
            : haversineKm(riderLat, riderLng, entry.destinationLatLng[0], entry.destinationLatLng[1]);
        const speedKph = isFinite(speedMps) && speedMps > 0 ? speedMps * 3.6 : null;
        const etaFromRoute = (routeData && isFinite(routeData.durationMin) && routeData.durationMin > 0)
            ? { min: Math.max(1, Math.round(routeData.durationMin)), max: Math.max(2, Math.round(routeData.durationMin * 1.35)) }
            : null;
        const eta = etaFromRoute || estimateEtaMinutes(distKm, speedKph);
        if (metaEl) metaEl.textContent = `Tracking #${entry.deliveryId} • ${formatDistanceKm(distKm)} • ETA ${eta.min}-${eta.max} min (estimate only, traffic may cause delay)`;
        if (chip) chip.textContent = `Rider en route • ${distKm.toFixed(2)} km • ETA ${eta.min}-${eta.max} min`;
        const routeSummary = document.getElementById('routeSummaryText');
        const stepsList = document.getElementById('routeStepsList');
        if (routeSummary) {
            routeSummary.textContent = `Road route distance: ${distKm.toFixed(2)} km • Estimated travel: ${eta.min}-${eta.max} min • ${routeData?.alternatives || 1} route option(s)`;
        }
        if (stepsList) {
            const steps = routeData?.steps || [];
            stepsList.innerHTML = steps.length
                ? steps.map((s) => `<li>${s.replace(/</g, '&lt;')}</li>`).join('')
                : '<li>Continue on current road toward destination.</li>';
        }
    } else if (metaEl) {
        metaEl.textContent = `Tracking delivery #${entry.deliveryId} • rider location active`;
        if (chip) chip.textContent = 'Rider location active. Waiting for destination coordinates.';
        const routeSummary = document.getElementById('routeSummaryText');
        const stepsList = document.getElementById('routeStepsList');
        if (routeSummary) routeSummary.textContent = 'Waiting for route details...';
        if (stepsList) stepsList.innerHTML = '';
    }
}

function openFullMapTracking(deliveryId) {
    if (!RIDER_MAPS_ENABLED) {
        Swal.fire({ icon: 'info', title: 'Map unavailable', text: 'Live map is temporarily disabled.' });
        return;
    }
    const entry = queueTrackingMaps.get(Number(deliveryId));
    if (!entry) {
        Swal.fire('Unavailable', 'Map data is not ready yet for this delivery.', 'info');
        return;
    }
    fullMapDeliveryId = Number(deliveryId);
    fullMapRenderSeq += 1;
    const routeSummary = document.getElementById('routeSummaryText');
    const stepsList = document.getElementById('routeStepsList');
    if (routeSummary) routeSummary.textContent = 'Waiting for route details...';
    if (stepsList) stepsList.innerHTML = '';
    const searchInput = document.getElementById('fullMapSearchInput');
    if (searchInput) searchInput.value = entry.address || '';
    setPinModeState(false);
    fullMapModal.show();
    setTimeout(() => {
        if (!fullMap) {
            if (typeof maplibregl !== 'undefined') {
                fullMap = new maplibregl.Map({
                    container: 'fullTrackingMap',
                    style: getMapLibreStyle(mapStyleMode),
                    center: [124.731371, 8.484026],
                    zoom: 12
                });
                fullMap.addControl(new maplibregl.NavigationControl(), 'top-right');
                fullMap.on('click', handleFullMapClickForPin);
            } else {
                fullMap = L.map('fullTrackingMap', { zoomControl: true });
                fullMap._baseLayers = createBaseLayersForMap(fullMap);
                applyMapStyle(fullMap._baseLayers);
                fullMap.on('click', handleFullMapClickForPin);
            }
        }
        const usingMapLibre = (typeof maplibregl !== 'undefined') && (fullMap instanceof maplibregl.Map);
        if (usingMapLibre) {
            if (fullMap.getLayer(fullMapRouteLayerId)) fullMap.removeLayer(fullMapRouteLayerId);
            if (fullMap.getSource(fullMapRouteSourceId)) fullMap.removeSource(fullMapRouteSourceId);
            if (fullMapRiderMarker) { fullMapRiderMarker.remove(); fullMapRiderMarker = null; }
            if (fullMapDestinationMarker) { fullMapDestinationMarker.remove(); fullMapDestinationMarker = null; }
            fullMap.resize();
        } else {
            if (fullMapRouteLine) { fullMap.removeLayer(fullMapRouteLine); fullMapRouteLine = null; }
            if (fullMapRiderMarker) { fullMap.removeLayer(fullMapRiderMarker); fullMapRiderMarker = null; }
            if (fullMapDestinationMarker) { fullMap.removeLayer(fullMapDestinationMarker); fullMapDestinationMarker = null; }
            fullMap.invalidateSize();
        }
        if (entry.destinationLatLng && hasValidDestinationLatLng(entry.destinationLatLng[0], entry.destinationLatLng[1])) {
            if (usingMapLibre) {
                const destEl = document.createElement('div');
                destEl.innerHTML = '<div style="width:30px;height:30px;border-radius:50%;background:#ef4444;border:2px solid #fff;box-shadow:0 1px 6px rgba(0,0,0,.35);display:flex;align-items:center;justify-content:center;color:#fff;"><i class="fas fa-store"></i></div>';
                fullMapDestinationMarker = new maplibregl.Marker({ element: destEl.firstChild }).setLngLat([entry.destinationLatLng[1], entry.destinationLatLng[0]]).addTo(fullMap);
                fullMap.easeTo({ center: [entry.destinationLatLng[1], entry.destinationLatLng[0]], zoom: 15, duration: 250 });
            } else {
                fullMapDestinationMarker = L.marker(entry.destinationLatLng, { icon: destinationIcon }).addTo(fullMap).bindPopup(`Destination: ${entry.customerName || 'Customer'}`);
                fullMap.setView(entry.destinationLatLng, 15);
            }
            document.getElementById('fullMapMeta').textContent = `Tracking delivery #${entry.deliveryId} • waiting for rider position...`;
            const chip = document.getElementById('fullMapStatusChip');
            if (chip) chip.textContent = `Delivery #${entry.deliveryId}: destination locked, waiting for rider GPS...`;
        } else {
            if (usingMapLibre) fullMap.easeTo({ center: [120.9842, 14.5995], zoom: 12, duration: 250 });
            else fullMap.setView([14.5995, 120.9842], 12);
            document.getElementById('fullMapMeta').textContent = `Tracking delivery #${entry.deliveryId} • destination not yet located`;
            const chip = document.getElementById('fullMapStatusChip');
            if (chip) chip.textContent = `Delivery #${entry.deliveryId}: destination not yet geocoded`;
        }
        if (entry.riderMarker) {
            const riderLatLng = entry.riderMarker.getLatLng();
            if (usingMapLibre && !fullMap.isStyleLoaded()) {
                fullMap.once('styledata', () => renderFullMapTracking(entry, riderLatLng.lat, riderLatLng.lng));
            } else {
                renderFullMapTracking(entry, riderLatLng.lat, riderLatLng.lng);
            }
        }
    }, 120);
}

function locateRiderNow() {
    const entry = queueTrackingMaps.get(Number(fullMapDeliveryId));
    if (!fullMap || !entry || !currentRiderLatLng) {
        Swal.fire('Not ready', 'Rider location is not available yet.', 'info');
        return;
    }
    const usingMapLibre = (typeof maplibregl !== 'undefined') && (fullMap instanceof maplibregl.Map);
    if (usingMapLibre) {
        fullMap.easeTo({ center: [currentRiderLatLng.lng, currentRiderLatLng.lat], zoom: 16, duration: 300 });
    } else {
        fullMap.setView([currentRiderLatLng.lat, currentRiderLatLng.lng], 16, { animate: true });
    }
    renderFullMapTracking(entry, currentRiderLatLng.lat, currentRiderLatLng.lng);
}

function fitRiderAndDestination() {
    const entry = queueTrackingMaps.get(Number(fullMapDeliveryId));
    if (!fullMap || !entry) return;
    if (currentRiderLatLng && entry.destinationLatLng && hasValidDestinationLatLng(entry.destinationLatLng[0], entry.destinationLatLng[1])) {
        const usingMapLibre = (typeof maplibregl !== 'undefined') && (fullMap instanceof maplibregl.Map);
        if (usingMapLibre) {
            const bounds = new maplibregl.LngLatBounds(
                [currentRiderLatLng.lng, currentRiderLatLng.lat],
                [entry.destinationLatLng[1], entry.destinationLatLng[0]]
            );
            fullMap.fitBounds(bounds, { padding: 30, maxZoom: 16, duration: 300 });
        } else {
            const bounds = L.latLngBounds([[currentRiderLatLng.lat, currentRiderLatLng.lng], entry.destinationLatLng]);
            fullMap.fitBounds(bounds, { padding: [30, 30], maxZoom: 16 });
        }
        renderFullMapTracking(entry, currentRiderLatLng.lat, currentRiderLatLng.lng);
        return;
    }
    if (entry.destinationLatLng && hasValidDestinationLatLng(entry.destinationLatLng[0], entry.destinationLatLng[1])) {
        const usingMapLibre = (typeof maplibregl !== 'undefined') && (fullMap instanceof maplibregl.Map);
        if (usingMapLibre) fullMap.easeTo({ center: [entry.destinationLatLng[1], entry.destinationLatLng[0]], zoom: 15, duration: 300 });
        else fullMap.setView(entry.destinationLatLng, 15);
    } else if (currentRiderLatLng) {
        const usingMapLibre = (typeof maplibregl !== 'undefined') && (fullMap instanceof maplibregl.Map);
        if (usingMapLibre) fullMap.easeTo({ center: [currentRiderLatLng.lng, currentRiderLatLng.lat], zoom: 16, duration: 300 });
        else fullMap.setView([currentRiderLatLng.lat, currentRiderLatLng.lng], 16);
    } else {
        Swal.fire('Not ready', 'Need rider and destination locations first.', 'info');
    }
}

async function initQueueMaps() {
    if (!RIDER_MAPS_ENABLED) return;
    const mapEls = document.querySelectorAll('.inline-map');
    if (!mapEls.length || typeof L === 'undefined') return;

    for (const el of mapEls) {
        const deliveryId = Number(el.dataset.deliveryId || 0);
        const address = (el.dataset.address || '').trim();
        const customerName = (el.dataset.customerName || 'Customer').trim();
        const dataDestLat = Number(el.dataset.destinationLat || '');
        const dataDestLng = Number(el.dataset.destinationLng || '');
        const metaEl = document.getElementById(`inline-map-meta-${deliveryId}`);

        const map = L.map(el, { zoomControl: true }).setView([14.5995, 120.9842], 12);
        const baseLayers = createBaseLayersForMap(map);
        applyMapStyle(baseLayers);

        const entry = {
            map, deliveryId, address, customerName,
            baseLayers,
            riderMarker: null, destinationMarker: null, routeLine: null, destinationLatLng: null,
            initialFitDone: false, fullInitialFitDone: false,
            prevRiderLatLng: null, lastRerouteAt: 0
        };
        queueTrackingMaps.set(deliveryId, entry);

        try {
            if (hasValidDestinationLatLng(dataDestLat, dataDestLng)) {
                entry.destinationLatLng = [dataDestLat, dataDestLng];
                entry.destinationMarker = L.marker(entry.destinationLatLng, { icon: destinationIcon }).addTo(map).bindPopup(`Destination: ${entry.customerName}`);
                map.setView(entry.destinationLatLng, 15);
                if (metaEl) metaEl.textContent = 'Destination loaded from saved coordinates.';
            } else {
                const resolved = await geocodeViaProxy(address, currentRiderLatLng?.lat ?? null, currentRiderLatLng?.lng ?? null);
                if (resolved) {
                    entry.destinationLatLng = [resolved.lat, resolved.lng];
                    entry.destinationMarker = L.marker(entry.destinationLatLng, { icon: destinationIcon }).addTo(map).bindPopup(`Destination: ${entry.customerName}`);
                    map.setView(entry.destinationLatLng, 15);
                    if (metaEl) {
                        if (resolved.confidence === 'low') {
                            metaEl.textContent = 'Destination resolved (low confidence). Verify address or set exact pin.';
                        } else {
                            metaEl.textContent = `Destination resolved (${resolved.confidence} confidence).`;
                        }
                    }
                } else if (metaEl) {
                    metaEl.textContent = 'Destination unavailable. Please verify delivery address.';
                }
            }
        } catch (e) {
            if (metaEl) metaEl.textContent = 'Map loaded, but destination lookup failed.';
        }

        setTimeout(() => map.invalidateSize(), 120);
    }

    if (!navigator.geolocation) return;
    queueGeoWatchId = navigator.geolocation.watchPosition(
        async (pos) => {
            const rawLat = pos.coords.latitude;
            const rawLng = pos.coords.longitude;
            const speedMps = (typeof pos.coords.speed === 'number') ? pos.coords.speed : null;
            const snapped = await snapToRoad(rawLat, rawLng);
            const lat = snapped.lat;
            const lng = snapped.lng;
            currentRiderLatLng = { lat, lng };
            queueTrackingMaps.forEach((entry, deliveryId) => {
                renderInlineTracking(entry, lat, lng, speedMps);
                renderFullMapTracking(entry, lat, lng, speedMps);
            });
        },
        () => {
            queueTrackingMaps.forEach((entry, deliveryId) => {
                const metaEl = document.getElementById(`inline-map-meta-${deliveryId}`);
                if (metaEl) metaEl.textContent = 'Location permission denied. Enable GPS to track rider.';
            });
        },
        { enableHighAccuracy: true, timeout: 10000, maximumAge: 5000 }
    );
}

document.querySelectorAll('.nav-tab-rider').forEach(tab => {
    tab.addEventListener('click', () => {
        switchToTab(tab.getAttribute('data-tab'));
    });
});

window.addEventListener('hashchange', () => {
    const tab = getInitialTab();
    if (tab) switchToTab(tab);
});

function openDetailModal(deliveryId, orderId) {
    currentDeliveryId = deliveryId;
    currentOrderId = orderId;
    fetch(`../api/get_delivery_details.php?delivery_id=${deliveryId}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                Swal.fire('Error', data.message || 'Failed to load delivery', 'error');
                return;
            }
            deliveryData = data;
            const d = data.delivery || {};
            const detailStatus = (d.delivery_status || '').toString();
            const allowCancel = detailStatus === 'Scheduled' || detailStatus === 'In Transit';
            const addr = d.delivery_address || d.order_delivery_address || d.customer_address || '';
            const custName = d.customer_name || 'Customer';
            document.getElementById('detailCustomerName').textContent = custName;
            document.getElementById('detailCustomerPhone').innerHTML = d.phone_number ? '<i class="fas fa-phone-alt me-1"></i>' + d.phone_number : '';
            document.getElementById('detailAddress').innerHTML = addr ? '<i class="fas fa-map-marker-alt"></i> <span>' + addr + '</span>' : '';
            const totalAmt = parseFloat(d.total_amount || 0);
            document.getElementById('detailTotalDisplay').textContent = '₱' + totalAmt.toLocaleString('en-PH', {minimumFractionDigits: 0});
            document.getElementById('amountToCollect').value = totalAmt > 0 ? totalAmt : '';
            const isAr = d.is_ar == 1;
            const collectGroup = document.getElementById('collectInputGroup');
            const arNote = document.getElementById('arCollectNote');
            const arReveal = document.getElementById('arRevealLink');
            if (isAr) {
                if (collectGroup) collectGroup.style.display = 'none';
                document.getElementById('amountToCollect').value = '0';
                if (arNote) arNote.style.display = 'flex';
                if (arReveal) arReveal.style.display = 'block';
            } else {
                if (collectGroup) collectGroup.style.display = 'block';
                if (arNote) arNote.style.display = 'none';
                if (arReveal) arReveal.style.display = 'none';
            }
            const sel = document.getElementById('deliveredTo');
            sel.innerHTML = '<option value="">-- Select customer --</option><option value="' + (custName ? custName.replace(/"/g, '&quot;') : '') + '" selected>' + (custName || 'Customer') + '</option><option value="_other_">Other (enter name)</option>';
            let html = '';
            (data.items || []).forEach((item, i) => {
                const unitBadge = item.unit ? `<span class="badge rounded-pill bg-indigo-100 text-indigo-700 border border-indigo-300 fw-semibold px-2 py-1" style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.02em;">${item.unit}</span>` : '';
                const receivedValue = item.received_qty ?? item.ordered_qty ?? 0;
                html += `<div class="item-checklist-card bg-white rounded-[16px] p-4 mb-3 border border-slate-200 shadow-sm">
                    <div class="d-flex justify-content-between align-items-start gap-3">
                        <div class="flex-grow-1">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <h6 class="fw-bold text-dark mb-0" style="font-size: 1.05rem; font-family: 'Plus Jakarta Sans', sans-serif;">${item.product_name || 'Item'}</h6>
                                ${unitBadge}
                            </div>
                            <p class="text-slate-500 mb-0" style="font-size: 0.875rem;">Ordered: <span class="fw-semibold text-slate-700">${item.ordered_qty || 0} ${item.unit || 'Pieces'}</span></p>
                            <input type="hidden" name="dd_${i}_id" value="${item.delivery_detail_id}">
                        </div>
                        <div class="text-end">
                            <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-2 d-block">Received</label>
                            <div class="position-relative">
                                <input class="form-control text-center fw-semibold bg-slate-50 border-slate-200 rounded-lg" type="number" step="0.01" min="0" name="received_${i}" value="${receivedValue}" style="width: 100px; height: 42px; font-size: 1rem; border-radius: 10px;">
                            </div>
                        </div>
                    </div>
                </div>`;
            });
            if ((data.items || []).length) {
                html += `<div class="d-flex align-items-start gap-2 mt-4 p-3 rounded-[12px] bg-slate-50 border border-slate-100">
                    <i class="fas fa-info-circle text-slate-400 mt-0.5"></i>
                    <p class="text-slate-500 mb-0" style="font-size: 0.8125rem;">Report product damage using <span class="fw-semibold text-slate-700">Report delivery damage</span> on the delivery card (not here).</p>
                </div>`;
            }
            document.getElementById('detailItems').innerHTML = html || '<p class="text-muted">No items</p>';

            document.getElementById('deliveredToOther').value = '';
            document.getElementById('deliveredToOther').style.display = 'none';
            document.getElementById('deliveryRemarks').value = '';
            document.getElementById('proofPhoto').value = '';
            document.getElementById('proofPreview').style.display = 'none';
            const proofPreviewGrid = document.getElementById('proofPreviewGrid');
            if (proofPreviewGrid) proofPreviewGrid.innerHTML = '';
            document.getElementById('btnCancelDeliveryModal').style.display = allowCancel ? 'inline-flex' : 'none';
            document.getElementById('btnConfirmDelivery').style.display = detailStatus === 'In Transit' ? 'inline-flex' : 'none';
            detailModal.show();
        })
        .catch(err => Swal.fire('Error', 'Network error', 'error'));
}

function toggleArCollectInput() {
    const collectGroup = document.getElementById('collectInputGroup');
    const arReveal = document.getElementById('arRevealLink');
    if (!collectGroup || !arReveal) return;
    collectGroup.style.display = 'block';
    arReveal.style.display = 'none';
    document.getElementById('amountToCollect').value = '0';
    document.getElementById('amountToCollect').focus();
}

function formatWholeNumber(value) {
    return new Intl.NumberFormat('en-PH', { maximumFractionDigits: 0 }).format(Number(value || 0));
}

function ddrUpdateQtyHint() {
    const sel = document.getElementById('ddr_order_detail_id');
    const hint = document.getElementById('ddr_qty_hint');
    const qty = document.getElementById('ddr_qty');
    if (!sel || !hint) return;
    const opt = sel.options[sel.selectedIndex];
    if (!opt) {
        hint.textContent = '';
        return;
    }
    const max = parseInt(opt.dataset.max || '0', 10) || 0;
    hint.textContent = max > 0 ? 'Maximum for this line: ' + formatWholeNumber(max) : '';
    if (qty && (parseInt(qty.value || '0', 10) || 0) > max) qty.value = String(max);
}

function openDamageReportModal(deliveryId) {
    if (!damageReportModal) return;
    document.getElementById('ddr_delivery_id').value = deliveryId;
    document.getElementById('ddr_qty').value = '';
    document.getElementById('ddr_reason').value = '';
    const photo = document.getElementById('ddr_photo');
    if (photo) photo.value = '';
    fetch(`../api/delivery_damage_backend.php?action=order_lines&delivery_id=${deliveryId}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                Swal.fire('Error', data.error || 'Could not load order lines', 'error');
                return;
            }
            const sel = document.getElementById('ddr_order_detail_id');
            sel.innerHTML = '';
            (data.items || []).forEach(it => {
                const rem = parseInt(it.remaining_qty || 0, 10) || 0;
                if (rem <= 0) return;
                const opt = document.createElement('option');
                opt.value = it.Order_detail_ID;
                const unit = (it.unit_name || '').trim();
                opt.textContent = (it.product_name || 'Item') + ' (max ' + formatWholeNumber(rem) + (unit ? ' ' + unit : '') + ')';
                opt.dataset.max = String(rem);
                sel.appendChild(opt);
            });
            if (!sel.options.length) {
                Swal.fire('Nothing to report', 'Remaining quantity is zero for all lines on this order.', 'info');
                return;
            }
            sel.onchange = ddrUpdateQtyHint;
            ddrUpdateQtyHint();
            damageReportModal.show();
        })
        .catch(() => Swal.fire('Error', 'Network error', 'error'));
}

function submitDamageReport() {
    const fd = new FormData();
    fd.append('action', 'submit');
    if (window.csrfToken) fd.append('csrf_token', window.csrfToken);
    fd.append('delivery_id', document.getElementById('ddr_delivery_id').value);
    fd.append('order_detail_id', document.getElementById('ddr_order_detail_id').value);
    fd.append('damaged_qty', document.getElementById('ddr_qty').value);
    fd.append('reason', document.getElementById('ddr_reason').value.trim());
    const sel = document.getElementById('ddr_order_detail_id');
    const opt = sel.options[sel.selectedIndex];
    const max = opt ? (parseInt(opt.dataset.max || '0', 10) || 0) : 0;
    const q = parseInt(document.getElementById('ddr_qty').value || '0', 10) || 0;
    if (!q || q <= 0) {
        Swal.fire('Quantity', 'Enter a whole-number damaged quantity.', 'warning');
        return;
    }
    if (max > 0 && q > max) {
        Swal.fire('Quantity', 'Damaged quantity cannot exceed ' + formatWholeNumber(max) + ' for this line.', 'warning');
        return;
    }
    const photoEl = document.getElementById('ddr_photo');
    if (photoEl && photoEl.files && photoEl.files[0]) {
        fd.append('photo', photoEl.files[0]);
    }
    const btn = document.getElementById('ddr_submit_btn');
    if (btn) { btn.disabled = true; }
    fetch('../api/delivery_damage_backend.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (btn) { btn.disabled = false; }
            if (data.success) {
                if (damageReportModal) damageReportModal.hide();
                Swal.fire('Submitted', 'Your report is pending staff review.', 'success').then(() => window.location.reload());
            } else {
                Swal.fire('Error', data.error || 'Submit failed', 'error');
            }
        })
        .catch(() => {
            if (btn) { btn.disabled = false; }
            Swal.fire('Error', 'Network error', 'error');
        });
}

function openReadOnlyModal(deliveryId, orderId) {
    fetch(`../api/get_delivery_details.php?delivery_id=${deliveryId}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                Swal.fire('Error', data.message || 'Failed to load delivery', 'error');
                return;
            }
            const d = data.delivery || {};
            const addr = d.delivery_address || d.order_delivery_address || d.customer_address || '';
            const custName = d.customer_name || 'Customer';
            document.getElementById('voCustomerName').textContent = custName;
            document.getElementById('voCustomerPhone').textContent = d.phone_number || '';
            document.getElementById('voAddress').textContent = addr || '';
            document.getElementById('voTotalAmount').textContent = '₱' + parseFloat(d.total_amount || 0).toLocaleString('en-PH', {minimumFractionDigits: 0});

            const items = data.items || [];

            // Show proof images from normalized delivery_proofs table (fallback to legacy).
            const proofs = Array.isArray(data.proofs) ? data.proofs : [];
            const normalizedPaths = proofs
                .map((p) => (p && p.file_path ? String(p.file_path).trim() : ''))
                .filter(Boolean);
            const legacyProofPath = (items.find(i => (i.proof_delivery || '').trim() !== '')?.proof_delivery || '').trim();
            const proofPaths = normalizedPaths.length ? normalizedPaths : (legacyProofPath ? [legacyProofPath] : []);
            const voProofBlock = document.getElementById('voProofBlock');
            const voProofGallery = document.getElementById('voProofGallery');
            if (proofPaths.length > 0 && voProofGallery) {
                voProofGallery.innerHTML = proofPaths.map((path) => {
                    const safePath = String(path).replace(/^\/+/, '').replace(/"/g, '&quot;');
                    const src = '../' + safePath;
                    return `<img src="${src}" alt="Proof" class="w-full h-28 object-cover rounded-[12px] shadow-sm border border-slate-200 cursor-pointer" onclick="window.open('${src}', '_blank')">`;
                }).join('');
                voProofBlock.style.display = 'block';
            } else {
                voProofBlock.style.display = 'none';
                if (voProofGallery) voProofGallery.innerHTML = '';
            }

            const html = items.length ? items.map((item) => {
                const unitBadge = item.unit ? `<span class="badge rounded-pill bg-indigo-100 text-indigo-700 border border-indigo-300 fw-semibold px-2 py-1" style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.02em;">${item.unit}</span>` : '';
                const receivedQty = item.received_qty ?? item.ordered_qty ?? 0;
                return `<div class="item-checklist-card bg-white rounded-[16px] p-4 mb-3 border border-slate-200 shadow-sm">
                    <div class="d-flex justify-content-between align-items-start gap-3">
                        <div class="flex-grow-1">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <h6 class="fw-bold text-dark mb-0" style="font-size: 1.05rem; font-family: 'Plus Jakarta Sans', sans-serif;">${item.product_name || 'Item'}</h6>
                                ${unitBadge}
                            </div>
                            <p class="text-slate-500 mb-0" style="font-size: 0.875rem;">Ordered: <span class="fw-semibold text-slate-700">${item.ordered_qty || 0} ${item.unit || 'Pieces'}</span></p>
                        </div>
                        <div class="text-end">
                            <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-2 d-block">Received</label>
                            <div class="fw-semibold text-dark" style="font-size: 1.125rem;">${receivedQty}</div>
                        </div>
                    </div>
                </div>`;
            }).join('') : '<p class="text-muted">No items</p>';
            document.getElementById('voItems').innerHTML = html;
            viewOnlyModal.show();
        })
        .catch(() => Swal.fire('Error', 'Network error', 'error'));
}

function buildDeliveryDetailsPayload() {
    const items = deliveryData?.items || [];
    const details = [];
    items.forEach((item, i) => {
        const ddId = item.delivery_detail_id;
        if (!ddId) return;
        const received = document.querySelector(`input[name="received_${i}"]`);
        let receivedQty = parseFloat(received?.value || 0) || 0;
        if (receivedQty < 0) receivedQty = 0;
        details.push({
            delivery_detail_id: ddId,
            received_qty: receivedQty,
            damage_qty: 0,
            remarks: '',
            ordered_qty: parseFloat(item.ordered_qty) || receivedQty
        });
    });
    return details;
}

document.getElementById('btnConfirmDelivery').addEventListener('click', () => {
    const proofInput = document.getElementById('proofPhoto');
    if (!proofInput.files || !proofInput.files[0]) {
        Swal.fire('Photo required', 'Please take or upload a photo as proof of delivery.', 'warning');
        return;
    }
    const sel = document.getElementById('deliveredTo');
    let deliveredTo = sel.value === '_other_' ? document.getElementById('deliveredToOther').value.trim() : (sel.value || '').trim();
    if (!deliveredTo) {
        Swal.fire('Required', 'Please select or enter the name of the person who received/paid.', 'warning');
        return;
    }
    const amountToCollect = parseFloat(document.getElementById('amountToCollect').value) || 0;

    if (deliveryData?.delivery?.is_ar == 1 && amountToCollect <= 0) {
        let payload;
        try { payload = buildDeliveryDetailsPayload(); } catch (e) {
            Swal.fire('Fix required', (e && e.message) ? e.message : 'Please check received quantities.', 'warning');
            return;
        }
        window.__delivery_details_payload = payload;
        doConfirmDelivery(deliveredTo, 0);
        return;
    }

    document.getElementById('codAmount').textContent = '₱' + amountToCollect.toLocaleString('en-PH', {minimumFractionDigits: 0});

    let payload;
    try {
        payload = buildDeliveryDetailsPayload();
    } catch (e) {
        Swal.fire('Fix required', (e && e.message) ? e.message : 'Please check received quantities.', 'warning');
        return;
    }

    // Store payload so the confirm step uses the same validated values.
    window.__delivery_details_payload = payload;

    document.getElementById('btnCodConfirmed').onclick = () => doConfirmDelivery(deliveredTo, amountToCollect);
    detailModal.hide();
    codModal.show();
});

function doConfirmDelivery(deliveredTo, amountToCollect) {
    const formData = new FormData();
    if (window.csrfToken) {
        formData.append('csrf_token', window.csrfToken);
    }
    formData.append('action', 'confirm_delivery');
    formData.append('delivery_id', currentDeliveryId);
    formData.append('delivered_to', deliveredTo);
    formData.append('amount_to_collect', amountToCollect);
    formData.append('remarks', document.getElementById('deliveryRemarks').value);
    // Use payload created/validated before switching modals
    const payload = window.__delivery_details_payload || buildDeliveryDetailsPayload();
    formData.append('delivery_details', JSON.stringify(payload));
    const proofInput = document.getElementById('proofPhoto');
    if (proofInput.files && proofInput.files.length) {
        Array.from(proofInput.files).forEach((file) => {
            if (file && file.type && file.type.startsWith('image/')) {
                formData.append('proof_photo[]', file);
            }
        });
    }

    codModal.hide();
    Swal.fire({ title: 'Processing...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

    fetch('../api/rider_dashboard_backend.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                Swal.fire('Success', 'Delivery confirmed! Collected: ₱' + (data.total_amount || 0).toLocaleString('en-PH', {minimumFractionDigits: 0}), 'success')
                    .then(() => location.reload());
            } else {
                Swal.fire('Error', data.message || 'Failed to confirm', 'error');
            }
        })
        .catch(() => Swal.fire('Error', 'Network error', 'error'));
}

let historyData = [];
let historyCurrentPage = 1;
const historyItemsPerPage = 10;

let queueData = [];
let queueCurrentPage = 1;
const queueItemsPerPage = 5;

function initQueuePagination() {
    const queueCards = document.querySelectorAll('#queueList .delivery-card');
    queueData = Array.from(queueCards);
    renderQueuePage();
}

function renderQueuePage() {
    const list = document.getElementById('queueList');
    const start = (queueCurrentPage - 1) * queueItemsPerPage;
    const end = start + queueItemsPerPage;
    const pageData = queueData.slice(start, end);
    const totalPages = Math.ceil(queueData.length / queueItemsPerPage) || 1;
    
    // Hide all cards first
    queueData.forEach(card => card.style.display = 'none');
    
    // Show only current page cards
    pageData.forEach(card => card.style.display = 'block');
    
    // Update pagination controls
    var infoEl = document.getElementById('queuePageInfo');
    if (infoEl) infoEl.textContent = 'Page ' + queueCurrentPage + ' of ' + totalPages;
    var prevEl = document.getElementById('queuePrev');
    if (prevEl) prevEl.disabled = queueCurrentPage <= 1;
    var nextEl = document.getElementById('queueNext');
    if (nextEl) nextEl.disabled = queueCurrentPage >= totalPages;
}

function changeQueuePage(delta) {
    const totalPages = Math.ceil(queueData.length / queueItemsPerPage) || 1;
    const newPage = queueCurrentPage + delta;
    if (newPage >= 1 && newPage <= totalPages) {
        queueCurrentPage = newPage;
        renderQueuePage();
        document.getElementById('queueList').scrollTop = 0;
    }
}

function renderHistoryPage() {
    const list = document.getElementById('historyList');
    const start = (historyCurrentPage - 1) * historyItemsPerPage;
    const end = start + historyItemsPerPage;
    const pageData = historyData.slice(start, end);
    const totalPages = Math.ceil(historyData.length / historyItemsPerPage) || 1;
    
    if (historyData.length) {
        list.innerHTML = pageData.map((d, i) => {
            const actualIndex = start + i;
            const dateStr = d.actual_date ? new Date(d.actual_date).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' }) : '—';
            const orderMeta = d.order_id ? `Order #${d.order_id}` : '';
            const meta = [orderMeta, dateStr].filter(Boolean).join(' · ');
            const status = (d.status || '').toString() || 'Delivered';

            return `
            <div class="py-3.5 border-b border-slate-100 cursor-pointer hover:bg-slate-50/50 transition-colors" onclick='showHistoryDetail(${JSON.stringify(d).replace(/'/g, "&apos;")})'>
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="fw-bold mb-1 text-dark fs-6" style="font-family: 'Plus Jakarta Sans', sans-serif;">${(d.customer_name || 'Customer').replace(/</g, '&lt;')}</h5>
                        <p class="text-muted small mb-0" style="font-size: 0.8rem;">
                            ${orderMeta} · ${dateStr} · To: ${(d.delivered_to || d.customer_name || '—').replace(/</g, '&lt;')}
                        </p>
                    </div>
                    <div class="text-end">
                        <span class="d-inline-block px-2 py-0.5 rounded text-xs fw-bold text-emerald-700 bg-emerald-50 mb-1" style="font-size: 0.65rem; letter-spacing: 0.02em;">COMPLETED</span>
                        <div class="fw-black text-emerald-600 fs-5" style="font-family: 'Plus Jakarta Sans', sans-serif;">₱${parseFloat(d.total_amount || 0).toLocaleString('en-PH', { minimumFractionDigits: 0 })}</div>
                    </div>
                </div>
            </div>`;
        }).join('');
    } else {
        list.innerHTML = `
        <div class="py-5 text-center text-muted">
            <i class="fas fa-clock-rotate-left mb-3" style="font-size: 2rem; opacity: 0.5;"></i>
            <p class="mb-0 small">No delivered history yet</p>
            <p class="mb-0 text-muted" style="font-size: 0.75rem;">Your delivered orders will appear here after you confirm delivery.</p>
        </div>`;
    }
    
    // Update pagination controls
    document.getElementById('histPageInfo').textContent = `Page ${historyCurrentPage} of ${totalPages}`;
    document.getElementById('histPrev').disabled = historyCurrentPage <= 1;
    document.getElementById('histNext').disabled = historyCurrentPage >= totalPages;
}

function changeHistoryPage(delta) {
    const totalPages = Math.ceil(historyData.length / historyItemsPerPage) || 1;
    const newPage = historyCurrentPage + delta;
    if (newPage >= 1 && newPage <= totalPages) {
        historyCurrentPage = newPage;
        renderHistoryPage();
        document.getElementById('historyList').scrollTop = 0;
    }
}

function showHistoryDetail(d) {
    const customerName = d.customer_name || '—';
    const deliveredTo = d.delivered_to || '';
    
    document.getElementById('cdCustomer').textContent = customerName;
    
    // Only show "Received By" if it's different from customer name
    const deliveredToRow = document.getElementById('cdDeliveredToRow');
    if (deliveredTo && deliveredTo !== customerName && deliveredTo !== '—') {
        document.getElementById('cdDeliveredTo').textContent = deliveredTo;
        deliveredToRow.style.display = 'block';
    } else {
        deliveredToRow.style.display = 'none';
    }
    
    document.getElementById('cdAddress').textContent = d.delivery_address || '—';
    document.getElementById('cdAmount').textContent = '₱' + parseFloat(d.total_amount || 0).toLocaleString('en-PH', { minimumFractionDigits: 0 });
    document.getElementById('cdDeliveryId').textContent = '#' + (d.delivery_id || '—');
    const dateStr = d.actual_date ? new Date(d.actual_date).toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' }) : '—';
    document.getElementById('cdDate').textContent = dateStr;
    new bootstrap.Modal(document.getElementById('collectionDetailModal')).show();
}

function loadDeliveredHistory() {
    fetch('../api/rider_dashboard_backend.php?action=get_delivered_history')
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                historyData = data.deliveries || [];
                historyCurrentPage = 1; // Reset to first page on load
                const count = data.delivery_count || historyData.length || 0;
                document.getElementById('historyTotalCount').textContent = count.toLocaleString('en-PH');
                document.getElementById('historyCodTotal').textContent = '₱' + (data.total_cod || 0).toLocaleString('en-PH', { minimumFractionDigits: 0 });
                renderHistoryPage();
            }
        })
        .catch(err => {
            console.error('Failed to load delivery history:', err);
        });
}

function initSlideToComplete() {
    document.querySelectorAll('.js-slide-complete').forEach((wrap) => {
        const thumb = wrap.querySelector('.slide-thumb');
        if (!thumb) return;
        let startX = 0;
        let currentX = 0;
        let dragging = false;
        let done = false;

        const maxX = () => Math.max(wrap.clientWidth - thumb.clientWidth - 6, 0);
        const setX = (x) => { thumb.style.left = (3 + x) + 'px'; };
        const reset = () => { currentX = 0; setX(0); };

        const onMove = (clientX) => {
            if (!dragging || done) return;
            const delta = clientX - startX;
            currentX = Math.max(0, Math.min(delta, maxX()));
            setX(currentX);
        };

        const onEnd = () => {
            if (!dragging || done) return;
            dragging = false;
            if (currentX >= maxX() * 0.92) {
                done = true;
                wrap.classList.add('complete');
                const deliveryId = parseInt(wrap.dataset.deliveryId || '0', 10);
                const orderId = parseInt(wrap.dataset.orderId || '0', 10);
                setX(maxX());
                openDetailModal(deliveryId, orderId);
                setTimeout(() => { done = false; wrap.classList.remove('complete'); reset(); }, 1200);
            } else {
                reset();
            }
        };

        thumb.addEventListener('pointerdown', (e) => {
            if (done) return;
            dragging = true;
            startX = e.clientX;
            thumb.setPointerCapture(e.pointerId);
        });
        thumb.addEventListener('pointermove', (e) => onMove(e.clientX));
        thumb.addEventListener('pointerup', onEnd);
        thumb.addEventListener('pointercancel', onEnd);
        reset();
    });
}

function sendOnTheWaySms(deliveryId, customerName, customerPhone) {
    if (!customerPhone) {
        Swal.fire('No Phone', 'This customer has no phone number on file.', 'warning');
        return;
    }

    Swal.fire({
        title: 'Send On-the-Way SMS?',
        html: `Send delivery update SMS to <b>${customerName}</b><br><small>${customerPhone}</small>`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Send SMS',
        cancelButtonText: 'Cancel'
    }).then((res) => {
        if (!res.isConfirmed) return;

        const formData = new FormData();
        if (window.csrfToken) {
            formData.append('csrf_token', window.csrfToken);
        }
        formData.append('action', 'send_on_the_way_sms');
        formData.append('delivery_id', deliveryId);

        Swal.fire({ title: 'Sending...', text: 'Please wait while sending SMS reminder.', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        fetch('../api/rider_dashboard_backend.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    const quotaText = (typeof data.quota_remaining !== 'undefined' && data.quota_remaining !== null)
                        ? `\nFree quota remaining: ${data.quota_remaining}`
                        : '';
                    Swal.fire('Sent', (data.message || 'On-the-way SMS reminder sent.') + quotaText, 'success');
                } else {
                    Swal.fire('Failed', data.message || 'Failed to send SMS reminder.', 'error');
                }
            })
            .catch(() => Swal.fire('Error', 'Network error while sending SMS.', 'error'));
    });
}

(function riderViewFlashFromConfig() {
    var cfg = typeof window.RIDER_VIEW_CONFIG === 'object' && window.RIDER_VIEW_CONFIG ? window.RIDER_VIEW_CONFIG : {};
    if (cfg.flashSuccess) {
        Swal.fire({ icon: 'success', title: 'Updated!', text: cfg.flashSuccess, timer: 2000, showConfirmButton: false });
    }
    if (cfg.flashError) {
        Swal.fire({ icon: 'error', title: 'Error', text: cfg.flashError });
    }
})();

// PWA install prompt
(function () {
    const installBtn = document.getElementById('installPwaBtn');
    if (!installBtn) return;
    if (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) {
        installBtn.style.display = 'none';
        return;
    }

    let deferredPrompt = null;

    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        deferredPrompt = e;
        installBtn.style.display = 'block';
    });

    window.addEventListener('appinstalled', () => {
        deferredPrompt = null;
        installBtn.style.display = 'none';
    });

    installBtn.addEventListener('click', async () => {
        if (!deferredPrompt) return;
        deferredPrompt.prompt();
        try {
            await deferredPrompt.userChoice;
        } catch (e2) {}
        deferredPrompt = null;
        installBtn.style.display = 'none';
    });
})();

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('../sw.js').catch(() => {});
    });
}
const initialTab = getInitialTab();
if (initialTab) switchToTab(initialTab);
initSlideToComplete();
if (RIDER_MAPS_ENABLED) {
    refreshMapStyleButton();
}
initQueueMaps();
const fullMapModalEl = document.getElementById('fullMapModal');
if (fullMapModalEl && RIDER_MAPS_ENABLED) {
fullMapModalEl.addEventListener('shown.bs.modal', () => {
    const resizeMap = () => {
        if (!fullMap) return;
        const usingMapLibre = (typeof maplibregl !== 'undefined') && (fullMap instanceof maplibregl.Map);
        if (usingMapLibre) fullMap.resize();
        else fullMap.invalidateSize(true);
    };
    setTimeout(resizeMap, 150);
    setTimeout(resizeMap, 350);
    setTimeout(resizeMap, 650);
});
fullMapModalEl.addEventListener('hide.bs.modal', () => {
    const active = document.activeElement;
    if (active && typeof active.blur === 'function') {
        active.blur();
    }
});
}
const fullMapSearchInputEl = document.getElementById('fullMapSearchInput');
if (fullMapSearchInputEl) {
    fullMapSearchInputEl.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            searchDestinationInFullMap();
        }
    });
}

// Initialize queue pagination
initQueuePagination();

// REAL-TIME NOTIFICATION & AUTO-QUEUE SYNC
let storedDelIds = JSON.parse(localStorage.getItem('rider_known_deliveries') || '[]');
let phpDelIds = Array.isArray(window.__riderDeliveryIds) ? window.__riderDeliveryIds.slice() : [];
let knownDeliveryIds = new Set([...storedDelIds, ...phpDelIds]);
localStorage.setItem('rider_known_deliveries', JSON.stringify([...knownDeliveryIds]));

let storedReadyIds = JSON.parse(localStorage.getItem('rider_known_ready_deliveries') || '[]');
let phpReadyIds = Array.isArray(window.__riderReadyDeliveryIds) ? window.__riderReadyDeliveryIds.slice() : [];
let knownReadyIds = new Set([...storedReadyIds, ...phpReadyIds]);
localStorage.setItem('rider_known_ready_deliveries', JSON.stringify([...knownReadyIds]));

let knownRemittanceUpdates = new Set(JSON.parse(localStorage.getItem('rider_known_remittance_updates') || '[]'));
let knownDamageReviewUpdates = new Set(JSON.parse(localStorage.getItem('rider_known_damage_reviews') || '[]'));
let realtimeUpdateSeeded = localStorage.getItem('rider_realtime_updates_seeded') === '1';

let notificationCount = parseInt(localStorage.getItem('rider_notif_count') || '0', 10);
let notificationHtml = localStorage.getItem('rider_notif_html') || '';

if (notificationCount > 0) {
    const btn = document.getElementById('notificationDropdown');
    const badge = document.getElementById('notificationBadge');
    const notifList = document.getElementById('notificationList');
    if (btn && badge && notifList) {
        btn.classList.add('has-new');
        badge.textContent = notificationCount;
        badge.style.display = 'inline-block';
        notifList.innerHTML = notificationHtml;
    }
}

const ToastNotification = Swal.mixin({
    toast: true,
    position: 'top-end',
    showConfirmButton: false,
    timer: 5000,
    timerProgressBar: true,
    didOpen: (toast) => {
        toast.addEventListener('mouseenter', Swal.stopTimer);
        toast.addEventListener('mouseleave', Swal.resumeTimer);
    }
});

function addRiderNotification(title, description, iconClass = 'fa-bell', onClickJs = 'return false;') {
    notificationCount++;
    const notifList = document.getElementById('notificationList');
    const noNotif = document.getElementById('noNotifItem');
    if (noNotif) noNotif.style.display = 'none';

    const itemHtml = `<a href="#" class="notif-item" onclick="${onClickJs}">
        <span class="notif-title"><i class="fas ${iconClass} text-primary me-1"></i> ${title}</span>
        <span class="notif-desc">${description}</span>
    </a>`;
    if (notifList) {
        notifList.insertAdjacentHTML('afterbegin', itemHtml);
    }

    const btn = document.getElementById('notificationDropdown');
    const badge = document.getElementById('notificationBadge');
    if (btn && badge) {
        btn.classList.add('has-new');
        badge.textContent = notificationCount;
        badge.style.display = 'inline-block';
    }

    localStorage.setItem('rider_notif_count', notificationCount.toString());
    if (notifList) localStorage.setItem('rider_notif_html', notifList.innerHTML);

    try {
        let audio = new Audio('../assets/sounds/notification.mp3');
        audio.play().catch(() => {});
    } catch (e) {}
}

function persistRiderRealtimeState() {
    localStorage.setItem('rider_known_remittance_updates', JSON.stringify([...knownRemittanceUpdates]));
    localStorage.setItem('rider_known_damage_reviews', JSON.stringify([...knownDamageReviewUpdates]));
}

function registerRemittanceUpdate(deliveryId, status) {
    const key = `${parseInt(deliveryId || '0', 10) || 0}:${String(status || '').toLowerCase()}`;
    if (knownRemittanceUpdates.has(key)) {
        return false;
    }
    knownRemittanceUpdates.add(key);
    persistRiderRealtimeState();
    return true;
}

function registerDamageReviewUpdate(reportId, status) {
    const key = `${parseInt(reportId || '0', 10) || 0}:${String(status || '').toLowerCase()}`;
    if (knownDamageReviewUpdates.has(key)) {
        return false;
    }
    knownDamageReviewUpdates.add(key);
    persistRiderRealtimeState();
    return true;
}

function handleRiderRemittanceUpdate(data, shouldNotify = true) {
    if (!data) return;
    const deliveryId = parseInt(data.delivery_id || '0', 10) || 0;
    const status = String(data.status || 'Completed').toLowerCase();

    // Rider marked as Remitted — silently refresh queue, no notification popup
    if (status === 'remitted') {
        if (shouldNotify) {
            registerRemittanceUpdate(deliveryId, status);
            refreshQueueAjax(true);
        }
        return;
    }

    // Only 'Completed' means the cashier actually recorded the sale
    if (!registerRemittanceUpdate(deliveryId, status)) {
        return;
    }
    if (!shouldNotify) {
        return;
    }

    addRiderNotification(
        `Remittance Recorded #${deliveryId || ''}`.trim(),
        'Cashier recorded your remitted money.',
        'fa-hand-holding-dollar',
        'refreshQueueAjax(true); return false;'
    );
    ToastNotification.fire({
        icon: 'success',
        title: 'Remittance recorded by cashier.'
    });
    refreshQueueAjax(true);
}

function handleRiderDamageReviewUpdate(data, shouldNotify = true) {
    if (!data) return;
    const status = String(data.status || '').toLowerCase();
    const reportId = parseInt(data.report_id || '0', 10) || 0;
    if (!status || !registerDamageReviewUpdate(reportId, status)) {
        return;
    }
    if (!shouldNotify) {
        return;
    }

    const isApproved = status === 'approved';
    addRiderNotification(
        `Damage Report ${isApproved ? 'Approved' : 'Rejected'}`,
        `Report #${reportId || ''} for Order #${data.order_id || ''} was ${isApproved ? 'approved' : 'rejected'}.`,
        isApproved ? 'fa-circle-check' : 'fa-circle-xmark',
        "showTab('damage_reports'); return false;"
    );
    ToastNotification.fire({
        icon: isApproved ? 'success' : 'warning',
        title: `Damage report ${isApproved ? 'approved' : 'rejected'}.`
    });
}

function refreshQueueAjax(autoSync = true) {
    if(!autoSync) {
        Swal.fire({ title: 'Syncing Queue...', toast: true, position: 'top-end', showConfirmButton: false, timer: 1500 });
        notificationCount = 0;
        localStorage.setItem('rider_notif_count', '0');
        localStorage.setItem('rider_notif_html', '<div id="noNotifItem" class="p-3 text-center text-muted small">No new notifications</div>');
        
        document.getElementById('notificationBadge').style.display = 'none';
        document.getElementById('notificationDropdown').classList.remove('has-new');
        document.getElementById('notificationList').innerHTML = '<div id="noNotifItem" class="p-3 text-center text-muted small">No new notifications</div>';
    }

    fetch(window.location.href)
        .then(r => r.text())
        .then(html => {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            
            ['tab-dashboard', 'tab-queue', 'tab-cancelled'].forEach(tabId => {
                const newTab = doc.getElementById(tabId);
                const oldTab = document.getElementById(tabId);
                if (newTab && oldTab) oldTab.innerHTML = newTab.innerHTML;
            });
            
            initSlideToComplete();
            if (typeof queueTrackingMaps !== 'undefined' && queueTrackingMaps.clear) queueTrackingMaps.clear();
            initQueueMaps();
        });
}

setInterval(() => {
    fetch('../api/rider_dashboard_backend.php?action=check_new_deliveries')
        .then(r => r.json())
        .then(data => {
            if (data.success && data.deliveries && data.deliveries.length > 0) {
                let hasNew = false;
                let hasReadyUpdate = false;
                const notifList = document.getElementById('notificationList');
                const noNotif = document.getElementById('noNotifItem');
                
                data.deliveries.forEach(d => {
                    let delId = parseInt(d.Delivery_ID, 10);
                    let ordId = parseInt(d.Order_ID, 10) || 0;
                    let prepStatus = String(d.prep_status || '').toLowerCase();
                    
                    // 1. Check for newly assigned delivery
                    if (!knownDeliveryIds.has(delId)) {
                        hasNew = true;
                        knownDeliveryIds.add(delId);
                        
                        ToastNotification.fire({
                            icon: 'info',
                            title: 'New Delivery Assigned!',
                            text: `Delivery #${delId} has been added to your queue.`
                        });
                        
                        try {
                            let audio = new Audio('../assets/sounds/notification.mp3');
                            audio.play().catch(e => {});
                        } catch(e) {}
                        
                        addRiderNotification(`New Delivery #${delId}`, 'Auto-synced into your queue.', 'fa-box', 'refreshQueueAjax(false); return false;');
                    }
                    
                    // 2. Check if preparation is ready for pick-up
                    var prepReady = prepStatus === 'ready';
                    if (prepReady && !knownReadyIds.has(delId)) {
                        hasReadyUpdate = true;
                        knownReadyIds.add(delId);
                        
                        ToastNotification.fire({
                            icon: 'success',
                            title: 'Order Ready for Pickup!',
                            text: `Order #${ordId} (Delivery #${delId}) is now ready for pickup.`
                        });
                        
                        try {
                            let audio = new Audio('../assets/sounds/notification.mp3');
                            audio.play().catch(e => {});
                        } catch(e) {}
                        
                        addRiderNotification(`Delivery #${delId} Ready`, 'Order is ready to pick up.', 'fa-check-circle', 'refreshQueueAjax(false); return false;');
                    }
                });
                
                if (hasNew || hasReadyUpdate) {
                    localStorage.setItem('rider_known_deliveries', JSON.stringify([...knownDeliveryIds]));
                    localStorage.setItem('rider_known_ready_deliveries', JSON.stringify([...knownReadyIds]));
                    localStorage.setItem('rider_notif_count', notificationCount.toString());
                    if (notifList) localStorage.setItem('rider_notif_html', notifList.innerHTML);

                    const btn = document.getElementById('notificationDropdown');
                    const badge = document.getElementById('notificationBadge');
                    if (btn && badge) {
                        btn.classList.add('has-new');
                        badge.textContent = notificationCount;
                        badge.style.display = 'inline-block';
                    }

                    // AUTO REFRESH SILENTLY!
                    refreshQueueAjax(true);
                }
            }
        })
        .catch(err => console.error('Error checking deliveries:', err));
}, 10000);

function pollRiderRealtimeUpdates() {
    fetch('../api/rider_dashboard_backend.php?action=check_realtime_updates')
        .then(r => r.json())
        .then(data => {
            if (!data || !data.success) return;
            const remittances = Array.isArray(data.remittances) ? data.remittances : [];
            const damageReviews = Array.isArray(data.damage_reviews) ? data.damage_reviews : [];
            const shouldNotify = realtimeUpdateSeeded;

            remittances.forEach(item => handleRiderRemittanceUpdate(item, shouldNotify));
            damageReviews.forEach(item => handleRiderDamageReviewUpdate(item, shouldNotify));

            if (!realtimeUpdateSeeded) {
                realtimeUpdateSeeded = true;
                localStorage.setItem('rider_realtime_updates_seeded', '1');
            }
        })
        .catch(err => console.error('Error checking rider realtime updates:', err));
}

function promptCancelDelivery(deliveryId) {
    const reasonOptions = Array.isArray(window.deliveryCancellationReasons) ? window.deliveryCancellationReasons : [];
    const optionsHtml = reasonOptions.map((reason) => {
        const escaped = String(reason)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
        return `<option value="${escaped}">${escaped}</option>`;
    }).join('');

    Swal.fire({
        title: 'Mark Delivery as Returning?',
        html: `
            <div style="text-align:left;">
                <label for="cancelDeliveryReason" style="display:block;font-size:0.82rem;font-weight:700;color:#475569;margin-bottom:0.45rem;">Cancellation Reason</label>
                <select id="cancelDeliveryReason" class="swal2-select" style="display:flex;width:100%;margin:0 0 0.85rem 0;">
                    <option value="">Select a reason</option>
                    ${optionsHtml}
                </select>
                <label for="cancelDeliveryRemarks" style="display:block;font-size:0.82rem;font-weight:700;color:#475569;margin-bottom:0.45rem;">Remarks <span style="font-weight:500;color:#94a3b8;">(required for Other)</span></label>
                <textarea id="cancelDeliveryRemarks" class="swal2-textarea" style="display:flex;width:100%;margin:0;" placeholder="Add extra details if needed..."></textarea>
            </div>
        `,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        confirmButtonText: 'Submit Reason',
        focusConfirm: false,
        preConfirm: () => {
            const reason = document.getElementById('cancelDeliveryReason')?.value?.trim() || '';
            const remarks = document.getElementById('cancelDeliveryRemarks')?.value?.trim() || '';
            if (!reason) {
                Swal.showValidationMessage('Please select a cancellation reason.');
                return false;
            }
            if (reason === 'Other' && !remarks) {
                Swal.showValidationMessage('Remarks are required when "Other" is selected.');
                return false;
            }

            return { reason, remarks };
        }
    }).then((res) => {
        if (res.isConfirmed) {
            const formData = new FormData();
            if (window.csrfToken) formData.append('csrf_token', window.csrfToken);
            formData.append('action', 'cancel_delivery');
            formData.append('delivery_id', deliveryId);
            formData.append('reason', res.value.reason);
            formData.append('remarks', res.value.remarks || '');
            
            Swal.fire({ title: 'Processing...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
            fetch('../api/rider_dashboard_backend.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire('Returning', data.message, 'success').then(() => refreshQueueAjax(false));
                    } else {
                        Swal.fire('Error', data.message || 'Failed to cancel', 'error');
                    }
                })
                .catch(() => Swal.fire('Error', 'Network error', 'error'));
        }
    });
}

function acknowledgeReturnToStore(deliveryId) {
    Swal.fire({
        title: 'Return to Store?',
        text: 'Use this after you physically bring the order back to the store. This does not record any remittance.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Confirm Return',
        confirmButtonColor: '#f59e0b'
    }).then((res) => {
        if (!res.isConfirmed) return;

        const formData = new FormData();
        if (window.csrfToken) formData.append('csrf_token', window.csrfToken);
        formData.append('action', 'acknowledge_return_to_store');
        formData.append('delivery_id', deliveryId);

        Swal.fire({ title: 'Processing...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        fetch('../api/rider_dashboard_backend.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('Returned to Store', data.message, 'success').then(() => refreshQueueAjax(false));
                } else {
                    Swal.fire('Error', data.message || 'Failed to update delivery', 'error');
                }
            })
            .catch(() => Swal.fire('Error', 'Network error', 'error'));
    });
}

function handleRiderReadyUpdate(data, shouldNotify = true) {
    if (!data) return;
    const delId = parseInt(data.delivery_id || '0', 10) || 0;
    const ordId = parseInt(data.order_id || '0', 10) || 0;
    
    if (delId <= 0) return;

    if (knownReadyIds.has(delId)) {
        return;
    }
    knownReadyIds.add(delId);
    localStorage.setItem('rider_known_ready_deliveries', JSON.stringify([...knownReadyIds]));
    
    if (!shouldNotify) {
        return;
    }
    
    ToastNotification.fire({
        icon: 'success',
        title: 'Order Ready for Pickup!',
        text: `Order #${ordId} (Delivery #${delId}) is now ready for pickup.`
    });
    
    try {
        let audio = new Audio('../assets/sounds/notification.mp3');
        audio.play().catch(e => {});
    } catch(e) {}
    
    addRiderNotification(`Delivery #${delId} Ready`, 'Order is ready to pick up.', 'fa-check-circle', 'refreshQueueAjax(false); return false;');

    const notifList = document.getElementById('notificationList');
    localStorage.setItem('rider_notif_count', notificationCount.toString());
    if (notifList) localStorage.setItem('rider_notif_html', notifList.innerHTML);

    const btn = document.getElementById('notificationDropdown');
    const badge = document.getElementById('notificationBadge');
    if (btn && badge) {
        btn.classList.add('has-new');
        badge.textContent = notificationCount;
        badge.style.display = 'inline-block';
    }

    refreshQueueAjax(true);
}

function initRealtimeRiderSocket() {
    const protocol = location.protocol === 'https:' ? 'wss' : 'ws';
    const socketUrl = `${protocol}://${location.hostname}:8090`;
    let socket = null;
    let reconnectDelay = 1000;

    const connect = () => {
        try {
            socket = new WebSocket(socketUrl);
        } catch (error) {
            setTimeout(connect, reconnectDelay);
            reconnectDelay = Math.min(reconnectDelay * 2, 15000);
            return;
        }

        socket.addEventListener('open', () => {
            reconnectDelay = 1000;
        });

        socket.addEventListener('message', (event) => {
            let payload;
            try {
                payload = JSON.parse(event.data);
            } catch (e) {
                return;
            }
            if (!payload || !payload.event) return;
            const data = payload.data || {};
            const riderUserId = parseInt(window.currentRiderUserId || '0', 10);
            
            if (payload.event === 'delivery.ready') {
                const targetRiderId = parseInt(data.rider_user_id || '0', 10);
                if (targetRiderId > 0 && riderUserId > 0 && targetRiderId !== riderUserId) {
                    return;
                }
                handleRiderReadyUpdate(data, true);
                return;
            }

            if (payload.event === 'delivery.remittance_recorded') {
                const targetRiderId = parseInt(data.rider_user_id || '0', 10);
                if (targetRiderId > 0 && riderUserId > 0 && targetRiderId !== riderUserId) {
                    return;
                }
                handleRiderRemittanceUpdate(data, true);
                return;
            }

            if (payload.event === 'damage_report.reviewed') {
                const targetRiderId = parseInt(data.rider_user_id || '0', 10);
                if (targetRiderId > 0 && riderUserId > 0 && targetRiderId !== riderUserId) {
                    return;
                }
                handleRiderDamageReviewUpdate(data, true);
                return;
            }

            if (payload.event === 'order.completed') {
                refreshQueueAjax(true);
            }
        });

        socket.addEventListener('close', () => {
            setTimeout(connect, reconnectDelay);
            reconnectDelay = Math.min(reconnectDelay * 2, 15000);
        });
        socket.addEventListener('error', () => {
            try { socket.close(); } catch (e) {}
        });
    };
    connect();
}

function backToDuty() {
    var btn = document.querySelector('[onclick="backToDuty()"]');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
    }
    fetch('../api/rider_dashboard_backend.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=rider_set_available&csrf_token=' + encodeURIComponent(document.querySelector('meta[name="csrf-token"]')?.content || '')
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        if (data.success) {
            window.location.reload();
        } else {
            alert(data.message || 'Failed to update status.');
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-undo-alt"></i> Back to Duty';
            }
        }
    })
    .catch(function() {
        alert('Network error. Please try again.');
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-undo-alt"></i> Back to Duty';
        }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    initRealtimeRiderSocket();
    pollRiderRealtimeUpdates();
    setInterval(pollRiderRealtimeUpdates, 10000);
});