<?php
// Validates if CORS headers work independent of Laravel
header("Access-Control-Allow-Origin: https://toplago.com");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

echo json_encode([
    "status" => "ok", 
    "message" => "CORS headers are working from a plain PHP file.",
    "server_software" => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'
]);
