<?php
/**
 * SMS provider configuration — loaded from .env when possible.
 * Set SMS_UNISMS_API_KEY in .env instead of hardcoding here.
 */
return [
    'provider' => getenv('SMS_PROVIDER') ?: 'unisms',
    'business_name' => 'VILLANUEVA ICE PLANT',
    'twilio_sid' => getenv('SMS_TWILIO_SID') ?: '',
    'twilio_token' => getenv('SMS_TWILIO_TOKEN') ?: '',
    'twilio_from' => getenv('SMS_TWILIO_FROM') ?: '',
    'unisms_api_key' => getenv('SMS_UNISMS_API_KEY') ?: '',
    'semaphore_api_key' => getenv('SMS_SEMAPHORE_API_KEY') ?: '',
    'semaphore_sender' => getenv('SMS_SEMAPHORE_SENDER') ?: '',
];

