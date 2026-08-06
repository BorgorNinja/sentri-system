<?php
/**
 * config/csrf.php — CSRF Token Generation & Validation
 *
 * Include after session_start(). Provides csrf_token(), csrf_meta_tag(),
 * csrf_script(), and csrf_validate() helpers.
 *
 * Usage in HTML pages:
 *   <?= csrf_meta_tag() ?>           <!-- in <head> -->
 *   <?= csrf_script() ?>             <!-- before </body> -->
 *
 * Usage in API endpoints:
 *   require_once __DIR__ . '/../config/csrf.php';
 *   csrf_validate();                  // at top, after session check
 */

/**
 * Get or generate the session CSRF token.
 */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * HTML <meta> tag for the CSRF token. Place in <head>.
 */
function csrf_meta_tag(): string {
    return '<meta name="csrf-token" content="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * <script> block that auto-injects the CSRF token into every fetch() call.
 * Place once per page, after the meta tag.
 */
function csrf_script(): string {
    return <<<'HTML'
<script>
(function(){
    var m = document.querySelector('meta[name="csrf-token"]');
    if (!m) return;
    var F = window.fetch;
    window.fetch = function(url, opts) {
        opts = Object.assign({}, opts);
        if (opts.headers instanceof Headers) {
            opts.headers.set('X-CSRF-Token', m.content);
        } else {
            opts.headers = Object.assign({}, opts.headers || {}, {'X-CSRF-Token': m.content});
        }
        return F.call(this, url, opts);
    };
})();
</script>
HTML;
}

/**
 * Hidden <input> for traditional form submissions.
 */
function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Validate the CSRF token on POST requests.
 * Reads from X-CSRF-Token header (fetch) or csrf_token POST field (form).
 * Call at the top of API endpoints that accept POST.
 */
function csrf_validate(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;

    $token = $_SERVER['HTTP_X_CSRF_TOKEN']
          ?? $_POST['csrf_token']
          ?? '';

    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        if (!headers_sent()) header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode([
            'status'  => 'error',
            'message' => 'Invalid or missing security token. Please refresh the page and try again.',
        ]);
        exit;
    }
}
