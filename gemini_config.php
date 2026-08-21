<?php
// Google Gemini API configuration
// IMPORTANT: create a new API key and keep it private.
define('GEMINI_API_KEY', getenv('GEMINI_API_KEY') ?: '');
define('GEMINI_MODEL', 'gemini-3.5-flash');
define(
    'GEMINI_API_URL',
    'https://generativelanguage.googleapis.com/v1beta/models/'
    . GEMINI_MODEL
    . ':generateContent'
);
