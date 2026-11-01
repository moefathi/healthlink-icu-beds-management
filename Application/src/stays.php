<?php
require 'includes/connect.php';
$title = 'ICU Stays';
$stay = [];
$stays = [];
$patients = [];
$beds = [];
$stayId = 0;
$patientId = 0;
$offset = 0;
$status = 'active';
$search = '';
$admissionHospitalId = 0;
$admissionHospitalName = '';

try {
    $stayId = id_value($_GET, 'stayId');
    $patientId = id_value($_GET, 'patientId');
    $offset = id_value($_GET, 'offset');
    $status = text_value($_GET, 'status', 10, false);
    $search = text_value($_GET, 'q', 100, false);
    $admissionHospitalId = $mode === 'staff' ? $hospitalId : id_value($_GET, 'hospitalId');
    if ($canOperate && isset($_POST['createStay'])) {
        check_post(true);
        if ($mode === 'admin') {
            $admissionHospitalId = id_value($_POST, 'hospitalId');
        }
        $patientId = id_value($_POST, 'patientId');
        $bedId = id_value($_POST, 'bedId');
        $enteringDate = date_value($_POST, 'enteringDate');
        $sql = "SELECT TO_CHAR(dateOfBirth, 'YYYY-MM-DD') AS birthDate
                FROM Patient
                WHERE patientId = :patientId
                FOR UPDATE WAIT 5";
        $statement = oci_parse($oracleConnection, $sql);
        // Serializes admissions for this patient across all hospitals and both interface modes.
        oci_bind_by_name($statement, ':patientId', $patientId, -1, SQLT_INT);
        if (!oci_execute($statement, OCI_NO_AUTO_COMMIT)) {
            throw new Exception(oracle_error($statement));
        }
        $patient = oci_fetch_array($statement, OCI_ASSOC);
        if (!$patient || $enteringDate < $patient['BIRTHDATE']) {
            throw new Exception('Check the patient and admission date.');
        }
        $sql = 'SELECT hospitalName
                FROM Hospital
                WHERE hospitalId = :hospitalId';
        $statement = oci_parse($oracleConnection, $sql);
        oci_bind_by_name($statement, ':hospitalId', $admissionHospitalId, -1, SQLT_INT);
        if (!oci_execute($statement, OCI_NO_AUTO_COMMIT)) {
            throw new Exception(oracle_error($statement));
        }
        $admissionHospital = oci_fetch_array($statement, OCI_ASSOC);
        if (!$admissionHospital) {
            throw new Exception('Select an existing hospital.');
        }
        $admissionHospitalName = $admissionHospital['HOSPITALNAME'];
        $sql = 'SELECT b.hospitalId, b.status
                FROM ICU_Bed b
                WHERE b.bedId = :bedId
                FOR UPDATE WAIT 5';
        $statement = oci_parse($oracleConnection, $sql);
        oci_bind_by_name($statement, ':bedId', $bedId, -1, SQLT_INT);
        if (!oci_execute($statement, OCI_NO_AUTO_COMMIT)) {
            throw new Exception(oracle_error($statement));
        }
        $bed = oci_fetch_array($statement, OCI_ASSOC);
        if (!$bed) {
            throw new Exception('Bed not found.');
        }
        if ((int) $bed['HOSPITALID'] !== $admissionHospitalId) {
            throw new Exception('This bed belongs to another hospital.');
        }
        $sql = 'SELECT COUNT(*) AS activeStayCount
                FROM ICU_Stay
                WHERE patientId = :patientId
                AND leavingDate IS NULL';
        $statement = oci_parse($oracleConnection, $sql);
        oci_bind_by_name($statement, ':patientId', $patientId, -1, SQLT_INT);
        if (!oci_execute($statement, OCI_NO_AUTO_COMMIT)) {
            throw new Exception(oracle_error($statement));
        }
        $row = oci_fetch_array($statement, OCI_ASSOC);
        if ((int) $row['ACTIVESTAYCOUNT'] > 0) {
            throw new Exception('This patient already has an active ICU stay.');
        }
        if ($bed['STATUS'] !== 'Available') {
            throw new Exception('This bed is in use.');
        }
        $sql = "INSERT INTO ICU_Stay (enteringDate, patientId, bedId)
                VALUES (TO_DATE(:enteringDate, 'YYYY-MM-DD'), :patientId, :bedId)

                RETURNING stayId INTO :stayId";
        $statement = oci_parse($oracleConnection, $sql);
        oci_bind_by_name($statement, ':enteringDate', $enteringDate);
        oci_bind_by_name($statement, ':patientId', $patientId, -1, SQLT_INT);
        oci_bind_by_name($statement, ':bedId', $bedId, -1, SQLT_INT);
        oci_bind_by_name($statement, ':stayId', $stayId, -1, SQLT_INT);
        if (!oci_execute($statement, OCI_NO_AUTO_COMMIT)) {
            throw new Exception(oracle_error($statement));
        }
        if (!oci_commit($oracleConnection)) {
            throw new Exception('Save not confirmed. Reload the stay list.');
        }
        $_SESSION['message'] = 'ICU stay created.';
        header('Location: stays.php?stayId=' . $stayId, true, 303);
        exit;
    }
    if ($canOperate && isset($_POST['endStay'])) {
        check_post(true);
        $stayId = id_value($_POST, 'stayId');
        $leavingDate = date_value($_POST, 'leavingDate');
        $sql = "SELECT s.stayId, s.leavingDate,
                       TO_CHAR(s.enteringDate, 'YYYY-MM-DD HH24:MI:SS') AS enteringDate
                FROM ICU_Stay s
                JOIN ICU_Bed b ON b.bedId = s.bedId
                WHERE s.stayId = :stayId";
        if ($mode === 'staff') {
            $sql .= '
                AND b.hospitalId = :hospitalId';
        }
        $sql .= '
                FOR UPDATE OF s.stayId, b.bedId WAIT 5';
        $statement = oci_parse($oracleConnection, $sql);
        oci_bind_by_name($statement, ':stayId', $stayId, -1, SQLT_INT);
        if ($mode === 'staff') {
            oci_bind_by_name($statement, ':hospitalId', $hospitalId, -1, SQLT_INT);
        }
        if (!oci_execute($statement, OCI_NO_AUTO_COMMIT)) {
            throw new Exception(oracle_error($statement));
        }
        $row = oci_fetch_array($statement, OCI_ASSOC | OCI_RETURN_NULLS);
        if (!$row) {
            throw new Exception('Stay not found in this hospital.');
        }
        if ($row['LEAVINGDATE'] !== null) {
            throw new Exception('This stay has ended.');
        }
        if ($leavingDate . ' 00:00:00' < $row['ENTERINGDATE']) {
            throw new Exception('Leaving date is before admission.');
        }
        $sql = "SELECT status
                FROM user_objects
                WHERE object_name = 'FINISHSTAY_PRC' AND object_type = 'PROCEDURE'";
        $statement = oci_parse($oracleConnection, $sql);
        if (!oci_execute($statement, OCI_NO_AUTO_COMMIT)) {
            throw new Exception(oracle_error($statement));
        }
        $procedure = oci_fetch_array($statement, OCI_ASSOC);
        $useProcedure = $procedure && $procedure['STATUS'] === 'VALID';
        if ($useProcedure) {
            $sql = "BEGIN finishStay_PRC(:stayId, TO_DATE(:leavingDate, 'YYYY-MM-DD')); END;";
        } else {
            // Existing Oracle triggers release the bed and equipment and retain assignment history.
            $sql = "UPDATE ICU_Stay
                    SET leavingDate = TO_DATE(:leavingDate, 'YYYY-MM-DD')
                    WHERE stayId = :stayId
                    AND leavingDate IS NULL";
        }
        $statement = oci_parse($oracleConnection, $sql);
        oci_bind_by_name($statement, ':stayId', $stayId, -1, SQLT_INT);
        oci_bind_by_name($statement, ':leavingDate', $leavingDate);
        if (!oci_execute($statement, OCI_NO_AUTO_COMMIT)) {
            throw new Exception(oracle_error($statement));
        }
        if (!$useProcedure && oci_num_rows($statement) !== 1) {
            throw new Exception('Stay was not ended. Reload the stay.');
        }
        if (!oci_commit($oracleConnection)) {
            throw new Exception('Save not confirmed. Reload the stay.');
        }
        $_SESSION['message'] = 'ICU stay ended.';
        header('Location: stays.php?stayId=' . $stayId, true, 303);
        exit;
    }
    if ($canOperate && isset($_GET['new'])) {
        foreach ($hospitals as $hospital) {
            if ((int) $hospital['HOSPITALID'] === $admissionHospitalId) {
                $admissionHospitalName = $hospital['HOSPITALNAME'];
                break;
            }
        }
        if ($admissionHospitalId && $admissionHospitalName === '') {
            throw new Exception('Select an existing hospital.');
        }
        if ($admissionHospitalId && ($search !== '' || $patientId)) {
            $sql = "SELECT patientId, Fname || ' ' || Mname || ' ' || Lname AS patientName, nationalId
                    FROM Patient
                    WHERE (:patientId = 0 OR patientId = :patientId)
                    AND (TO_CHAR(patientId) LIKE :search
                         OR LOWER(Fname || ' ' || Mname || ' ' || Lname) LIKE :search
                         OR nationalId LIKE :search)
                    ORDER BY patientId DESC FETCH FIRST 100 ROWS ONLY";
            $statement = oci_parse($oracleConnection, $sql);
            $searchValue = '%' . strtolower($search) . '%';
            oci_bind_by_name($statement, ':patientId', $patientId);
            oci_bind_by_name($statement, ':search', $searchValue);
            if (!oci_execute($statement, OCI_NO_AUTO_COMMIT)) {
                throw new Exception(oracle_error($statement));
            }
            while ($row = oci_fetch_array($statement, OCI_ASSOC)) {
                $patients[] = $row;
            }
        }
        if ($admissionHospitalId) {
            if ($mode === 'staff' && $admissionHospitalId === 554) {
                $sql = 'SELECT b.bedId, b.bedNumber, h.hospitalId, h.hospitalName
                    FROM hospitalBeds_view b
                    JOIN Hospital h ON h.hospitalId = b.hospitalId';
            } else {
                $sql = 'SELECT b.bedId, b.bedNumber, h.hospitalId, h.hospitalName
                    FROM ICU_Bed b
                    JOIN Hospital h ON h.hospitalId = b.hospitalId';
            }
            $sql .= "\n                WHERE b.hospitalId = :hospitalId AND b.status = 'Available'";
            $sql .= '
                    ORDER BY b.bedId';
            $statement = oci_parse($oracleConnection, $sql);
            oci_bind_by_name($statement, ':hospitalId', $admissionHospitalId, -1, SQLT_INT);
            if (!oci_execute($statement, OCI_NO_AUTO_COMMIT)) {
                throw new Exception(oracle_error($statement));
            }
            while ($row = oci_fetch_array($statement, OCI_ASSOC)) {
                $beds[] = $row;
            }
        }
    } elseif ($canOperate) {
        $sql = "SELECT s.stayId, s.patientId, s.bedId, b.bedNumber, h.hospitalId, h.hospitalName,
                       p.Fname || ' ' || p.Mname || ' ' || p.Lname AS patientName,
                       TO_CHAR(s.enteringDate, 'YYYY-MM-DD') AS enteringDate,
                       TO_CHAR(s.leavingDate, 'YYYY-MM-DD') AS leavingDate
                FROM ICU_Stay s
                JOIN Patient p ON p.patientId = s.patientId
                JOIN ICU_Bed b ON b.bedId = s.bedId
                JOIN Hospital h ON h.hospitalId = b.hospitalId
                WHERE (TO_CHAR(s.stayId) LIKE :search
                       OR LOWER(p.Fname || ' ' || p.Mname || ' ' || p.Lname) LIKE :search
                       OR p.nationalId LIKE :search
                       OR TO_CHAR(b.bedId) LIKE :search
                       OR LOWER(b.bedNumber) LIKE :search
                       OR TO_CHAR(h.hospitalId) LIKE :search
                       OR LOWER(h.hospitalName) LIKE :search)";
        if ($mode === 'staff') {
            $sql .= '
                AND h.hospitalId = :hospitalId';
        }
        if ($stayId) {
            $sql .= '
                AND s.stayId = :stayId';
        }
        if ($patientId) {
            $sql .= '
                AND s.patientId = :patientId';
        }
        if (!$stayId && $status === 'active') {
            $sql .= '
                AND s.leavingDate IS NULL';
        } elseif (!$stayId && $status === 'completed') {
            $sql .= '
                AND s.leavingDate IS NOT NULL';
        }
        $sql .= '
                ORDER BY s.stayId DESC
                OFFSET :offset ROWS FETCH NEXT 100 ROWS ONLY';
        $statement = oci_parse($oracleConnection, $sql);
        $searchValue = '%' . strtolower($search) . '%';
        oci_bind_by_name($statement, ':search', $searchValue);
        if ($mode === 'staff') {
            oci_bind_by_name($statement, ':hospitalId', $hospitalId, -1, SQLT_INT);
        }
        if ($stayId) {
            oci_bind_by_name($statement, ':stayId', $stayId, -1, SQLT_INT);
        }
        if ($patientId) {
            oci_bind_by_name($statement, ':patientId', $patientId, -1, SQLT_INT);
        }
        oci_bind_by_name($statement, ':offset', $offset, -1, SQLT_INT);
        if (!oci_execute($statement, OCI_NO_AUTO_COMMIT)) {
            throw new Exception(oracle_error($statement));
        }
        while ($row = oci_fetch_array($statement, OCI_ASSOC | OCI_RETURN_NULLS)) {
            $stays[] = $row;
        }
        if ($stayId) {
            $stay = $stays[0] ?? [];
            if (!$stay) {
                throw new Exception('Stay not found in this hospital.');
            }
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
<h1>ICU Stays</h1>
<?php if ($canOperate) { ?>
    <?php if (isset($_GET['new'])) { ?>
        <?php if ($mode === 'admin') { ?>
            <form method="get" class="filters">
                <input type="hidden" name="new" value="1">
                <label>
                    Admission Hospital
                    <select name="hospitalId" required>
                        <option value="">Select Hospital</option>
                        <?php foreach ($staffHospitals as $hospital) { ?>
                            <option value="<?php echo e($hospital['HOSPITALID']); ?>" <?php if ((int) $hospital['HOSPITALID'] === $admissionHospitalId) { echo 'selected'; } ?>>#<?php echo e($hospital['HOSPITALID']); ?> — <?php echo e($hospital['HOSPITALNAME']); ?></option>
                        <?php } ?>
                    </select>
                </label>
                <button>Load Available Beds</button>
            </form>
        <?php } ?>
        <?php if ($admissionHospitalName !== '') { ?>
            <p>Admission hospital: #<?php echo e($admissionHospitalId); ?> — <?php echo e($admissionHospitalName); ?></p>
            <form method="get" class="filters">
                <input type="hidden" name="new" value="1">
                <?php if ($mode === 'admin') { ?>
                    <input type="hidden" name="hospitalId" value="<?php echo e($admissionHospitalId); ?>">
                <?php } ?>
                <label>
                    Patient ID, name or national ID
                    <input name="q" value="<?php echo e($search); ?>">
                </label>
                <button>Search</button>
                <a href="stays.php?new=1&amp;hospitalId=<?php echo e($admissionHospitalId); ?>">Clear Search</a>
            </form>
            <section class="panel narrow">
                <h2>Create Stay</h2>
                <?php if (!$patients) { ?>
                    <p><?php echo $search === '' ? 'Search for a patient first.' : 'No patients found.'; ?></p>
                <?php } ?>
                <?php if (!$beds) { ?>
                    <p>No available beds.</p>
                <?php } else { ?>
                    <form method="post">
                        <?php csrf_fields(); ?>
                        <?php if ($mode === 'admin') { ?>
                            <input type="hidden" name="hospitalId" value="<?php echo e($admissionHospitalId); ?>">
                        <?php } ?>
                        <label>
                            Patient
                            <select name="patientId" required>
                                <option value="">Select Patient</option>
                                <?php foreach ($patients as $row) { ?>
                                    <option value="<?php echo e($row['PATIENTID']); ?>" <?php if ((int) $row['PATIENTID'] === $patientId) { echo 'selected'; } ?>>#<?php echo e($row['PATIENTID']); ?> — <?php echo e($row['PATIENTNAME']); ?> — <?php echo e($row['NATIONALID']); ?></option>
                                <?php } ?>
                            </select>
                        </label>
                        <label>
                            Available Bed
                            <select name="bedId" required>
                                <option value="">Select Bed</option>
                                <?php foreach ($beds as $row) { ?>
                                    <option value="<?php echo e($row['BEDID']); ?>">#<?php echo e($row['BEDID']); ?> — <?php echo e($row['BEDNUMBER']); ?> — <?php echo e($row['HOSPITALNAME']); ?></option>
                                <?php } ?>
                            </select>
                        </label>
                        <label>
                            Admission Date
                            <input type="date" name="enteringDate" value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>" required>
                        </label>
                        <button name="createStay">Create Stay</button>
                        <a class="button secondary" href="stays.php">Cancel</a>
                    </form>
                <?php } ?>
            </section>
        <?php } else { ?>
            <p>Select a hospital to load its available beds.</p>
        <?php } ?>
    <?php } elseif ($stay) { ?>
        <section class="panel narrow">
            <h2>Stay #<?php echo e($stayId); ?></h2>
            <dl>
                <dt>Patient</dt>
                <dd><a href="patients.php?patientId=<?php echo e($stay['PATIENTID']); ?>">#<?php echo e($stay['PATIENTID']); ?> — <?php echo e($stay['PATIENTNAME']); ?></a></dd>
                <dt>Hospital</dt>
                <dd>#<?php echo e($stay['HOSPITALID']); ?> — <?php echo e($stay['HOSPITALNAME']); ?></dd>
                <dt>Bed</dt>
                <dd>#<?php echo e($stay['BEDID']); ?> — <?php echo e($stay['BEDNUMBER']); ?></dd>
                <dt>Admission</dt>
                <dd><?php echo e($stay['ENTERINGDATE']); ?></dd>
                <dt>Leaving Date</dt>
                <dd><?php echo e($stay['LEAVINGDATE'] ?? 'Active'); ?></dd>
            </dl>
            <div class="links">
                <a href="medical.php?stayId=<?php echo e($stayId); ?>">Medical Information</a>
                <a href="equipment.php?stayId=<?php echo e($stayId); ?>">Equipment</a>
                <a href="billing.php?stayId=<?php echo e($stayId); ?>">Daily Charges</a>
                <a href="transfers.php?stayId=<?php echo e($stayId); ?>">Find Destination</a>
            </div>
        </section>
        <?php if ($stay['LEAVINGDATE'] === null) { ?>
            <section class="panel narrow">
                <h2>End Stay</h2>
                <form method="post" data-confirm="End this stay and release its bed and equipment?">
                    <?php csrf_fields(); ?>
                    <input type="hidden" name="stayId" value="<?php echo e($stayId); ?>">
                    <label>
                        Leaving Date
                        <input type="date" name="leavingDate" value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>" required>
                    </label>
                    <button name="endStay" class="danger">End Stay</button>
                </form>
            </section>
        <?php } ?>
    <?php } elseif (!$stayId) { ?>
        <form method="get" class="filters">
            <input type="hidden" name="patientId" value="<?php echo e($patientId); ?>">
            <label>
                Stay ID, patient, national ID, bed or hospital
                <input name="q" value="<?php echo e($search); ?>">
            </label>
            <label>
                Status
                <select name="status">
                    <option value="all">All</option>
                    <option value="active" <?php if ($status === 'active') { echo 'selected'; } ?>>Active</option>
                    <option value="completed" <?php if ($status === 'completed') { echo 'selected'; } ?>>Completed</option>
                </select>
            </label>
            <button>Search</button>
            <a href="stays.php">Clear Search</a>
            <a class="button secondary" href="stays.php?new=1">Create Stay</a>
        </form>
        <section class="panel">
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Stay</th>
                            <th>Patient</th>
                            <th>Hospital</th>
                            <th>Bed</th>
                            <th>Admission</th>
                            <th>Leaving Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stays as $row) { ?>
                            <tr>
                                <td><a href="stays.php?stayId=<?php echo e($row['STAYID']); ?>">#<?php echo e($row['STAYID']); ?></a></td>
                                <td>#<?php echo e($row['PATIENTID']); ?> — <?php echo e($row['PATIENTNAME']); ?></td>
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
                <a href="stays.php?q=<?php echo urlencode($search); ?>&amp;offset=<?php echo max(0, $offset - 100); ?>&amp;status=<?php echo urlencode($status); ?>&amp;patientId=<?php echo $patientId; ?>">Previous</a>
            <?php } ?>
            <?php if (count($stays) === 100) { ?>
                <a href="stays.php?q=<?php echo urlencode($search); ?>&amp;offset=<?php echo $offset + 100; ?>&amp;status=<?php echo urlencode($status); ?>&amp;patientId=<?php echo $patientId; ?>">Next</a>
            <?php } ?>
        </div>
    <?php } ?>
<?php } ?>
<?php require 'includes/footer.php'; ?>
