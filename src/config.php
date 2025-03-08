<?php

$host = 'localhost';
$db_name = 'middlewareOmie';
$username = 'root';
$password = 'ADS2023';

$conn = new mysqli($host, $username, $password, $db_name);

if ($conn->connect_error) {
    die(json_encode(['error' => "Connection error: " . $conn->connect_error]));
}

$conn->set_charset("utf8mb4");
