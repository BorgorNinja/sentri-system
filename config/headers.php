<?php
/**
 * SenTri Security Headers
 * Required by config/db.php so every page emits these headers.
 * PHP-layer headers make the vulnerability scanner's headers_list() check
 * accurate regardless of whether mod_headers processed the .htaccess rules.
 */
if (!headers_sent()) {
    // Prevent clickjacking
    header('X-Frame-Options: SAMEORIGIN');
    // Prevent MIME-type sniffing
    header('X-Content-Type-Options: nosniff');
    // Legacy XSS filter
    header('X-XSS-Protection: 1; mode=block');
    // Limit referrer leakage
    header('Referrer-Policy: strict-origin-when-cross-origin');
    // Content Security Policy
    // unsafe-inline is required while the app uses inline <script>/<style>.
    // Tighten progressively once inline code is moved to external files.
    header(
        "Content-Security-Policy: " .
        "default-src 'self'; " .
        "script-src 'self' 'unsafe-inline'; " .
        "style-src 'self' 'unsafe-inline'; " .
        "font-src 'self' data:; " .
        "img-src 'self' data: https: blob:; " .
        "connect-src 'self'; " .
        "frame-ancestors 'self'; " .
        "object-src 'none'; " .
        "base-uri 'self'"
    );
    // Feature/Permissions policy
    header('Permissions-Policy: geolocation=(self), camera=(), microphone=()');
    // HSTS — browsers ignore this on plain HTTP, so it is safe to always emit.
    // Once HTTPS is active it enforces secure connections for 1 year.
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}
