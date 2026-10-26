<?php
// Settings. This file sits outside public/, so browsers can never read it.
// No secrets are stored here: values come from environment variables (Railway),
// with local defaults for running on your own computer.
return [
    // --- database ---
    'db_host' => getenv('DB_HOST') ?: getenv('MYSQLHOST') ?: '127.0.0.1',
    'db_name' => getenv('DB_NAME') ?: getenv('MYSQLDATABASE') ?: 'kioskfinal',
    'db_user' => getenv('DB_USER') ?: getenv('MYSQLUSER') ?: 'root',
    'db_pass' => getenv('DB_PASS') ?: getenv('MYSQLPASSWORD') ?: '',

    // --- chatbot (Ollama, reached through a tunnel when deployed) ---
    'ollama_url'     => getenv('OLLAMA_URL') ?: 'http://127.0.0.1:11434',
    'ollama_model'   => getenv('OLLAMA_MODEL') ?: 'llama3.2:3b',
    'ollama_timeout' => (int) (getenv('OLLAMA_TIMEOUT') ?: 120),

    // --- shown to students by the chatbot (EDIT THESE) ---
    'clinic_name'      => 'School Clinic',
    'clinic_location'  => 'Ground floor, beside the main office',
    'clinic_hours'     => 'Mon-Fri, 8:00 AM - 5:00 PM',
    'emergency_number' => '911',
];