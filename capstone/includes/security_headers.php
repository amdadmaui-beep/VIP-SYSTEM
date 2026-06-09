<?php
declare(strict_types=1);

/**
 * Security Headers
 *
 * Sets HTTP security headers including Content-Security-Policy (Report-Only),
 * clickjacking protection, MIME sniffing prevention, and more.
 *
 * Include this file early in every page request after session start,
 * before any HTML output.
 *
 * Usage:
 *   require_once __DIR__ . '/includes/security_headers.php';
 */

if (headers_sent()) {
    return;
}

// ── Content-Security-Policy ───────────────────────────────────────────────────
// Enforcing CSP. All violations are reported via the report-uri endpoint.

$cspDirectives = [
    "default-src 'self'",
    "script-src 'self' 'unsafe-inline' 'unsafe-eval' cdn.tailwindcss.com cdn.jsdelivr.net unpkg.com cdnjs.cloudflare.com",
    "style-src 'self' 'unsafe-inline' fonts.googleapis.com cdnjs.cloudflare.com cdn.jsdelivr.net unpkg.com",
    "font-src 'self' fonts.gstatic.com cdnjs.cloudflare.com data:",
    "img-src 'self' data: tile.openstreetmap.org unpkg.com cdnjs.cloudflare.com cdn.jsdelivr.net grainy-gradients.vercel.app blob:",
    "connect-src 'self' nominatim.openstreetmap.org photon.komoot.io api.open-meteo.com router.project-osrm.org graphhopper.com api.openrouteservice.org generativelanguage.googleapis.com tile.openstreetmap.org unpkg.com cdn.jsdelivr.net ws://localhost:8090 wss://localhost:8090",
    "frame-src 'none'",
    "object-src 'none'",
    "base-uri 'self'",
    "form-action 'self'",
];

$csp = implode('; ', $cspDirectives);
header("Content-Security-Policy: $csp");

// ── HSTS (only meaningful over HTTPS; skip localhost to avoid browser lockout) ──
$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
if ($host !== 'localhost' && strpos($host, '127.0.0.1') !== 0 && strpos($host, '::1') !== 0) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// ── Other Security Headers ────────────────────────────────────────────────────

// Prevent MIME-type sniffing
header('X-Content-Type-Options: nosniff');

// Prevent clickjacking
header('X-Frame-Options: SAMEORIGIN');

// Enable browser XSS filter (legacy, but still useful in older browsers)
header('X-XSS-Protection: 1; mode=block');

// Referrer policy
header('Referrer-Policy: strict-origin-when-cross-origin');

// Permissions policy – restrict sensitive browser features
header("Permissions-Policy: geolocation=(self), camera=(), microphone=(), payment=(), usb=(), fullscreen=(self)");
