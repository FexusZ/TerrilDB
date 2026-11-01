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
    return false;
}

$_SERVER['SCRIPT_FILENAME'] = $public.'/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';
require $public.'/index.php';
