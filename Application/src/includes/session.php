<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('HealthLink');
    session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Lax']);
}
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$mode = $_SESSION['mode'] ?? 'staff';
$hospitalId = 0;
$hospitalName = '';
$hospitals = [];
$staffHospitals = [];
$missingHospitals = [];
$error = $connectionError;
$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);
$page = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');

if ($oracleConnection) {
    $sql = 'SELECT *
            FROM Hospital
            ORDER BY hospitalId ASC';
    $statement = oci_parse($oracleConnection, $sql);
    if (oci_execute($statement, OCI_NO_AUTO_COMMIT)) {
        $byHospitalId = [];
        while ($row = oci_fetch_array($statement, OCI_ASSOC | OCI_RETURN_NULLS)) {
            $hospitals[] = $row;
            $byHospitalId[(int) $row['HOSPITALID']] = $row;
        }
        $priorityIds = [445, 554, 570, 580, 588, 600];
        foreach ($priorityIds as $id) {
            if (isset($byHospitalId[$id])) {
                $staffHospitals[] = $byHospitalId[$id];
            } else {
                $missingHospitals[] = $id;
            }
        }
        foreach ($hospitals as $row) {
            if (!in_array((int) $row['HOSPITALID'], $priorityIds, true)) {
                $staffHospitals[] = $row;
            }
        }
        if ($mode === 'staff' && empty($_SESSION['hospitalContextInitialized'])) {
            if (!isset($_SESSION['hospitalId']) && isset($byHospitalId[445])) {
                $_SESSION['hospitalId'] = 445;
            }
            $_SESSION['hospitalContextInitialized'] = true;
        }
        $selectedId = (int) ($_SESSION['hospitalId'] ?? 0);
        if (isset($byHospitalId[$selectedId])) {
            $hospitalId = $selectedId;
            $hospitalName = $byHospitalId[$selectedId]['HOSPITALNAME'];
        } elseif (isset($_SESSION['hospitalId'])) {
            unset($_SESSION['hospitalId']);
            $error = 'Selected hospital no longer exists.';
        }
    } else {
        $error = oracle_error($statement);
    }
    oci_free_statement($statement);
}

if (isset($_POST['changeMode']) || isset($_POST['selectHospital'])) {
    try {
        check_post();
        if (isset($_POST['changeMode'])) {
            $newMode = text_value($_POST, 'changeMode', 5);
            if (!in_array($newMode, ['staff', 'admin'], true)) {
                throw new Exception('Invalid mode.');
            }
            $_SESSION['mode'] = $newMode;
        } else {
            if ($mode !== 'staff') {
                throw new Exception('Switch to Staff mode.');
            }
            $selectedId = id_value($_POST, 'hospitalId');
            $found = false;
            foreach ($hospitals as $row) {
                if ((int) $row['HOSPITALID'] === $selectedId) {
                    $found = true;
                }
            }
            if (!$found) {
                throw new Exception('Select an existing hospital.');
            }
            $_SESSION['hospitalId'] = $selectedId;
        }
        if ($page === 'admin.php' && $_SESSION['mode'] === 'staff') {
            $page = 'index.php';
        }
        header('Location: ' . $page, true, 303);
        exit;
    } catch (Exception $exception) {
        $error = $exception->getMessage();
    }
}

$canOperate = $oracleConnection && ($mode === 'admin' || $hospitalId > 0);
