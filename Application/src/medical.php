<?php
require 'includes/connect.php';
$title = 'Medical Information';
$stayId = 0;
$stay = [];
$stays = [];
$search = '';
$offset = 0;
$document = [];
$logs = [];
$version = '';
$medicalReady = false;

try {
    $stayId = id_value($_GET, 'stayId');
    $search = text_value($_GET, 'q', 100, false);
    $offset = id_value($_GET, 'offset');
    if (isset($_POST['saveMedical'])) {
        check_post(true);
        $stayId = id_value($_POST, 'stayId');
        if (text_value($_POST, 'formComplete', 1) !== '1') {
            throw new Exception('Form incomplete. Reload the page.');
        }
    }
    if ($canOperate && $stayId) {
        $sql = "SELECT s.stayId, s.patientId, h.hospitalId, s.leavingDate, h.hospitalName,
                       p.Fname || ' ' || p.Mname || ' ' || p.Lname AS patientName
                FROM ICU_Stay s
                JOIN ICU_Bed b ON b.bedId = s.bedId
                JOIN Hospital h ON h.hospitalId = b.hospitalId
                JOIN Patient p ON p.patientId = s.patientId
                WHERE s.stayId = :stayId";
        if ($mode === 'staff') {
            $sql .= '
                AND b.hospitalId = :hospitalId';
        }
        if (isset($_POST['saveMedical'])) {
            $sql .= ' FOR UPDATE OF s.stayId WAIT 5';
        }
        $statement = oci_parse($oracleConnection, $sql);
        oci_bind_by_name($statement, ':stayId', $stayId, -1, SQLT_INT);
        if ($mode === 'staff') {
            oci_bind_by_name($statement, ':hospitalId', $hospitalId, -1, SQLT_INT);
        }
        if (!oci_execute($statement, OCI_NO_AUTO_COMMIT)) {
            throw new Exception(oracle_error($statement));
        }
        $stay = oci_fetch_array($statement, OCI_ASSOC | OCI_RETURN_NULLS);
        if (!$stay) {
            throw new Exception('Stay not found in this hospital.');
        }
        if (isset($_POST['saveMedical']) && $stay['LEAVINGDATE'] !== null) {
            throw new Exception('This stay has ended.');
        }
        if (!extension_loaded('mongodb') || $mongoUri === '' || $mongoDatabase === '') {
            throw new Exception('Check the MongoDB settings.');
        }
        try {
            $mongoConnection = new MongoDB\Driver\Manager($mongoUri, ['serverSelectionTimeoutMS' => 4000]);
            $query = new MongoDB\Driver\Query(['stayId' => $stayId], ['limit' => 2]);
            $result = $mongoConnection->executeQuery($mongoDatabase . '.Stay_Info', $query);
            $result->setTypeMap(['root' => 'array', 'document' => 'array', 'array' => 'array']);
            $documents = $result->toArray();
        } catch (Throwable $exception) {
            throw new Exception('MongoDB could not read this stay.');
        }
        if (count($documents) > 1) {
            throw new Exception('Duplicate medical records exist for this stay.');
        }
        $document = $documents[0] ?? [];
        $versionData = $document;
        unset($versionData['_id']);
        $version = hash('sha256', json_encode($versionData));
        $logs = $document['monitoringLogs'] ?? [];
        if (isset($_POST['saveMedical'])) {
            if (!hash_equals($version, text_value($_POST, 'version', 64))) {
                throw new Exception('Medical information changed. Reload the page.');
            }
            $fields = [];
            foreach (['clinicalNotes', 'incidentNotes'] as $field) {
                $text = text_value($_POST, $field, 40000, false);
                $fields[$field] = [];
                if ($text !== '') {
                    foreach (preg_split('/\r\n|\n|\r/', $text) as $note) {
                        if (trim($note) === '' || strlen($note) > 2000) {
                            throw new Exception('Check the note length and remove blank lines.');
                        }
                        $fields[$field][] = trim($note);
                    }
                }
            }
            $fields['monitoringLogs'] = [];
            $postedLogs = $_POST['logs'] ?? [];
            if (!is_array($postedLogs) || count($postedLogs) > 200) {
                throw new Exception('Too many monitoring entries.');
            }
            foreach ($postedLogs as $log) {
                if (!is_array($log) || array_diff(array_keys($log), ['time', 'temperature', 'heartRate', 'oxygenSaturation', 'bloodPressure'])) {
                    throw new Exception('Invalid monitoring entry.');
                }
                $values = [];
                foreach (['time', 'temperature', 'heartRate', 'oxygenSaturation', 'bloodPressure'] as $field) {
                    $values[$field] = text_value($log, $field, 20, false);
                }
                if (implode('', $values) === '') {
                    continue;
                }
                if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $values['time'])) {
                    throw new Exception('Enter a valid monitoring time.');
                }
                $entry = ['time' => $values['time']];
                foreach (['temperature', 'heartRate', 'oxygenSaturation'] as $field) {
                    if ($values[$field] !== '') {
                        if (!is_numeric($values[$field]) || !is_finite((float) $values[$field]) || (float) $values[$field] < 0 || (float) $values[$field] > 10000) {
                            throw new Exception('Check the monitoring values.');
                        }
                        if ($field === 'oxygenSaturation' && (float) $values[$field] > 100) {
                            throw new Exception('Oxygen saturation must be between 0 and 100.');
                        }
                        if ($field !== 'temperature' && !ctype_digit($values[$field])) {
                            throw new Exception('Heart rate and oxygen saturation must be whole numbers.');
                        }
                        $entry[$field] = (float) $values[$field];
                        if ($field !== 'temperature') {
                            $entry[$field] = (int) $values[$field];
                        }
                    }
                }
                if ($values['bloodPressure'] !== '') {
                    if (!preg_match('/^\d{1,3}\/\d{1,3}$/D', $values['bloodPressure'])) {
                        throw new Exception('Use a blood pressure such as 120/80.');
                    }
                    $entry['bloodPressure'] = $values['bloodPressure'];
                }
                if (count($entry) < 2) {
                    throw new Exception('Enter a monitoring value.');
                }
                $fields['monitoringLogs'][] = $entry;
            }
            $set = ['stayId' => $stayId];
            $unset = [];
            foreach ($fields as $field => $value) {
                if ($value) {
                    $set[$field] = $value;
                } else {
                    $unset[$field] = '';
                }
            }
            $update = ['$set' => $set];
            if ($unset) {
                $update['$unset'] = (object) $unset;
            }
            $filter = ['stayId' => $stayId];
            if ($document) {
                $filter['_id'] = $document['_id'];
            }
            try {
                $bulk = new MongoDB\Driver\BulkWrite();
                $bulk->update($filter, $update, ['upsert' => !$document, 'multi' => false]);
                $mongoConnection->executeBulkWrite($mongoDatabase . '.Stay_Info', $bulk, new MongoDB\Driver\WriteConcern('majority', 8000));
            } catch (Throwable $exception) {
                throw new Exception('MongoDB save not confirmed. Reload this stay.');
            }
            oci_rollback($oracleConnection); // Release the stay lock; Oracle was not changed.
            $_SESSION['message'] = 'Medical information saved.';
            header('Location: medical.php?stayId=' . $stayId, true, 303);
            exit;
        }
        $medicalReady = true;
    }
    if ($canOperate && !$stayId) {
        $sql = "SELECT s.stayId, s.patientId, s.bedId, b.bedNumber, h.hospitalId, h.hospitalName,
                       p.Fname || ' ' || p.Mname || ' ' || p.Lname AS patientName,
                       p.nationalId,
                       TO_CHAR(s.enteringDate, 'YYYY-MM-DD') AS enteringDate,
                       TO_CHAR(s.leavingDate, 'YYYY-MM-DD') AS leavingDate
                FROM ICU_Stay s
                JOIN ICU_Bed b ON b.bedId = s.bedId
                JOIN Hospital h ON h.hospitalId = b.hospitalId
                JOIN Patient p ON p.patientId = s.patientId
                WHERE (TO_CHAR(s.stayId) LIKE :search
                       OR LOWER(p.Fname || ' ' || p.Mname || ' ' || p.Lname) LIKE :search
                       OR p.nationalId LIKE :search
                       OR TO_CHAR(h.hospitalId) LIKE :search
                       OR LOWER(h.hospitalName) LIKE :search)";
        if ($mode === 'staff') {
            $sql .= '
                AND b.hospitalId = :hospitalId';
        }
        $sql .= '
                ORDER BY s.stayId DESC
                OFFSET :offset ROWS FETCH NEXT 100 ROWS ONLY';
        $statement = oci_parse($oracleConnection, $sql);
        $searchValue = '%' . strtolower($search) . '%';
        oci_bind_by_name($statement, ':search', $searchValue);
        oci_bind_by_name($statement, ':offset', $offset, -1, SQLT_INT);
        if ($mode === 'staff') {
            oci_bind_by_name($statement, ':hospitalId', $hospitalId, -1, SQLT_INT);
        }
        if (!oci_execute($statement, OCI_NO_AUTO_COMMIT)) {
            throw new Exception(oracle_error($statement));
        }
        while ($row = oci_fetch_array($statement, OCI_ASSOC | OCI_RETURN_NULLS)) {
            $stays[] = $row;
        }
    }
} catch (Exception $exception) {
    if ($oracleConnection) {
        oci_rollback($oracleConnection);
    }
    $error = $exception->getMessage();
}
require 'includes/header.php';
?>
<h1>Medical Information</h1>
<?php if ($canOperate) { ?>
    <form method="get" class="filters">
        <label>
            Stay ID, patient name, national ID or hospital
            <input name="q" value="<?php echo e($search); ?>">
        </label>
        <button>Search Stays</button>
        <a href="medical.php">Clear Search</a>
    </form>
    <?php if (!$stayId) { ?>
        <section class="panel">
            <div class="table-wrap">
                <table id="stay-results">
                    <thead>
                        <tr>
                            <th>Stay ID</th>
                            <th>Patient</th>
                            <th>National ID</th>
                            <th>Hospital</th>
                            <th>Bed</th>
                            <th>Admission</th>
                            <th>Leaving Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stays as $row) { ?>
                            <tr>
                                <td><a href="medical.php?stayId=<?php echo e($row['STAYID']); ?>">#<?php echo e($row['STAYID']); ?></a></td>
                                <td>#<?php echo e($row['PATIENTID']); ?> — <?php echo e($row['PATIENTNAME']); ?></td>
                                <td><?php echo e($row['NATIONALID']); ?></td>
                                <td>#<?php echo e($row['HOSPITALID']); ?> — <?php echo e($row['HOSPITALNAME']); ?></td>
                                <td>#<?php echo e($row['BEDID']); ?> — <?php echo e($row['BEDNUMBER']); ?></td>
                                <td><?php echo e($row['ENTERINGDATE']); ?></td>
                                <td><?php echo e($row['LEAVINGDATE'] ?? 'Active'); ?></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
            <?php if (!$stays) { ?>
                <p>No stays found.</p>
            <?php } ?>
        </section>
        <div class="links">
            <?php if ($offset > 0) { ?>
                <a href="medical.php?q=<?php echo urlencode($search); ?>&amp;offset=<?php echo max(0, $offset - 100); ?>">Previous</a>
            <?php } ?>
            <?php if (count($stays) === 100) { ?>
                <a href="medical.php?q=<?php echo urlencode($search); ?>&amp;offset=<?php echo $offset + 100; ?>">Next</a>
            <?php } ?>
        </div>
    <?php } ?>
    <?php if ($medicalReady) { ?>
        <section class="context">
            <strong>Stay #<?php echo e($stayId); ?></strong>
            <span>Patient #<?php echo e($stay['PATIENTID']); ?> — <?php echo e($stay['PATIENTNAME']); ?></span>
            <span>Hospital #<?php echo e($stay['HOSPITALID']); ?> — <?php echo e($stay['HOSPITALNAME']); ?></span>
        </section>
        <?php $active = $stay['LEAVINGDATE'] === null; ?>
        <?php if (!$active) { ?>
            <p>This stay has ended. Medical information is read-only.</p>
        <?php } ?>
        <form method="post">
            <?php csrf_fields(); ?>
            <input type="hidden" name="stayId" value="<?php echo e($stayId); ?>">
            <input type="hidden" name="version" value="<?php echo e($version); ?>">
            <div class="two-column">
                <section class="panel">
                    <label>
                        Clinical Notes
                        <textarea name="clinicalNotes" rows="7" maxlength="40000" <?php if (!$active) { echo 'readonly'; } ?>><?php echo e(implode("\n", $document['clinicalNotes'] ?? [])); ?></textarea>
                    </label>
                </section>
                <section class="panel">
                    <label>
                        Incident Notes
                        <textarea name="incidentNotes" rows="7" maxlength="40000" <?php if (!$active) { echo 'readonly'; } ?>><?php echo e(implode("\n", $document['incidentNotes'] ?? [])); ?></textarea>
                    </label>
                </section>
            </div>
            <section class="panel">
                <h2>Monitoring Logs</h2>
                <div id="monitoringLogs">
                    <?php if ($active) { $logs[] = []; } ?>
                    <?php foreach ($logs as $index => $log) { ?>
                        <div class="monitoring-row">
                            <label>
                                Time
                                <input type="time" name="logs[<?php echo $index; ?>][time]" value="<?php echo e($log['time'] ?? ''); ?>" <?php if (!$active) { echo 'readonly'; } ?>>
                            </label>
                            <label>
                                Temperature
                                <input type="number" step="any" min="0" name="logs[<?php echo $index; ?>][temperature]" value="<?php echo e($log['temperature'] ?? ''); ?>" <?php if (!$active) { echo 'readonly'; } ?>>
                            </label>
                            <label>
                                Heart Rate
                                <input type="number" min="0" name="logs[<?php echo $index; ?>][heartRate]" value="<?php echo e($log['heartRate'] ?? ''); ?>" <?php if (!$active) { echo 'readonly'; } ?>>
                            </label>
                            <label>
                                Oxygen (%)
                                <input type="number" min="0" max="100" name="logs[<?php echo $index; ?>][oxygenSaturation]" value="<?php echo e($log['oxygenSaturation'] ?? ''); ?>" <?php if (!$active) { echo 'readonly'; } ?>>
                            </label>
                            <label>
                                Blood Pressure
                                <input name="logs[<?php echo $index; ?>][bloodPressure]" value="<?php echo e($log['bloodPressure'] ?? ''); ?>" <?php if (!$active) { echo 'readonly'; } ?>>
                            </label>
                            <?php if ($active) { ?>
                                <button type="button" class="remove-log secondary">Remove</button>
                            <?php } ?>
                        </div>
                    <?php } ?>
                </div>
                <?php if ($active) { ?>
                    <button type="button" id="addLog" class="secondary">Add Entry</button>
                <?php } ?>
            </section>
            <?php if ($active) { ?>
                <input type="hidden" name="formComplete" value="1">
                <button name="saveMedical">Save</button>
            <?php } ?>
        </form>
    <?php } ?>
<?php } ?>
<?php require 'includes/footer.php'; ?>
