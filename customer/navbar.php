<?php
$customerNavPage = basename($_SERVER['PHP_SELF'] ?? '');
$customerNavLinks = [
    ['file' => 'dashboard.php', 'label' => 'Dashboard', 'mobile' => 'Home'],
    ['file' => 'rooms.php', 'label' => 'Browse rooms', 'mobile' => 'Rooms'],
    ['file' => 'booking.php', 'label' => 'Book a stay', 'mobile' => 'Book'],
    ['file' => 'announcements.php', 'label' => 'Hotel announcements', 'mobile' => 'News'],
    ['file' => 'bookings.php', 'label' => 'Paid bookings', 'mobile' => 'Stays'],
    ['file' => 'profile.php', 'label' => 'My profile', 'mobile' => 'Profile'],
    ['file' => '../logout.php', 'label' => 'Sign out', 'mobile' => 'Sign out'],
];
?>
<style>
    .customer-nav, .customer-nav * { box-sizing: border-box; }
    .customer-nav {
        position: fixed;
        z-index: 20;
        top: 0;
        bottom: 0;
        left: 0;
        display: flex;
        width: 84px;
        min-height: 100vh;
        flex-direction: column;
        align-items: center;
        gap: 34px;
        padding: 24px 14px;
        border-right: 1px solid #e4e7e0;
        background: rgba(251, 250, 247, 0.96);
        color: #202923;
        font-family: "Aptos", "Segoe UI", sans-serif;
        -webkit-backdrop-filter: blur(12px);
        backdrop-filter: blur(12px);
    }
    body { padding-left: 84px; }
    .customer-nav__brand {
        display: grid;
        width: 54px;
        height: 54px;
        flex: 0 0 auto;
        place-items: center;
        color: #173b35;
        text-decoration: none;
    }
    .customer-nav__brand span:last-child { display: none; }
    .customer-nav__mark {
        display: grid;
        width: 32px;
        aspect-ratio: 1;
        place-items: center;
        border: 1px solid #173b35;
        color: #9a774a;
        font-family: Georgia, "Times New Roman", serif;
        font-size: 18px;
        font-weight: 400;
    }
    .customer-nav__links {
        display: flex;
        width: 100%;
        flex: 1 1 auto;
        flex-direction: column;
        align-items: center;
        gap: 14px;
    }
    .customer-nav__link {
        position: relative;
        display: inline-grid;
        width: 54px;
        min-width: 54px;
        height: 54px;
        align-items: center;
        justify-content: center;
        place-items: center;
        align-self: center;
        border-radius: 4px;
        color: #687169;
        text-decoration: none;
        transition: color 150ms ease, background-color 150ms ease;
    }
    .customer-nav__link:hover, .customer-nav__link:focus-visible, .customer-nav__link[aria-current="page"] {
        background: #e8eee8;
        color: #173b35;
    }
    .customer-nav__icon { display: block; width: 26px; height: 26px; }
    .customer-nav__link::after {
        position: absolute;
        top: 50%;
        left: calc(100% + 10px);
        z-index: 2;
        padding: 6px 9px;
        transform: translate(-3px, -50%);
        border-radius: 3px;
        background: #173b35;
        color: white;
        content: "";
        content: attr(data-tooltip);
        font-size: 11px;
        font-weight: 600;
        line-height: 1.2;
        opacity: 0;
        pointer-events: none;
        white-space: nowrap;
        transition: opacity 120ms ease, transform 120ms ease;
    }
    .customer-nav__link:hover::after, .customer-nav__link:focus-visible::after { transform: translate(0, -50%); opacity: 1; }
    .customer-nav__link--logout { color: #874d43; }
    .customer-nav__link--logout:hover { color: #6f3028; }
    .customer-nav__link--logout { margin-top: auto; }
    .customer-nav a:focus-visible { outline: 2px solid #173b35; outline-offset: -4px; }
    .customer-route-trigger {
        position: fixed;
        z-index: 40;
        right: 22px;
        bottom: 22px;
        display: grid;
        width: 54px;
        height: 54px;
        place-items: center;
        border: 1px solid #173b35;
        border-radius: 50%;
        background: #173b35;
        box-shadow: 0 5px 18px rgba(25, 43, 35, .22);
        color: #fff;
        cursor: pointer;
        transition: transform 150ms ease, background-color 150ms ease;
    }
    .customer-route-trigger:hover { transform: translateY(-2px); background: #102d29; }
    .customer-route-trigger svg { width: 24px; height: 24px; }
    .customer-route-trigger:focus-visible, .customer-route-dialog button:focus-visible { outline: 3px solid rgba(23,59,53,.28); outline-offset: 3px; }
    .customer-route-dialog {
        width: min(900px, calc(100vw - 28px));
        max-width: none;
        max-height: min(760px, calc(100dvh - 28px));
        padding: 0;
        overflow: hidden;
        border: 1px solid #dfe5dd;
        border-radius: 4px;
        background: #fbfaf7;
        color: #202923;
        box-shadow: 0 20px 70px rgba(18, 35, 27, .3);
        font-family: "Aptos", "Segoe UI", sans-serif;
    }
    .customer-route-dialog::backdrop { background: rgba(14, 26, 21, .55); }
    .customer-route-content { padding: 20px; }
    .customer-route-heading { display: flex; align-items: start; justify-content: space-between; gap: 16px; margin-bottom: 15px; }
    .customer-route-heading h2 { margin: 0 0 5px; color: #173b35; font: 400 25px Georgia, "Times New Roman", serif; }
    .customer-route-heading p { margin: 0; color: #747d75; font-size: 11px; line-height: 1.5; }
    .customer-route-close { display: grid; width: 36px; height: 36px; flex: 0 0 auto; place-items: center; border: 1px solid #e2e6df; background: #fff; color: #455047; cursor: pointer; }
    .customer-route-close svg { width: 18px; height: 18px; }
    .customer-route-summary { display: flex; min-height: 42px; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 10px; padding: 9px 12px; border-left: 3px solid #ae8551; background: #f2f2eb; color: #39453d; font-size: 11px; }
    .customer-route-summary strong { color: #173b35; font-size: 12px; }
    .customer-route-map { width: 100%; height: min(55vh, 470px); min-height: 250px; border: 1px solid #dce1d9; background: #e8eee8; }
    .customer-route-status { min-height: 18px; margin: 10px 0 7px; color: #69736b; font-size: 10px; line-height: 1.5; }
    .customer-route-attribution { margin: 0; color: #747d75; font-size: 9px; line-height: 1.4; }
    .customer-route-attribution a { color: #173b35; }
    @media (max-width: 640px) {
        .customer-route-trigger { right: 15px; bottom: calc(78px + env(safe-area-inset-bottom)); width: 50px; height: 50px; }
        .customer-route-dialog { width: calc(100vw - 16px); max-height: calc(100dvh - 16px); }
        .customer-route-content { padding: 15px; }
        .customer-route-heading h2 { font-size: 22px; }
        .customer-route-map { height: 48vh; min-height: 230px; }
    }

    @media (max-width: 640px) {
        body { padding-bottom: calc(68px + env(safe-area-inset-bottom)); }
        body { padding-left: 0; }
        .customer-nav {
            position: fixed;
            top: auto;
            right: 0;
            bottom: 0;
            left: 0;
            width: auto;
            min-height: 60px;
            flex-direction: row;
            align-items: center;
            justify-content: center;
            gap: 0;
            padding: 0 6px env(safe-area-inset-bottom);
            border-top: 1px solid #e4e7e0;
            border-right: 0;
            border-bottom: 0;
            background: rgba(251, 250, 247, 0.98);
        }
        .customer-nav__brand { display: none; }
        .customer-nav__links {
            width: 100%;
            flex: 0 1 auto;
            min-height: 60px;
            flex-direction: row;
            align-items: center;
            justify-content: space-around;
            gap: 0;
        }
        .customer-nav__link {
            width: 56px;
            min-width: 56px;
            height: 56px;
            flex: 1 1 25%;
            margin: 0 auto;
        }
        .customer-nav__link--logout { margin-top: 0; }
        .customer-nav__link::after {
            top: auto;
            bottom: calc(100% + 6px);
            left: 50%;
            transform: translate(-50%, 3px);
        }
        .customer-nav__link:hover::after, .customer-nav__link:focus-visible::after { transform: translate(-50%, 0); }
    }

    @media (prefers-reduced-motion: reduce) {
        .customer-nav__link { transition: none; }
    }
</style>
<nav class="customer-nav" aria-label="Customer navigation">
    <a class="customer-nav__brand" href="dashboard.php" aria-label="The Queens Hotel customer dashboard">
        <span class="customer-nav__mark" aria-hidden="true">Q</span>
        <span>The Queens Hotel</span>
    </a>
    <div class="customer-nav__links">
        <?php foreach ($customerNavLinks as $link): ?>
            <?php $isCurrentPage = $customerNavPage === basename($link['file']); ?>
            <a
                class="customer-nav__link<?= $link['file'] === '../logout.php' ? ' customer-nav__link--logout' : '' ?>"
                href="<?= htmlspecialchars($link['file'], ENT_QUOTES, 'UTF-8') ?>"
                aria-label="<?= htmlspecialchars($link['label'], ENT_QUOTES, 'UTF-8') ?>"
                title="<?= htmlspecialchars($link['label'], ENT_QUOTES, 'UTF-8') ?>"
                data-tooltip="<?= htmlspecialchars($link['label'], ENT_QUOTES, 'UTF-8') ?>"
                <?= $isCurrentPage ? 'aria-current="page"' : '' ?>
            >
                <?php if ($link['file'] === 'dashboard.php'): ?>
                    <svg class="customer-nav__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="3" y="3" width="8" height="8" rx="1.5"/><rect x="13" y="3" width="8" height="5" rx="1.5"/><rect x="13" y="10" width="8" height="11" rx="1.5"/><rect x="3" y="13" width="8" height="8" rx="1.5"/></svg>
                <?php elseif ($link['file'] === 'rooms.php'): ?>
                    <svg class="customer-nav__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 21V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v16M3 10h18M7 7h.01M11 7h.01M15 7h.01M8 14h3v4H8zM15 14h2v2h-2z"/></svg>
                <?php elseif ($link['file'] === 'booking.php'): ?>
                    <svg class="customer-nav__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18M8 14h3M8 17h6"/></svg>
                <?php elseif ($link['file'] === 'announcements.php'): ?>
                    <svg class="customer-nav__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4 11v2a2 2 0 0 0 2 2h2l3 5h2l-2-5 8 3V6l-8 3H6a2 2 0 0 0-2 2Z"/><path d="M20 9v6"/></svg>
                <?php elseif ($link['file'] === 'bookings.php'): ?>
                    <svg class="customer-nav__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4 3h16v18H4zM8 7h8M8 11h8M8 15h4"/><path d="m15 18 2 2 4-4"/></svg>
                <?php elseif ($link['file'] === 'profile.php'): ?>
                    <svg class="customer-nav__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="8" r="3.5"/><path d="M5 21a7 7 0 0 1 14 0"/></svg>
                <?php else: ?>
                    <svg class="customer-nav__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m10 17 5-5-5-5M15 12H3"/><path d="M12 3h5a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-5"/></svg>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>
</nav>
<button class="customer-route-trigger" id="customer-route-open" type="button" title="Route to the hotel" aria-label="Show route to the hotel" aria-haspopup="dialog" aria-controls="customer-route-dialog">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="6" cy="19" r="2.5"/><circle cx="18" cy="5" r="2.5"/><path d="M6 16.5V13a4 4 0 0 1 4-4h4a4 4 0 0 0 4-4"/><path d="m14 7 4-2 2 4"/></svg>
</button>
<dialog class="customer-route-dialog" id="customer-route-dialog" aria-labelledby="customer-route-title">
    <div class="customer-route-content">
        <div class="customer-route-heading">
            <div><h2 id="customer-route-title">Route to The Queens Hotel</h2><p>If GPS is unavailable, an approximate area may be estimated from your IP. Location data is shared with map services for this request and is not stored by the hotel.</p></div>
            <button class="customer-route-close" id="customer-route-close" type="button" aria-label="Close route map"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg></button>
        </div>
        <div class="customer-route-summary" id="customer-route-summary" hidden><span id="customer-route-address"></span><strong id="customer-route-distance"></strong></div>
        <div class="customer-route-map" id="customer-route-map" role="region" aria-label="Driving route map"></div>
        <p class="customer-route-status" id="customer-route-status" role="status" aria-live="polite">Allow location access to see the route, or tap your starting point on the map.</p>
        <p class="customer-route-attribution">Map data &copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap contributors</a>. Route by <a href="https://project-osrm.org/" target="_blank" rel="noopener">OSRM</a>.</p>
    </div>
</dialog>
<script>
    const customerRouteDialog = document.querySelector('#customer-route-dialog');
    const customerRouteStatus = document.querySelector('#customer-route-status');
    const customerRouteSummary = document.querySelector('#customer-route-summary');
    const customerRouteOpenButton = document.querySelector('#customer-route-open');
    let customerRouteMap;
    let customerRouteLayer;
    let customerLocationMarker;
    let hotelLocationMarker;
    let hotelLocationData;
    let leafletLoading;

    function loadCustomerLeaflet() {
        if (window.L) return Promise.resolve();
        if (leafletLoading) return leafletLoading;
        leafletLoading = new Promise((resolve, reject) => {
            if (!document.querySelector('#customer-leaflet-css')) {
                const stylesheet = document.createElement('link');
                stylesheet.id = 'customer-leaflet-css';
                stylesheet.rel = 'stylesheet';
                stylesheet.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
                document.head.append(stylesheet);
            }
            const script = document.createElement('script');
            script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
            script.onload = resolve;
            script.onerror = () => reject(new Error('The map could not be loaded. Check your connection and try again.'));
            document.head.append(script);
        });
        return leafletLoading;
    }

    async function initializeCustomerRouteMap() {
        await loadCustomerLeaflet();
        if (!customerRouteMap) {
            customerRouteMap = window.L.map('customer-route-map').setView([14.5995, 120.9842], 5);
            window.L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
            }).addTo(customerRouteMap);
            customerRouteMap.on('click', (event) => {
                if (!hotelLocationData) {
                    customerRouteStatus.textContent = 'The hotel location is still loading. Try again in a moment.';
                    return;
                }
                customerRouteSummary.hidden = true;
                void showCustomerRoute(
                    { latitude: event.latlng.lat, longitude: event.latlng.lng },
                    'the selected map point'
                );
            });
        }
        if (!hotelLocationData) {
            const response = await fetch('hotel-location.php', { headers: { Accept: 'application/json' } });
            const result = await response.json();
            if (!response.ok) throw new Error(result.error || 'The hotel location is not available yet.');
            hotelLocationData = {
                address: result.address,
                latitude: Number(result.latitude),
                longitude: Number(result.longitude),
            };
        }
        if (!hotelLocationMarker) {
            hotelLocationMarker = window.L.circleMarker([hotelLocationData.latitude, hotelLocationData.longitude], { radius: 8, color: '#fff', weight: 2, fillColor: '#ae8551', fillOpacity: 1 }).addTo(customerRouteMap);
            hotelLocationMarker.bindTooltip('The Queens Hotel');
            customerRouteMap.setView([hotelLocationData.latitude, hotelLocationData.longitude], 12);
        }
        customerRouteMap.invalidateSize();
    }

    async function getApproximateCustomerPosition() {
        customerRouteStatus.textContent = 'GPS is unavailable. Checking your approximate network location…';
        const controller = new AbortController();
        const timeoutId = window.setTimeout(() => controller.abort(), 8000);
        try {
            const response = await fetch('https://ipapi.co/json/', {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            });
            if (!response.ok) throw new Error('Approximate location lookup failed.');
            const result = await response.json();
            const latitude = Number(result.latitude);
            const longitude = Number(result.longitude);
            if (result.latitude === null || result.longitude === null || !Number.isFinite(latitude) || !Number.isFinite(longitude) || Math.abs(latitude) > 90 || Math.abs(longitude) > 180) {
                throw new Error('Approximate location could not be determined.');
            }
            const area = [result.city, result.region, result.country_name].filter((part) => typeof part === 'string' && part.trim() !== '').join(', ');
            return {
                latitude,
                longitude,
                description: area ? `your approximate location near ${area}` : 'your approximate network location',
            };
        } finally {
            window.clearTimeout(timeoutId);
        }
    }

    function getCustomerPosition() {
        return new Promise((resolve, reject) => {
            if (!navigator.geolocation) {
                getApproximateCustomerPosition().then(resolve, reject);
                return;
            }
            navigator.geolocation.getCurrentPosition(
                (position) => resolve({
                    latitude: position.coords.latitude,
                    longitude: position.coords.longitude,
                    description: 'your current location',
                }),
                async (error) => {
                    if (error.code === error.PERMISSION_DENIED) {
                        reject(new Error('Location access was denied. Allow it in browser settings, or tap your starting point on the map.'));
                        return;
                    }
                    try {
                        resolve(await getApproximateCustomerPosition());
                    } catch {
                        reject(new Error('Neither GPS nor approximate location is available. Tap your starting point on the map to get directions.'));
                    }
                },
                { enableHighAccuracy: false, timeout: 10000, maximumAge: 300000 }
            );
        });
    }

    async function showCustomerRoute(origin, originDescription = 'your current location') {
        const destination = hotelLocationData;
        const routeUrl = `https://router.project-osrm.org/route/v1/driving/${origin.longitude},${origin.latitude};${destination.longitude},${destination.latitude}?overview=full&geometries=geojson&steps=false`;
        customerRouteStatus.textContent = 'Finding a driving route…';
        customerRouteSummary.hidden = true;
        try {
            const response = await fetch(routeUrl, { headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error('The route service is unavailable right now. Please try again later.');
            const result = await response.json();
            if (result.code !== 'Ok' || !result.routes?.length) throw new Error('No driving route was found between your location and the hotel.');

            if (customerRouteLayer) customerRouteMap.removeLayer(customerRouteLayer);
            if (customerLocationMarker) customerRouteMap.removeLayer(customerLocationMarker);
            if (hotelLocationMarker) customerRouteMap.removeLayer(hotelLocationMarker);
            const route = result.routes[0];
            const routePoints = route.geometry.coordinates.map(([longitude, latitude]) => [latitude, longitude]);
            customerRouteLayer = window.L.polyline(routePoints, { color: '#b25e43', weight: 5, opacity: .88 }).addTo(customerRouteMap);
            customerLocationMarker = window.L.circleMarker([origin.latitude, origin.longitude], { radius: 7, color: '#fff', weight: 2, fillColor: '#276b5b', fillOpacity: 1 }).addTo(customerRouteMap);
            hotelLocationMarker = window.L.circleMarker([destination.latitude, destination.longitude], { radius: 8, color: '#fff', weight: 2, fillColor: '#ae8551', fillOpacity: 1 }).addTo(customerRouteMap);
            customerRouteMap.fitBounds(customerRouteLayer.getBounds(), { padding: [28, 28] });

            const distanceKm = (route.distance / 1000).toFixed(1);
            const durationMinutes = Math.max(1, Math.round(route.duration / 60));
            document.querySelector('#customer-route-address').textContent = destination.address;
            document.querySelector('#customer-route-distance').textContent = `${distanceKm} km · about ${durationMinutes} min by car`;
            customerRouteSummary.hidden = false;
            customerRouteStatus.textContent = `Driving route from ${originDescription} to the hotel.`;
        } catch (error) {
            customerRouteStatus.textContent = error.message || 'Could not calculate the route. Please try again.';
        }
    }

    customerRouteOpenButton.addEventListener('click', async () => {
        customerRouteDialog.showModal();
        customerRouteOpenButton.disabled = true;
        customerRouteStatus.textContent = 'Requesting your location and loading the hotel map…';
        const positionPromise = getCustomerPosition();
        try {
            const [, coordinates] = await Promise.all([initializeCustomerRouteMap(), positionPromise]);
            await showCustomerRoute(
                { latitude: coordinates.latitude, longitude: coordinates.longitude },
                coordinates.description
            );
        } catch (error) {
            customerRouteStatus.textContent = error.message || 'Could not load the hotel route. Please try again.';
        } finally {
            customerRouteOpenButton.disabled = false;
        }
    });

    document.querySelector('#customer-route-close').addEventListener('click', () => customerRouteDialog.close());
    customerRouteDialog.addEventListener('click', (event) => {
        if (event.target === customerRouteDialog) customerRouteDialog.close();
    });
</script>