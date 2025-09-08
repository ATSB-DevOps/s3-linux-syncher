<?php

/**
 * Get the base path for the application
 * This handles cases where the app is in a subdirectory
 */
function getBasePath() {
    // Get the directory of the current script relative to document root
    $scriptName = $_SERVER['SCRIPT_NAME'];
    $scriptDir = dirname($scriptName);
    
    // If we're in the root, return empty string, otherwise return the directory with trailing slash
    return $scriptDir === '/' ? '' : $scriptDir;
}

/**
 * Generate a URL relative to the application base
 */
function appUrl($path = '') {
    $basePath = getBasePath();
    $path = ltrim($path, '/');
    return $basePath . '/' . $path;
}

/**
 * Redirect to a path within the application
 */
function appRedirect($path = '') {
    $url = appUrl($path);
    header('Location: ' . $url);
    exit;
}