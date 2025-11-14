<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Agafar estat d'autenticació d'Azure
$userId = $_SERVER['HTTP_X_MS_CLIENT_PRINCIPAL_ID'] ?? 
          $_SERVER['X_MS_CLIENT_PRINCIPAL_ID'] ?? null;

$userName = $_SERVER['HTTP_X_MS_CLIENT_PRINCIPAL_NAME'] ?? 
            $_SERVER['X_MS_CLIENT_PRINCIPAL_NAME'] ?? 
            'Convidat';

$userEmail = $_SERVER['HTTP_X_MS_CLIENT_PRINCIPAL_EMAIL'] ?? 
             $_SERVER['X_MS_CLIENT_PRINCIPAL_EMAIL'] ?? null;

$isAuthenticated = !empty($userId);

// Retornar l'estat com a JSON
echo json_encode([
    'isAuthenticated' => $isAuthenticated,
    'userId' => $userId,
    'userName' => $userName,
    'userEmail' => $userEmail
]);
?>