<?php

declare(strict_types=1);

/**
 * Routeur du serveur PHP intégré (développement uniquement) : un fichier statique existant de public/ est servi
 * tel quel, tout le reste passe par le contrôleur frontal de Symfony. Sans lui, un dossier existant comme
 * public/app/ empêche le serveur de remonter jusqu'à index.php.
 */
$public = dirname(__DIR__, 2).'/public';
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', \PHP_URL_PATH));
$file = realpath($public.$path);

if (false !== $file && str_starts_with($file, $public.'/') && is_file($file) && !str_ends_with($file, '.php')) {
    // Le serveur intégré n'ajoute aucun en-tête à un fichier qu'il sert lui-même : on le sert ici, avec les mêmes
    // en-têtes que SecurityHeadersListener (jamais pour ce qui n'est pas sous /app/, où rien de compilé ne vit).
    if (str_starts_with($path, '/app/')) {
        require_once dirname(__DIR__, 2).'/vendor/autoload.php';
        $types = ['css' => 'text/css', 'js' => 'text/javascript', 'mjs' => 'text/javascript', 'html' => 'text/html; charset=UTF-8', 'json' => 'application/json', 'svg' => 'image/svg+xml', 'woff2' => 'font/woff2', 'woff' => 'font/woff', 'map' => 'application/json'];
        $type = $types[strtolower(pathinfo($file, \PATHINFO_EXTENSION))] ?? mime_content_type($file) ?: 'application/octet-stream';
        header('Content-Type: '.$type);
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Content-Security-Policy: '.\App\Http\Web\SecurityHeadersListener::CONTENT_SECURITY_POLICY);
        readfile($file);

        return true;
    }

    return false;
}

$_SERVER['SCRIPT_FILENAME'] = $public.'/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';
require $public.'/index.php';
