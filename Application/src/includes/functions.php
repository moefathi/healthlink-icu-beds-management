<?php
function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function text_value($source, $name, $max = 100, $required = true)
{
    $value = $source[$name] ?? '';
    if (!is_string($value) || strlen(trim($value)) > $max || strpos($value, "\0") !== false) {
        throw new Exception('Check ' . $name . '.');
    }
    $value = trim($value);
    if ($required && $value === '') {
        throw new Exception('Enter ' . $name . '.');
    }
    return $value;
}

function id_value($source, $name)
{
    $value = $source[$name] ?? '0';
    if (!is_scalar($value) || !ctype_digit((string) $value) || (float) $value > PHP_INT_MAX) {
        throw new Exception('Invalid ' . $name . '.');
    }
    return (int) $value;
}

function date_value($source, $name)
{
    $value = text_value($source, $name, 10);
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value || $value > date('Y-m-d')) {
        throw new Exception('Enter a valid ' . $name . '.');
    }
    return $value;
}

function check_post($hospitalOperation = false)
{
    global $mode, $hospitalId;
    $token = text_value($_POST, 'csrf', 64);
    if (!hash_equals($_SESSION['csrf'], $token)) {
        throw new Exception('Form expired. Reload the page.');
    }
    if ($hospitalOperation && $mode === 'staff') {
        if (!$hospitalId) {
            throw new Exception('Select a hospital.');
        }
        if (id_value($_POST, 'contextHospitalId') !== $hospitalId) {
            throw new Exception('Hospital changed. Reload the page.');
        }
    }
}

function csrf_fields()
{
    global $hospitalId;
    echo '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">';
    echo '<input type="hidden" name="contextHospitalId" value="' . e($hospitalId) . '">';
}

function oracle_error($statement)
{
    $error = oci_error($statement);
    $code = abs((int) ($error['code'] ?? 0));
    $messages = [
        1 => 'These details already exist.',
        1400 => 'Complete the required fields.',
        12899 => 'A value is too long.',
        2290 => 'Check the entered values.',
        2291 => 'A selected record no longer exists.',
        2292 => 'This record has history.',
        54 => 'Record busy. Try again.',
        30006 => 'Record busy. Try again.',
        20999 => 'Check the leaving date and stay status.',
        20998 => 'This bed is in use.',
        20997 => 'This stay has ended.',
        20994 => 'This stay belongs to another hospital.',
        20993 => 'Transfer date is before admission.',
        20992 => 'This stay has ended.',
        20991 => 'This stay has ended.',
        20990 => 'This equipment belongs to another hospital.',
        20989 => 'This equipment is in use.',
        20987 => 'Charge date is before admission.',
        20986 => 'This stay has ended.'
    ];
    return $messages[$code] ?? 'Oracle could not complete this operation.';
}

function neo4j_query($query, $parameters = [])
{
    global $neo4jEndpoint, $neo4jUser, $neo4jPassword;
    if (!extension_loaded('curl') || !preg_match('~^https?://[^/]+/db/[^/]+/tx/commit$~', $neo4jEndpoint)) {
        throw new Exception('Check the Neo4j HTTP endpoint in config.php.');
    }
    $handle = curl_init($neo4jEndpoint);
    $body = ['statements' => [['statement' => $query, 'parameters' => (object) $parameters]]];
    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $neo4jUser . ':' . $neo4jPassword,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS
    ]);
    $response = curl_exec($handle);
    $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);
    $data = json_decode((string) $response, true);
    if ($status !== 200 || !isset($data['results'], $data['errors']) || $data['errors']) {
        throw new Exception('Neo4j is unavailable. Check its connection.');
    }
    $rows = [];
    foreach ($data['results'][0]['data'] as $record) {
        $rows[] = array_combine($data['results'][0]['columns'], $record['row']);
    }
    return $rows;
}
