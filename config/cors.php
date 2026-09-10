<?php
declare(strict_types=1);

/**
 * Cabeceras CORS para los endpoints de app/api/ (patron de Porcify Manager,
 * que lo incluye al principio de cada *-api.php).
 *
 * Por defecto NO se emite ninguna: la interfaz y la API comparten origen.
 * Solo se responde a origenes de una lista blanca explicita, y nunca con
 * comodin junto a credenciales.
 */

$cors = \App\Core\Config::get('security.cors', []);

if (!empty($cors['enabled'])) {
    $origen = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origen !== '' && in_array($origen, (array) ($cors['allowed_origins'] ?? []), true)) {
        header('Access-Control-Allow-Origin: ' . $origen);
        header('Vary: Origin');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Headers: ' . implode(', ', (array) ($cors['allowed_headers'] ?? [])));
        header('Access-Control-Allow-Methods: ' . implode(', ', (array) ($cors['allowed_methods'] ?? [])));
        header('Access-Control-Max-Age: ' . (int) ($cors['max_age'] ?? 600));
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
