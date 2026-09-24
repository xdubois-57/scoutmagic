/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Ambient declaration for Leaflet, exposed as the global `L` by the
// vendored public/assets/vendor/leaflet/leaflet.js <script> tag — no npm
// package, no bundler, same treatment as Chart.js (see
// types/window-globals.d.ts and AGENTS.md § CSS / frontend).
//
// Deliberately narrow: only what public/assets/js/map.js, camps-map.js and
// covoiturage-organize.js actually call. Mirroring Leaflet's full API here would be a second, always-stale
// copy of its type definitions, and the point of this file is to catch a
// typo in our own code, not to describe someone else's library.

type LeafletLatLng = [number, number];

interface LeafletLayer {
    addTo(map: LeafletMap): LeafletLayer;
}

interface LeafletMarker extends LeafletLayer {
    addTo(map: LeafletMap): LeafletMarker;
    on(event: string, handler: () => void): LeafletMarker;
    getLatLng(): { lat: number; lng: number };
    setLatLng(latlng: LeafletLatLng): LeafletMarker;
    remove(): LeafletMarker;
}

interface LeafletMouseEvent {
    latlng: { lat: number; lng: number };
}

interface LeafletBounds {
    // Opaque: only ever handed straight back to fitBounds().
    readonly _leafletBounds?: never;
}

interface LeafletFeatureGroup {
    getBounds(): LeafletBounds;
}

interface LeafletMap {
    setView(center: LeafletLatLng, zoom: number): LeafletMap;
    fitBounds(bounds: LeafletBounds, options?: { padding?: [number, number]; maxZoom?: number }): LeafletMap;
    on(event: string, handler: (event: LeafletMouseEvent) => void): LeafletMap;
    invalidateSize(): LeafletMap;
}

interface LeafletStatic {
    map(element: HTMLElement | string): LeafletMap;
    tileLayer(urlTemplate: string, options?: { attribution?: string; maxZoom?: number }): LeafletLayer;
    marker(latlng: LeafletLatLng, options?: { draggable?: boolean }): LeafletMarker;
    featureGroup(layers: LeafletLayer[]): LeafletFeatureGroup;
}

declare const L: LeafletStatic;

interface Window {
    // public/assets/vendor/leaflet/leaflet.js — present only on the pages
    // that load it (the camps list, the carpool organiser's form).
    L?: LeafletStatic;
}
