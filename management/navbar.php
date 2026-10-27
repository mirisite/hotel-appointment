<?php
$managementNavPage = basename($_SERVER['PHP_SELF'] ?? '');
$managementCsrfToken = $_SESSION['csrf_token'] ?? '';
if (!is_string($managementCsrfToken) || $managementCsrfToken === '') {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $managementCsrfToken = $_SESSION['csrf_token'];
}
$managementNavLinks = [
    ['key' => 'overview', 'href' => 'dashboard.php#overview', 'page' => 'dashboard.php', 'label' => 'Dashboard'],
    ['key' => 'rooms', 'href' => 'rooms.php', 'page' => 'rooms.php', 'label' => 'Rooms'],
    ['key' => 'bookings', 'href' => 'bookings.php', 'page' => 'bookings.php', 'label' => 'Paid bookings'],
    ['key' => 'announcements', 'href' => 'announcements.php', 'page' => 'announcements.php', 'label' => 'Announcements'],
    ['key' => 'customers', 'href' => 'dashboard.php#customers', 'page' => 'dashboard.php', 'label' => 'Customers'],
    ['key' => 'team', 'href' => 'dashboard.php#team', 'page' => 'dashboard.php', 'label' => 'Staff'],
];
?>
<style>
    .management-nav, .management-nav * { box-sizing: border-box; }
    .management-nav {
        position: fixed;
        z-index: 30;
        inset: 0 auto 0 0;
        display: flex;
        width: 88px;
        flex-direction: column;
        align-items: center;
        gap: 34px;
        padding: 24px 14px;
        border-right: 1px solid #e4e7e0;
        background: #fbfaf7;
        color: #202923;
        font-family: "Aptos", "Segoe UI", sans-serif;
    }
    body { padding-left: 88px; }
    .management-nav__brand {
        display: grid;
        width: 54px;
        height: 54px;
        flex: 0 0 auto;
        place-items: center;
        color: #173b35;
        text-decoration: none;
    }
    .management-nav__mark {
        display: grid;
        width: 36px;
        aspect-ratio: 1;
        place-items: center;
        border: 1px solid #173b35;
        color: #9a774a;
        font-family: Georgia, "Times New Roman", serif;
        font-size: 20px;
    }
    .management-nav__links {
        display: flex;
        width: 100%;
        flex: 1 1 auto;
        flex-direction: column;
        align-items: center;
        gap: 14px;
    }
    .management-nav__link {
        position: relative;
        display: grid;
        width: 56px;
        height: 56px;
        flex: 0 0 auto;
        place-items: center;
        border-radius: 4px;
        color: #687169;
        text-decoration: none;
        transition: color 150ms ease, background-color 150ms ease;
    }
    .management-nav__link:hover, .management-nav__link:focus-visible, .management-nav__link[aria-current="location"], .management-nav__link[aria-current="page"] {
        background: #e8eee8;
        color: #173b35;
    }
    .management-nav__link--logout { margin-top: auto; color: #874d43; }
    .management-nav__link--logout:hover { color: #6f3028; }
    .management-nav__icon { display: block; width: 26px; height: 26px; }
    .management-nav__link::after {
        position: absolute;
        top: 50%;
        left: calc(100% + 10px);
        z-index: 2;
        padding: 6px 9px;
        transform: translate(-3px, -50%);
        border-radius: 3px;
        background: #173b35;
        color: #fff;
        content: attr(data-tooltip);
        font-size: 11px;
        font-weight: 600;
        line-height: 1.2;
        opacity: 0;
        pointer-events: none;
        white-space: nowrap;
        transition: opacity 120ms ease, transform 120ms ease;
    }
    .management-nav__link:hover::after, .management-nav__link:focus-visible::after { transform: translate(0, -50%); opacity: 1; }
    .management-nav a:focus-visible { outline: 2px solid #173b35; outline-offset: 2px; }
    .hotel-location-trigger {
        position: fixed;
        z-index: 40;
        right: 24px;
        bottom: 24px;
        display: inline-flex;
        min-height: 48px;
        align-items: center;
        gap: 9px;
        padding: 0 16px;
        border: 1px solid #173b35;
        border-radius: 3px;
        background: #173b35;
        box-shadow: 0 5px 18px rgba(25, 43, 35, .2);
        color: #fff;
        cursor: pointer;
        font: 600 12px "Aptos", "Segoe UI", sans-serif;
    }
    .hotel-location-trigger:hover { background: #102d29; }
    .hotel-location-trigger svg { width: 19px; height: 19px; flex: 0 0 auto; }
    .hotel-location-dialog {
        width: min(880px, calc(100vw - 28px));
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
    .hotel-location-dialog::backdrop { background: rgba(14, 26, 21, .55); }
    .hotel-location-content { padding: 20px; }
    .hotel-location-visually-hidden { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
    .hotel-location-heading { display: flex; align-items: start; justify-content: space-between; gap: 16px; margin-bottom: 16px; }
    .hotel-location-heading h2 { margin: 0 0 5px; color: #173b35; font: 400 25px Georgia, "Times New Roman", serif; }
    .hotel-location-heading p { margin: 0; color: #747d75; font-size: 11px; line-height: 1.5; }
    .hotel-location-close { display: grid; width: 36px; height: 36px; flex: 0 0 auto; place-items: center; border: 1px solid #e2e6df; background: #fff; color: #455047; cursor: pointer; }
    .hotel-location-close svg { width: 18px; height: 18px; }
    .hotel-location-form { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 8px; margin-bottom: 10px; }
    .hotel-location-input { width: 100%; min-height: 42px; padding: 9px 11px; border: 1px solid #dce1d9; border-radius: 2px; outline: none; background: #fff; color: #202923; font: inherit; font-size: 12px; }
    .hotel-location-input:focus { border-color: #173b35; box-shadow: 0 0 0 3px rgba(23,59,53,.09); }
    .hotel-location-action { display: inline-flex; min-height: 42px; align-items: center; justify-content: center; gap: 7px; padding: 0 14px; border: 1px solid #173b35; border-radius: 2px; background: #173b35; color: white; cursor: pointer; font: 600 11px "Aptos", "Segoe UI", sans-serif; }
    .hotel-location-action:hover { background: #102d29; }
    .hotel-location-action:disabled { cursor: wait; opacity: .65; }
    .hotel-location-action--secondary { border-color: #dce3db; background: #f5f7f3; color: #173b35; }
    .hotel-location-action--secondary:hover { background: #e9efe8; }
    .hotel-location-map { width: 100%; height: min(42vh, 360px); min-height: 220px; border: 1px solid #dce1d9; background: #e8eee8; }
    .hotel-location-status { min-height: 18px; margin: 9px 0 12px; color: #69736b; font-size: 10px; line-height: 1.5; }
    .hotel-location-footer { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
    .hotel-location-attribution { color: #747d75; font-size: 9px; line-height: 1.4; }
    .hotel-location-attribution a { color: #173b35; }
    @media (max-width: 640px) {
        .hotel-location-trigger { right: 14px; bottom: calc(78px + env(safe-area-inset-bottom)); min-height: 44px; padding: 0 13px; }
        .hotel-location-dialog { width: calc(100vw - 16px); max-height: calc(100dvh - 16px); }
        .hotel-location-content { padding: 15px; }
        .hotel-location-heading h2 { font-size: 22px; }
        .hotel-location-map { height: 38vh; min-height: 210px; }
        .hotel-location-footer { align-items: end; }
        .hotel-location-footer .hotel-location-action { min-width: 112px; }
    }
    @media (prefers-reduced-motion: reduce) { .hotel-location-trigger { transition: none; } }
    @media (max-width: 640px) {
        body { padding-left: 0; padding-bottom: calc(72px + env(safe-area-inset-bottom)); }
        .management-nav {
            inset: auto 0 0;
            width: auto;
            min-height: 64px;
            flex-direction: row;
            justify-content: center;
            gap: 0;
            padding: 4px 8px env(safe-area-inset-bottom);
            border-top: 1px solid #e4e7e0;
            border-right: 0;
            background: rgba(251, 250, 247, .98);
        }
        .management-nav__brand { display: none; }
        .management-nav__links {
            width: 100%;
            flex: 0 1 auto;
            flex-direction: row;
            justify-content: space-around;
            gap: 0;
        }
        .management-nav__link { width: 56px; height: 56px; flex: 1 1 25%; margin: 0 auto; }
        .management-nav__link--logout { margin-top: 0; }
        .management-nav__link::after {
            top: auto;
            bottom: calc(100% + 6px);
            left: 50%;
            transform: translate(-50%, 3px);
        }
        .management-nav__link:hover::after, .management-nav__link:focus-visible::after { transform: translate(-50%, 0); }
    }
    @media (prefers-reduced-motion: reduce) { .management-nav__link { transition: none; } }
</style>
<nav class="management-nav" aria-label="Management navigation">
    <a class="management-nav__brand" href="dashboard.php" aria-label="The Queens Hotel management dashboard">
        <span class="management-nav__mark" aria-hidden="true">Q</span>
    </a>
    <div class="management-nav__links">
        <?php foreach ($managementNavLinks as $link): ?>
            <?php $isCurrentPage = $managementNavPage === $link['page'] && in_array($link['key'], ['overview', 'rooms', 'bookings', 'announcements'], true); ?>
            <a class="management-nav__link" href="<?= htmlspecialchars($link['href'], ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars($link['label'], ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars($link['label'], ENT_QUOTES, 'UTF-8') ?>" data-tooltip="<?= htmlspecialchars($link['label'], ENT_QUOTES, 'UTF-8') ?>" data-key="<?= htmlspecialchars($link['key'], ENT_QUOTES, 'UTF-8') ?>" <?= $isCurrentPage ? 'aria-current="page"' : '' ?>>
                <?php if ($link['key'] === 'overview'): ?>
                    <svg class="management-nav__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="3" y="3" width="8" height="8" rx="1.5"/><rect x="13" y="3" width="8" height="5" rx="1.5"/><rect x="13" y="10" width="8" height="11" rx="1.5"/><rect x="3" y="13" width="8" height="8" rx="1.5"/></svg>
                <?php elseif ($link['key'] === 'rooms'): ?>
                    <svg class="management-nav__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 21V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v16M3 10h18M7 7h.01M11 7h.01M15 7h.01M8 14h3v4H8zM15 14h2v2h-2z"/></svg>
                <?php elseif ($link['key'] === 'bookings'): ?>
                    <svg class="management-nav__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4 3h16v18H4zM8 7h8M8 11h8M8 15h4"/><path d="m15 18 2 2 4-4"/></svg>
                <?php elseif ($link['key'] === 'announcements'): ?>
                    <svg class="management-nav__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4 11v2a2 2 0 0 0 2 2h2l3 5h2l-2-5 8 3V6l-8 3H6a2 2 0 0 0-2 2Z"/><path d="M20 9v6"/></svg>
                <?php elseif ($link['key'] === 'customers'): ?>
                    <svg class="management-nav__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M20 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                <?php else: ?>
                    <svg class="management-nav__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 21a6.5 6.5 0 0 1 13 0M16 5.5a3 3 0 0 1 0 5.8M18 15a5.5 5.5 0 0 1 3.5 5"/></svg>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
        <a class="management-nav__link management-nav__link--logout" href="../logout.php" aria-label="Sign out" title="Sign out" data-tooltip="Sign out">
            <svg class="management-nav__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m10 17 5-5-5-5M15 12H3"/><path d="M12 3h5a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-5"/></svg>
        </a>
    </div>
</nav>
<button class="hotel-location-trigger" id="hotel-location-open" type="button" aria-haspopup="dialog" aria-controls="hotel-location-dialog">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M20 10c0 5-8 12-8 12S4 15 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/></svg>
    <span>Set hotel address</span>
</button>
<dialog class="hotel-location-dialog" id="hotel-location-dialog" aria-labelledby="hotel-location-title">
    <div class="hotel-location-content">
        <div class="hotel-location-heading">
            <div><h2 id="hotel-location-title">Hotel location</h2><p>Search for the address, then click or drag the pin to set the exact location.</p></div>
            <button class="hotel-location-close" id="hotel-location-close" type="button" aria-label="Close hotel location editor"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg></button>
        </div>
        <form class="hotel-location-form" id="hotel-location-search">
            <label class="hotel-location-visually-hidden" for="hotel-location-address">Hotel address</label>
            <input class="hotel-location-input" id="hotel-location-address" name="address" type="search" maxlength="255" autocomplete="street-address" placeholder="Search a street, city, or place" required>
            <button class="hotel-location-action" id="hotel-location-find" type="submit">Find address</button>
        </form>
        <div class="hotel-location-map" id="hotel-location-map" role="region" aria-label="OpenStreetMap hotel location picker"></div>
        <p class="hotel-location-status" id="hotel-location-status" role="status" aria-live="polite">Search for the hotel address or click the map to place its pin.</p>
        <div class="hotel-location-footer">
            <span class="hotel-location-attribution">Map data &copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap contributors</a></span>
            <button class="hotel-location-action" id="hotel-location-save" type="button">Save location</button>
        </div>
    </div>
</dialog>
<script>
    const managementLinks = document.querySelectorAll('.management-nav__link[data-key]');
    const currentHash = window.location.hash.slice(1);
    if (currentHash) {
        managementLinks.forEach((link) => {
            if (link.dataset.key === currentHash) link.setAttribute('aria-current', 'location');
            else if (link.dataset.key === 'overview') link.removeAttribute('aria-current');
        });
    }

    const locationDialog = document.querySelector('#hotel-location-dialog');
    const locationStatus = document.querySelector('#hotel-location-status');
    const locationAddress = document.querySelector('#hotel-location-address');
    const locationSaveButton = document.querySelector('#hotel-location-save');
    const locationSearchButton = document.querySelector('#hotel-location-find');
    const locationCsrfToken = <?= json_encode($managementCsrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    let hotelMap;
    let hotelMarker;
    let hotelCoordinates = null;
    let hotelLocationLoaded = false;
    let lastGeocodeAt = 0;

    function setHotelMarker(latitude, longitude, zoom = 16) {
        hotelCoordinates = { latitude, longitude };
        const position = [latitude, longitude];
        if (hotelMarker) hotelMarker.setLatLng(position);
        else hotelMarker = window.L.marker(position, { draggable: true }).addTo(hotelMap);
        hotelMarker.off('dragend');
        hotelMarker.on('dragend', (event) => {
            const point = event.target.getLatLng();
            hotelCoordinates = { latitude: point.lat, longitude: point.lng };
            locationStatus.textContent = 'Pin moved. Save to update the hotel location.';
        });
        hotelMap.setView(position, zoom);
    }

    function loadLeaflet() {
        if (window.L) return Promise.resolve();
        return new Promise((resolve, reject) => {
            const stylesheet = document.createElement('link');
            stylesheet.rel = 'stylesheet';
            stylesheet.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
            document.head.append(stylesheet);
            const script = document.createElement('script');
            script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
            script.onload = resolve;
            script.onerror = reject;
            document.head.append(script);
        });
    }

    async function initializeHotelMap() {
        if (!hotelMap) {
            await loadLeaflet();
            hotelMap = window.L.map('hotel-location-map').setView([14.5995, 120.9842], 5);
            window.L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
            }).addTo(hotelMap);
            hotelMap.on('click', (event) => {
                setHotelMarker(event.latlng.lat, event.latlng.lng);
                locationStatus.textContent = 'Pin placed. Enter or search the street address, then save.';
            });
        }
        if (!hotelLocationLoaded) {
            const response = await fetch('hotel-address.php', { headers: { Accept: 'application/json' } });
            const result = await response.json();
            if (!response.ok) throw new Error(result.error || 'Could not load the saved hotel location.');
            locationAddress.value = result.address || '';
            if (result.latitude !== null && result.longitude !== null) {
                setHotelMarker(Number(result.latitude), Number(result.longitude));
                locationStatus.textContent = 'Saved hotel location loaded. Click or drag the pin to adjust it.';
            }
            hotelLocationLoaded = true;
        }
        hotelMap.invalidateSize();
    }

    document.querySelector('#hotel-location-open').addEventListener('click', async () => {
        locationDialog.showModal();
        locationStatus.textContent = 'Loading the map…';
        try {
            await initializeHotelMap();
            if (!hotelCoordinates) locationStatus.textContent = 'Search for the hotel address or click the map to place its pin.';
        } catch (error) {
            locationStatus.textContent = error.message || 'The map could not be loaded. Check your connection and try again.';
        }
    });

    document.querySelector('#hotel-location-close').addEventListener('click', () => locationDialog.close());
    locationDialog.addEventListener('click', (event) => {
        if (event.target === locationDialog) locationDialog.close();
    });

    document.querySelector('#hotel-location-search').addEventListener('submit', async (event) => {
        event.preventDefault();
        const query = locationAddress.value.trim();
        if (!query) return;
        locationSearchButton.disabled = true;
        locationStatus.textContent = 'Searching OpenStreetMap…';
        try {
            const waitMs = Math.max(0, 1100 - (Date.now() - lastGeocodeAt));
            if (waitMs) await new Promise((resolve) => window.setTimeout(resolve, waitMs));
            lastGeocodeAt = Date.now();
            const endpoint = `https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&q=${encodeURIComponent(query)}`;
            const response = await fetch(endpoint, { headers: { Accept: 'application/json', 'Accept-Language': 'en' } });
            if (!response.ok) throw new Error('Address search is temporarily unavailable.');
            const results = await response.json();
            if (!results.length) throw new Error('No matching address found. Try adding the city or country.');
            const result = results[0];
            locationAddress.value = result.display_name.slice(0, 255);
            setHotelMarker(Number(result.lat), Number(result.lon));
            locationStatus.textContent = 'Address found. Adjust the pin if needed, then save the location.';
        } catch (error) {
            locationStatus.textContent = error.message || 'Address search failed. Try again.';
        } finally {
            locationSearchButton.disabled = false;
        }
    });

    locationSaveButton.addEventListener('click', async () => {
        if (!locationCoordinatesAreValid()) {
            locationStatus.textContent = 'Search for an address or place a pin before saving.';
            return;
        }
        locationSaveButton.disabled = true;
        locationStatus.textContent = 'Saving hotel location…';
        try {
            const response = await fetch('hotel-address.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': locationCsrfToken },
                body: JSON.stringify({ address: locationAddress.value.trim(), ...hotelCoordinates }),
            });
            const result = await response.json();
            if (!response.ok) throw new Error(result.error || 'The hotel location could not be saved.');
            locationAddress.value = result.address;
            locationStatus.textContent = 'Hotel address and map pin saved.';
        } catch (error) {
            locationStatus.textContent = error.message || 'Could not save the hotel location. Please try again.';
        } finally {
            locationSaveButton.disabled = false;
        }
    });

    function locationCoordinatesAreValid() {
        return locationAddress.value.trim() !== '' && hotelCoordinates
            && Number.isFinite(hotelCoordinates.latitude) && Math.abs(hotelCoordinates.latitude) <= 90
            && Number.isFinite(hotelCoordinates.longitude) && Math.abs(hotelCoordinates.longitude) <= 180;
    }
</script>