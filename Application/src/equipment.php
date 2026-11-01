<?php
require 'includes/connect.php';
$title = 'Equipment';
$stayId = 0;
$equipmentId = 0;
$stay = [];
$equipment = [];
$available = [];
$assignments = [];
$history = [];
$search = '';
$offset = 0;
$view = '';
$currentAssignments = [];

try {
    $stayId = id_value($_GET, 'stayId');
    $search = text_value($_GET, 'q', 100, false);
    $offset = id_value($_GET, 'offset');
    $equipmentId = id_value($_GET, 'equipmentId');
    $view = text_value($_GET, 'view', 10, false);
    if (isset($_POST['assignEquipment'])) {
        check_post(true);
        $stayId = id_value($_POST, 'stayId');
        $equipmentId = id_value($_POST, 'equipmentId');
    }
    if ($stayId || $equipmentId || $view !== 'current') {
        $view = '';
    }
    if ($canOperate && $stayId) {
        $sql = "SELECT s.stayId, s.patientId, s.leavingDate, h.hospitalId, h.hospitalName,
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
        if (isset($_POST['assignEquipment'])) {
            $sql .= ' FOR UPDATE OF s.stayId WAIT 5';
        }
        $statement = oci_parse($oracleConnection, $sql);
        oci_bind_by_name($statement, ':stayId', $stayId);
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
        $stayHospitalId = (int) $stay['HOSPITALID'];
        if (isset($_POST['assignEquipment'])) {
            if ($stay['LEAVINGDATE'] !== null) {
                throw new Exception('This stay has ended.');
            }
            $sql = 'SELECT hospitalId, status
                FROM Equipment
                WHERE equipmentId = :equipmentId
                FOR UPDATE WAIT 5';
            $statement = oci_parse($oracleConnection, $sql);
            oci_bind_by_name($statement, ':equipmentId', $equipmentId);
            if (!oci_execute($statement, OCI_NO_AUTO_COMMIT)) {
                throw new Exception(oracle_error($statement));
            }
            $row = oci_fetch_array($statement, OCI_ASSOC);
            if (!$row || (int) $row['HOSPITALID'] !== $stayHospitalId) {
                throw new Exception('This equipment belongs to another hospital.');
            }
            if ($row['STATUS'] !== 'Available') {
                throw new Exception('This equipment is in use.');
            }
            $sql = 'SELECT es.stayId
                FROM Equipment_Stay es
                JOIN ICU_Stay s ON s.stayId = es.stayId
                WHERE es.equipmentId = :equipmentId AND s.leavingDate IS NULL';
            $statement = oci_parse($oracleConnection, $sql);
            oci_bind_by_name($statement, ':equipmentId', $equipmentId);
            if (!oci_execute($statement, OCI_NO_AUTO_COMMIT)) {
                throw new Exception(oracle_error($statement));
            }
            if (oci_fetch_array($statement, OCI_ASSOC)) {
                throw new Exception('This equipment already has an active assignment.');
            }
            $sql = 'INSERT INTO Equipment_Stay (stayId, equipmentId) VALUES (:stayId, :equipmentId)';
            $statement = oci_parse($oracleConnection, $sql);
            oci_bind_by_name($statement, ':stayId', $stayId);
            oci_bind_by_name($statement, ':equipmentId', $equipmentId);
            if (!oci_execute($statement, OCI_NO_AUTO_COMMIT)) {
                throw new Exception(oracle_error($statement));
            }
            if (!oci_commit($oracleConnection)) {
                throw new Exception('Save not confirmed. Reload the assignments.');
            }
            $_SESSION['message'] = 'Equipment assigned.';
            header('Location: equipment.php?stayId=' . $stayId, true, 303);
            exit;
        }
        $sql = 'SELECT es.equipmentId, es.stayId, e.equipmentName, e.status,
                       b.bedId, b.bedNumber, h.hospitalId, h.hospitalName
                FROM Equipment_Stay es
                JOIN Equipment e ON e.equipmentId = es.equipmentId
                JOIN ICU_Stay s ON s.stayId = es.stayId
                JOIN ICU_Bed b ON b.bedId = s.bedId
                JOIN Hospital h ON h.hospitalId = b.hospitalId
                WHERE es.stayId = :stayId
                ORDER BY e.equipmentId';
        $statement = oci_parse($oracleConnection, $sql);
        oci_bind_by_name($statement, ':stayId', $stayId);
        if (!oci_execute($statement, OCI_NO_AUTO_COMMIT)) {
            throw new Exception(oracle_error($statement));
        }
        while ($row = oci_fetch_array($statement, OCI_ASSOC | OCI_RETURN_NULLS)) {
            $assignments[] = $row;
        }
        if ($stay['LEAVINGDATE'] === null) {
            $sql = "SELECT e.*, h.hospitalName
                FROM Equipment e
                JOIN Hospital h ON h.hospitalId = e.hospitalId
                WHERE e.hospitalId = :hospitalId AND e.status = 'Available'
                ORDER BY e.equipmentId";
            $statement = oci_parse($oracleConnection, $sql);
            oci_bind_by_name($statement, ':hospitalId', $stayHospitalId);
            if (!oci_execute($statement, OCI_NO_AUTO_COMMIT)) {
                throw new Exception(oracle_error($statement));
            }
            while ($row = oci_fetch_array($statement, OCI_ASSOC | OCI_RETURN_NULLS)) {
                $available[] = $row;
            }
        }
    } elseif ($canOperate && $view === 'current') {
        $sql = "SELECT es.equipmentId, es.stayId, e.equipmentName, e.status,
                       b.bedId, b.bedNumber, h.hospitalId, h.hospitalName
                FROM Equipment_Stay es
                JOIN Equipment e ON e.equipmentId = es.equipmentId
                JOIN ICU_Stay s ON s.stayId = es.stayId
                JOIN ICU_Bed b ON b.bedId = s.bedId
                JOIN Hospital h ON h.hospitalId = b.hospitalId
                WHERE e.status = 'In use' AND s.leavingDate IS NULL
                AND (TO_CHAR(es.equipmentId) LIKE :search
                     OR LOWER(e.equipmentName) LIKE :search
                     OR TO_CHAR(es.stayId) LIKE :search
                     OR TO_CHAR(b.bedId) LIKE :search
                     OR LOWER(b.bedNumber) LIKE :search
                     OR TO_CHAR(h.hospitalId) LIKE :search
                     OR LOWER(h.hospitalName) LIKE :search)";
        if ($mode === 'staff') {
            $sql .= '
                AND h.hospitalId = :hospitalId';
        }
        $sql .= '
                ORDER BY es.equipmentId, es.stayId
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
        while ($row = oci_fetch_array($statement, OCI_ASSOC)) {
            $currentAssignments[] = $row;
        }
    } elseif ($canOperate) {
        $sql = 'SELECT e.*, h.hospitalName
                FROM Equipment e
                JOIN Hospital h ON h.hospitalId = e.hospitalId
                WHERE (TO_CHAR(e.equipmentId) LIKE :search
                       OR LOWER(e.equipmentName) LIKE :search
                       OR TO_CHAR(h.hospitalId) LIKE :search
                       OR LOWER(h.hospitalName) LIKE :search)';
        if ($mode === 'staff') {
            $sql .= '
                AND e.hospitalId = :hospitalId';
        }
        if ($equipmentId) {
            $sql .= '
                AND e.equipmentId = :equipmentId';
            $offset = 0;
        }
        $sql .= '
                ORDER BY e.equipmentId
                OFFSET :offset ROWS FETCH NEXT 100 ROWS ONLY';
        $statement = oci_parse($oracleConnection, $sql);
        $searchValue = '%' . strtolower($search) . '%';
        oci_bind_by_name($statement, ':search', $searchValue);
        oci_bind_by_name($statement, ':offset', $offset, -1, SQLT_INT);
        if ($mode === 'staff') {
            oci_bind_by_name($statement, ':hospitalId', $hospitalId, -1, SQLT_INT);
        }
        if ($equipmentId) {
            oci_bind_by_name($statement, ':equipmentId', $equipmentId, -1, SQLT_INT);
        }
        if (!oci_execute($statement, OCI_NO_AUTO_COMMIT)) {
            throw new Exception(oracle_error($statement));
        }
        while ($row = oci_fetch_array($statement, OCI_ASSOC | OCI_RETURN_NULLS)) {
            $equipment[] = $row;
        }
        if ($equipmentId && !$equipment) {
            throw new Exception('Equipment not found in this hospital.');
        }
        if ($equipmentId) {
            $sql = "SELECT s.stayId, b.bedId, b.bedNumber, h.hospitalId, h.hospitalName,
                           TO_CHAR(s.enteringDate, 'YYYY-MM-DD') AS enteringDate,
                           TO_CHAR(s.leavingDate, 'YYYY-MM-DD') AS leavingDate
                FROM Equipment_Stay es
                JOIN Equipment e ON e.equipmentId = es.equipmentId
                JOIN ICU_Stay s ON s.stayId = es.stayId
                JOIN ICU_Bed b ON b.bedId = s.bedId
                JOIN Hospital h ON h.hospitalId = b.hospitalId
                WHERE es.equipmentId = :equipmentId";
            if ($mode === 'staff') {
                $sql .= '
                    AND b.hospitalId = :hospitalId';
            }
            $sql .= '
                    ORDER BY s.stayId DESC';
            $statement = oci_parse($oracleConnection, $sql);
            oci_bind_by_name($statement, ':equipmentId', $equipmentId);
            if ($mode === 'staff') {
                oci_bind_by_name($statement, ':hospitalId', $hospitalId, -1, SQLT_INT);
            }
            if (!oci_execute($statement, OCI_NO_AUTO_COMMIT)) {
                throw new Exception(oracle_error($statement));
            }
            while ($row = oci_fetch_array($statement, OCI_ASSOC | OCI_RETURN_NULLS)) {
                $history[] = $row;
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
<h1>Equipment</h1>
<?php if ($canOperate) { ?>
    <nav class="tabs">
        <a href="equipment.php">All Equipment</a>
        <a href="equipment.php?view=current">Current Assignments</a>
    </nav>
    <form method="get" class="filters">
        <label>
            Stay ID
            <input type="number" min="1" name="stayId" value="<?php echo $stayId ? e($stayId) : ''; ?>" required>
        </label>
        <button>Open Stay</button>
        <a href="stays.php?status=active">ICU Stays</a>
    </form>
    <form method="get" class="filters">
        <input type="hidden" name="view" value="<?php echo e($view); ?>">
        <label>
            <?php echo $view === 'current' ? 'Equipment, stay, bed or hospital' : 'Equipment ID, name or hospital'; ?>
            <input name="q" value="<?php echo e($search); ?>">
        </label>
        <button>Search Equipment</button>
        <a href="equipment.php?view=<?php echo e($view); ?>">Clear Search</a>
    </form>
    <?php if ($view === 'current') { ?>
        <section class="panel">
            <h2>Current Equipment Assignments</h2>
            <div class="table-wrap">
                <table id="current-assignments">
                    <thead>
                        <tr>
                            <th>Equipment ID</th>
                            <th>Equipment</th>
                            <th>Status</th>
                            <th>Stay</th>
                            <th>Bed</th>
                            <th>Hospital</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($currentAssignments as $row) { ?>
                            <tr>
                                <td><a href="equipment.php?equipmentId=<?php echo e($row['EQUIPMENTID']); ?>">#<?php echo e($row['EQUIPMENTID']); ?></a></td>
                                <td><?php echo e($row['EQUIPMENTNAME']); ?></td>
                                <td><?php echo e($row['STATUS']); ?></td>
                                <td><a href="stays.php?stayId=<?php echo e($row['STAYID']); ?>">#<?php echo e($row['STAYID']); ?></a></td>
                                <td>#<?php echo e($row['BEDID']); ?> — <?php echo e($row['BEDNUMBER']); ?></td>
                                <td>#<?php echo e($row['HOSPITALID']); ?> — <?php echo e($row['HOSPITALNAME']); ?></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
            <?php if (!$currentAssignments) { ?>
                <p>No current assignments found.</p>
            <?php } ?>
        </section>
        <div class="links">
            <?php if ($offset > 0) { ?>
                <a href="equipment.php?view=current&amp;q=<?php echo urlencode($search); ?>&amp;offset=<?php echo max(0, $offset - 100); ?>">Previous</a>
            <?php } ?>
            <?php if (count($currentAssignments) === 100) { ?>
                <a href="equipment.php?view=current&amp;q=<?php echo urlencode($search); ?>&amp;offset=<?php echo $offset + 100; ?>">Next</a>
            <?php } ?>
        </div>
    <?php } elseif ($stay) { ?>
        <section class="context">
            <strong>Stay #<?php echo e($stayId); ?></strong>
            <span>Patient #<?php echo e($stay['PATIENTID']); ?> — <?php echo e($stay['PATIENTNAME']); ?></span>
            <span>Hospital #<?php echo e($stay['HOSPITALID']); ?> — <?php echo e($stay['HOSPITALNAME']); ?></span>
        </section>
        <section class="panel">
            <h2>Assigned Equipment</h2>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Equipment</th>
                            <th>Stay</th>
                            <th>Bed</th>
                            <th>Hospital</th>
                            <th>Current Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($assignments as $row) { ?>
                            <tr>
                                <td>#<?php echo e($row['EQUIPMENTID']); ?></td>
                                <td><?php echo e($row['EQUIPMENTNAME']); ?></td>
                                <td>#<?php echo e($row['STAYID']); ?></td>
                                <td>#<?php echo e($row['BEDID']); ?> — <?php echo e($row['BEDNUMBER']); ?></td>
                                <td>#<?php echo e($row['HOSPITALID']); ?> — <?php echo e($row['HOSPITALNAME']); ?></td>
                                <td><?php echo e($row['STATUS']); ?></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
            <?php if (!$assignments) { ?>
                <p>No equipment assigned.</p>
            <?php } ?>
        </section>
        <?php if ($stay['LEAVINGDATE'] === null) { ?>
            <section class="panel narrow">
                <h2>Assign Equipment</h2>
                <?php if (!$available) { ?>
                    <p>No available equipment.</p>
                <?php } else { ?>
                    <form method="post">
                        <?php csrf_fields(); ?>
                        <input type="hidden" name="stayId" value="<?php echo e($stayId); ?>">
                        <label>
                            Available Equipment
                            <select name="equipmentId" required>
                                <option value="">Select Equipment</option>
                                <?php foreach ($available as $row) { ?>
                                    <option value="<?php echo e($row['EQUIPMENTID']); ?>">#<?php echo e($row['EQUIPMENTID']); ?> — <?php echo e($row['EQUIPMENTNAME']); ?><?php if (isset($row['EQUIPMENTTYPE'])) { echo ' — ' . e($row['EQUIPMENTTYPE']); } ?> — <?php echo e($row['HOSPITALNAME']); ?> (#<?php echo e($row['HOSPITALID']); ?>)</option>
                                <?php } ?>
                            </select>
                        </label>
                        <button name="assignEquipment">Assign Equipment</button>
                    </form>
                <?php } ?>
            </section>
        <?php } else { ?>
            <p>This stay has ended. Assignments are kept in its history.</p>
        <?php } ?>
    <?php } elseif (!$stayId) { ?>
        <section class="panel">
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Equipment</th>
                            <th>Hospital</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($equipment as $row) { ?>
                            <tr>
                                <td>#<?php echo e($row['EQUIPMENTID']); ?></td>
                                <td><?php echo e($row['EQUIPMENTNAME']); ?><?php if (isset($row['EQUIPMENTTYPE'])) { ?><small><?php echo e($row['EQUIPMENTTYPE']); ?></small><?php } ?></td>
                                <td>#<?php echo e($row['HOSPITALID']); ?> — <?php echo e($row['HOSPITALNAME']); ?></td>
                                <td><?php echo e($row['STATUS']); ?></td>
                                <td><a href="equipment.php?equipmentId=<?php echo e($row['EQUIPMENTID']); ?>">History</a></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
            <?php if (!$equipment) { ?>
                <p>No equipment found.</p>
            <?php } ?>
        </section>
        <?php if (!$equipmentId) { ?>
            <div class="links">
                <?php if ($offset > 0) { ?>
                    <a href="equipment.php?q=<?php echo urlencode($search); ?>&amp;offset=<?php echo max(0, $offset - 100); ?>">Previous</a>
                <?php } ?>
                <?php if (count($equipment) === 100) { ?>
                    <a href="equipment.php?q=<?php echo urlencode($search); ?>&amp;offset=<?php echo $offset + 100; ?>">Next</a>
                <?php } ?>
            </div>
        <?php } ?>
        <?php if ($equipmentId && $equipment) { ?>
            <section class="panel">
                <h2>Assignment History</h2>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Stay</th>
                                <th>Bed</th>
                                <th>Hospital</th>
                                <th>Admission</th>
                                <th>Leaving Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($history as $row) { ?>
                                <tr>
                                    <td><a href="equipment.php?stayId=<?php echo e($row['STAYID']); ?>">#<?php echo e($row['STAYID']); ?></a></td>
                                    <td>#<?php echo e($row['BEDID']); ?> — <?php echo e($row['BEDNUMBER']); ?></td>
                                    <td>#<?php echo e($row['HOSPITALID']); ?> — <?php echo e($row['HOSPITALNAME']); ?></td>
                                    <td><?php echo e($row['ENTERINGDATE']); ?></td>
                                    <td><?php echo e($row['LEAVINGDATE'] ?? 'Active'); ?></td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
                <?php if (!$history) { ?>
                    <p>No assignments.</p>
                <?php } ?>
            </section>
        <?php } ?>
    <?php } ?>
<?php } ?>
<?php require 'includes/footer.php'; ?>
