<?php
/**
 * SMTP configuration — now loaded from .env via MAIL_* variables.
 * This file is kept as a fallback only. Set MAIL_* in .env instead.
 */
return [
    'host' => getenv('MAIL_HOST') ?: '',
    'port' => getenv('MAIL_PORT') ?: 587,
    'username' => getenv('MAIL_USERNAME') ?: '',
    'password' => getenv('MAIL_PASSWORD') ?: '',
    'from_address' => getenv('MAIL_FROM_ADDRESS') ?: '',
    'from_name' => getenv('MAIL_FROM_NAME') ?: 'VIP System',
    'encryption' => getenv('MAIL_ENCRYPTION') ?: 'tls',
];

