<?php
/**
 * auth-handler.php
 * 
 * Punto de entrada que:
 * 1. NO redirige automáticamente a login
 * 2. Proporciona el estado de autenticación al cliente
 * 3. Permite que index.html se cargue sin autenticación
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Obtener estado de autenticación de Azure
$userId = $_SERVER['HTTP_X_MS_CLIENT_PRINCIPAL_ID'] ?? 
          $_SERVER['X_MS_CLIENT_PRINCIPAL_ID'] ?? null;

$userName = $_SERVER['HTTP_X_MS_CLIENT_PRINCIPAL_NAME'] ?? 
            $_SERVER['X_MS_CLIENT_PRINCIPAL_NAME'] ?? 
            'Convidat';

$userEmail = $_SERVER['HTTP_X_MS_CLIENT_PRINCIPAL_EMAIL'] ?? 
             $_SERVER['X_MS_CLIENT_PRINCIPAL_EMAIL'] ?? null;

$isAuthenticated = !empty($userId);

// Retornar estado como JSON
echo json_encode([
    'isAuthenticated' => $isAuthenticated,
    'userId' => $userId,
    'userName' => $userName,
    'userEmail' => $userEmail
]);
?>