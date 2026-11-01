<?php
date_default_timezone_set('Asia/Amman');
ini_set('display_errors', '0');
ini_set('log_errors', '0');

require __DIR__ . '/functions.php';
if (is_file(__DIR__ . '/../config.php')) {
    require __DIR__ . '/../config.php';
} else {
    require __DIR__ . '/../config.example.php';
    // Use the existing local settings while developing; .env is not submitted.
    $settings = [];
    if (is_file(__DIR__ . '/../.env')) {
        foreach (file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES) as $line) {
            $line = trim($line);
            if ($line !== '' && $line[0] !== '#' && strpos($line, '=') !== false) {
                $parts = explode('=', $line, 2);
                $settings[trim($parts[0])] = trim(trim($parts[1]), "\"'");
            }
        }
    }
    $oracleUser = $settings['ORACLE_USER'] ?? '';
    $oraclePassword = $settings['ORACLE_PASSWORD'] ?? '';
    $oracleDsn = $settings['ORACLE_DSN'] ?? '';
    $mongoUri = $settings['MONGODB_URI'] ?? '';
    $mongoDatabase = $settings['MONGODB_DATABASE'] ?? '';
    $neo4jEndpoint = $settings['NEO4J_HTTP_ENDPOINT'] ?? '';
    $neo4jUser = $settings['NEO4J_USER'] ?? '';
    $neo4jPassword = $settings['NEO4J_PASSWORD'] ?? '';
}

$oracleConnection = false;
$connectionError = '';
if (!extension_loaded('oci8')) {
    $connectionError = 'Enable the PHP OCI8 extension.';
} elseif ($oracleUser === '' || $oraclePassword === '' || $oracleDsn === '') {
    $connectionError = 'Enter the database settings in config.php.';
} else {
    $oracleConnection = @oci_connect($oracleUser, $oraclePassword, $oracleDsn, 'AL32UTF8');
    if (!$oracleConnection) {
        $connectionError = 'Oracle connection failed.';
    } else {
        oci_set_call_timeout($oracleConnection, 8000);
    }
}

require __DIR__ . '/session.php';
