<?php
/**
 * email_config.php – SenTri Email Configuration
 *
 * HOW TO SET UP GMAIL APP PASSWORD:
 * 1. Go to your Google Account → Security
 * 2. Enable 2-Step Verification (required for App Passwords)
 * 3. Go to Security → App passwords
 * 4. Select "Mail" and "Other (custom name)" → type "SenTri"
 * 5. Copy the 16-character password (e.g. "abcd efgh ijkl mnop")
 * 6. Paste it below WITHOUT spaces (e.g. "abcdefghijklmnop")
 *
 * NEVER commit this file to a public repository.
 * Add email_config.php to .gitignore.
 */

// ─── Gmail SMTP Settings ──────────────────────────────────────────────────────
define('MAIL_HOST',      'smtp.gmail.com');
define('MAIL_PORT',      587);                          // STARTTLS port

// ── Your Gmail address ──
define('MAIL_USERNAME',  'sican.isaac201419@gmail.com');       // ← CHANGE THIS

// ── Gmail App Password (16 chars, no spaces) ──
define('MAIL_PASSWORD',  'gcfrvhzsempvngjq');           // ← CHANGE THIS

// ── Sender name & address shown to recipients ──
define('MAIL_FROM',      'sican.isaac201419@gmail.com');       // ← CHANGE THIS
define('MAIL_FROM_NAME', 'SenTri');

// ─── Application URL (no trailing slash) ─────────────────────────────────────
// Change this to your actual domain in production:
//   define('APP_URL', 'https://yourdomain.com/sentri');
// !! IMPORTANT: Set this to the EXACT URL of your project root — no trailing slash.
// If your project is at http://localhost/sentri-system/ then set:
//   define('APP_URL', 'http://localhost/sentri-system');
// If served from webroot:
//   define('APP_URL', 'http://localhost');
// Wrong APP_URL = password reset / verification links point to wrong address.
define('APP_URL', 'http://localhost');        // ← CHANGE THIS TO MATCH YOUR INSTALL PATH
