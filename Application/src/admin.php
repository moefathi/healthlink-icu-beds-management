<?php
require 'includes/connect.php';
$title = 'Admin';
$section = 'hospitals';
$adminHospitalId = 0;
$editId = 0;
$record = [];
$rows = [];
$hasEquipmentType = false;
$hasChargesView = false;
$chargeSummary = [];
$chargeView = [];
$search = '';
$offset = 0;

try {
    $section = text_value($_GET, 'section', 10, false);
    if (!in_array($section, ['hospitals', 'beds', 'equipment', 'capacity', 'charges', 'routes'], true)) {
        $section = 'hospitals';
    }
    $adminHospitalId = id_value($_GET, 'hospitalId');
    $editId = id_value($_GET, 'editId');
    $search = text_value($_GET, 'q', 100, false);
    $offset = id_value($_GET, 'offset');
    if ($mode !== 'admin') {
        throw new Exception('Switch to Admin mode.');
    }
    if (!$oracleConnection) {
        throw new Exception($connectionError);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_post();
    }
    if ($section === 'equipment' || isset($_POST['saveEquipment'])) {
        $sql = "SELECT column_name
                FROM user_tab_columns
                WHERE table_name = 'EQUIPMENT' AND column_name = 'EQUIPMENTTYPE'";
        $statement = oci_parse($oracleConnection, $sql);
        if (!oci_execute($statement, OCI_NO_AUTO_COMMIT)) {
            throw new Exception(oracle_error($statement));
        }
        $hasEquipmentType = (bool) oci_fetch_array($statement, OCI_ASSOC);
    }
    if (isset($_POST['saveHospital'])) {
        $editId = id_value($_POST, 'editId');
        $name = text_value($_POST, 'hospitalName', 100);
        $country = text_value($_POST, 'country', 50);
        $city = text_value($_POST, 'city', 50);
        $street = text_value($_POST, 'street', 150);
        $phone = text_value($_POST, 'phone', 10);
        $email = text_value($_POST, 'email', 100);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Enter a valid email.');
        }
        if ($editId) {
            $sql = 'SELECT country, city
                FROM Hospital
                WHERE hospitalId = :hospitalId
                FOR UPDATE WAIT 5';
            $statement = oci_parse($oracleConnection, $sql);
            oci_bind_by_name($statement, ':hospitalId', $editId);
            if (!oci_execute($statement, OCI_NO_AUTO_COMMIT)) {
                throw new Exception(oracle_error($statement));
            }
            $oldHospital = oci_fetch_array($statement, OCI_ASSOC);
            if (!$oldHospital) {
                throw new Exception('Hospital not found.');
            }
            $sql = 'UPDATE Hospital SET hospitalName = :name, country = :country, city = :city,
                    street = :street, contactNumber = :phone, email = :email
                WHERE hospitalId = :hospitalId';
        } else {
            $sql = 'INSERT INTO Hospital (hospitalName, country, city, street, contactNumber, email)
                    VALUES (:name, :country, :city, :street, :phone, :email)
                RETURNING hospitalId INTO :hospitalId';
        }
        $statement = oci_parse($oracleConnection, $sql);
        oci_bind_by_name($statement, ':name', $name);
        oci_bind_by_name($statement, ':country', $country);
        oci_bind_by_name($statement, ':city', $city);
        oci_bind_by_name($statement, ':street', $street);
        oci_bind_by_name($statement, ':phone', $phone);
        oci_bind_by_name($statement, ':email', $email);
        oci_bind_by_name($statement, ':hospitalId', $editId, -1, SQLT_INT);
        if (!oci_execute($statement, OCI_NO_AUTO_COMMIT)) {
            throw new Exception(oracle_error($statement));
        }
        if (!oci_commit($oracleConnection)) {
            throw new Exception('Hospital save not confirmed. Reload the list.');
        }
        $_SESSION['message'] = 'Hospital saved.';
        try {
            neo4j_query('MERGE (h:Hospital {hospitalId: $id})
                         SET h.hospitalName = $name',
                         ['id' => (int) $editId, 'name' => $name]);
        } catch (Exception $exception) {
            $_SESSION['message'] = 'Hospital saved in Oracle. Neo4j update was not confirmed.';
        }
        header('Location: admin.php', true, 303);
        exit;
    }
    if (isset($_POST['saveBed']) || isset($_POST['deleteBed'])) {
        $editId = id_value($_POST, 'editId');
        $newHospitalId = id_value($_POST, 'hospitalId');
        $number = text_value($_POST, 'bedNumber', 20);
        if ($editId) {
            $sql = 'SELECT hospitalId, status
                FROM ICU_Bed
                WHERE bedId = :bedId
                FOR UPDATE WAIT 5';
            $statement = oci_parse($oracleConnection, $sql);
            oci_bind_by_name($statement, ':bedId', $editId);
            if (!oci_execute($statement, OCI_NO_AUTO_COMMIT)) {
                throw new Exception(oracle_error($statement));
            }
            $oldBed = oci_fetch_array($statement, OCI_ASSOC);
            if (!$oldBed) {
                throw new Exception('Bed not found.');
            }
            $sql = 'SELECT stayId
                FROM ICU_Stay
                WHERE bedId = :bedId';
            $statement = oci_parse($oracleConnection, $sql);
            oci_bind_by_name($statement, ':bedId', $editId);
            if (!oci_execute($statement, OCI_NO_AUTO_COMMIT)) {
                throw new Exception(oracle_error($statement));
            }
            $hasHistory = (bool) oci_fetch_array($statement, OCI_ASSOC);
            if ($hasHistory && ($newHospitalId !== (int) $oldBed['HOSPITALID'] || isset($_POST['deleteBed']))) {
                throw new Exception('A bed with history cannot move or be deleted.');
            }
            if (isset($_POST['deleteBed']) && $oldBed['STATUS'] !== 'Available') {
                throw new Exception('This bed is in use.');
            }
        }
        if (isset($_POST['deleteBed'])) {
            $sql = 'DELETE
                FROM ICU_Bed
                WHERE bedId = :bedId';
        } elseif ($editId) {
            $sql = 'UPDATE ICU_Bed SET bedNumber = :bedNumber, hospitalId = :hospitalId
                WHERE bedId = :bedId';
        } else {
            $sql = 'INSERT INTO ICU_Bed (bedNumber, hospitalId) VALUES (:bedNumber, :hospitalId)
                RETURNING bedId INTO :bedId';
        }
        $statement = oci_parse($oracleConnection, $sql);
        oci_bind_by_name($statement, ':bedId', $editId, -1, SQLT_INT);
        if (!isset($_POST['deleteBed'])) {
            oci_bind_by_name($statement, ':bedNumber', $number);
            oci_bind_by_name($statement, ':hospitalId', $newHospitalId);
        }
        if (!oci_execute($statement, OCI_NO_AUTO_COMMIT)) {
            throw new Exception(oracle_error($statement));
        }
        if (!oci_commit($oracleConnection)) {
            throw new Exception('Bed save not confirmed. Reload the list.');
        }
        $_SESSION['message'] = 'Bed saved.';
        header('Location: admin.php?section=beds&hospitalId=' . $newHospitalId, true, 303);
        exit;
    }
    if (isset($_POST['saveEquipment']) || isset($_POST['deleteEquipment'])) {
        $editId = id_value($_POST, 'editId');
        $newHospitalId = id_value($_POST, 'hospitalId');
        $name = text_value($_POST, 'equipmentName', 100);
        $type = text_value($_POST, 'equipmentType', 100, $hasEquipmentType);
        if ($editId) {
            $sql = 'SELECT hospitalId, status
                FROM Equipment
                WHERE equipmentId = :equipmentId
                FOR UPDATE WAIT 5';
            $statement = oci_parse($oracleConnection, $sql);
            oci_bind_by_name($statement, ':equipmentId', $editId);
            if (!oci_execute($statement, OCI_NO_AUTO_COMMIT)) {
                throw new Exception(oracle_error($statement));
            }
            $oldEquipment = oci_fetch_array($statement, OCI_ASSOC);
            if (!$oldEquipment) {
                throw new Exception('Equipment not found.');
            }
            $sql = 'SELECT stayId
                FROM Equipment_Stay
                WHERE equipmentId = :equipmentId';
            $statement = oci_parse($oracleConnection, $sql);
            oci_bind_by_name($statement, ':equipmentId', $editId);
            if (!oci_execute($statement, OCI_NO_AUTO_COMMIT)) {
                throw new Exception(oracle_error($statement));
            }
            $hasHistory = (bool) oci_fetch_array($statement, OCI_ASSOC);
            if ($hasHistory && ($newHospitalId !== (int) $oldEquipment['HOSPITALID'] || isset($_POST['deleteEquipment']))) {
                throw new Exception('Equipment with history cannot move or be deleted.');
            }
            if (isset($_POST['deleteEquipment']) && $oldEquipment['STATUS'] !== 'Available') {
                throw new Exception('This equipment is in use.');
            }
        }
        if (isset($_POST['deleteEquipment'])) {
            $sql = 'DELETE
                FROM Equipment
                WHERE equipmentId = :equipmentId';
        } elseif ($editId) {
            $sql = 'UPDATE Equipment SET equipmentName = :name, hospitalId = :hospitalId';
            if ($hasEquipmentType) {
                $sql .= ', equipmentType = :type';
            }
            $sql .= ' WHERE equipmentId = :equipmentId';
        } elseif ($hasEquipmentType) {
            $sql = "INSERT INTO Equipment (equipmentName, hospitalId, status, equipmentType)
                    VALUES (:name, :hospitalId, 'Available', :type)
                RETURNING equipmentId INTO :equipmentId";
        } else {
            $sql = "INSERT INTO Equipment (equipmentName, hospitalId, status)
                    VALUES (:name, :hospitalId, 'Available')
                RETURNING equipmentId INTO :equipmentId";
        }
        $statement = oci_parse($oracleConnection, $sql);
        oci_bind_by_name($statement, ':equipmentId', $editId, -1, SQLT_INT);
        if (!isset($_POST['deleteEquipment'])) {
            oci_bind_by_name($statement, ':name', $name);
            oci_bind_by_name($statement, ':hospitalId', $newHospitalId);
            if ($hasEquipmentType) {
                oci_bind_by_name($statement, ':type', $type);
            }
        }
        if (!oci_execute($statement, OCI_NO_AUTO_COMMIT)) {
            throw new Exception(oracle_error($statement));
        }
        if (!oci_commit($oracleConnection)) {
            throw new Exception('Equipment save not confirmed. Reload the list.');
        }
        $_SESSION['message'] = 'Equipment saved.';
        header('Location: admin.php?section=equipment&hospitalId=' . $newHospitalId, true, 303);
        exit;
    }
    if (isset($_POST['saveRoute']) || isset($_POST['removeRoute'])) {
        $sourceId = id_value($_POST, 'sourceId');
        $destinationId = id_value($_POST, 'destinationId');
        $byId = array_column($hospitals, null, 'HOSPITALID');
        if ($sourceId === $destinationId || !isset($byId[$sourceId], $byId[$destinationId])) {
            throw new Exception('Select two different hospitals.');
        }
        if (isset($_POST['saveRoute'])) {
            $query = 'MATCH (a:Hospital {hospitalId: $sourceId}), (b:Hospital {hospitalId: $destinationId})
                      MERGE (a)-[r:ROUTE]-(b) RETURN count(r) AS total';
        } else {
            $query = 'MATCH (a:Hospital {hospitalId: $sourceId})-[r:ROUTE]-(b:Hospital {hospitalId: $destinationId})
                      DELETE r RETURN count(r) AS total';
        }
        $result = neo4j_query($query, ['sourceId' => $sourceId, 'destinationId' => $destinationId]);
        if (!$result || !$result[0]['total']) {
            throw new Exception('Route or hospital nodes not found.');
        }
        if (isset($_POST['removeRoute'])) {
            $_SESSION['message'] = 'Route removed.';
        } else {
            $_SESSION['message'] = 'Route saved.';
        }
        header('Location: admin.php?section=routes&hospitalId=' . $sourceId, true, 303);
        exit;
    }
    if ($section === 'hospitals') {
        $sql = 'SELECT *
                FROM Hospital
                WHERE (TO_CHAR(hospitalId) LIKE :search
                       OR LOWER(hospitalName) LIKE :search
                       OR LOWER(city) LIKE :search)';
        if ($editId) {
            $sql .= '
                AND hospitalId = :editId';
            $offset = 0;
        }
        $sql .= '
                ORDER BY hospitalId
                OFFSET :offset ROWS FETCH NEXT 100 ROWS ONLY';
        $statement = oci_parse($oracleConnection, $sql);
        $searchValue = '%' . strtolower($search) . '%';
        oci_bind_by_name($statement, ':search', $searchValue);
        oci_bind_by_name($statement, ':offset', $offset, -1, SQLT_INT);
        if ($editId) {
            oci_bind_by_name($statement, ':editId', $editId, -1, SQLT_INT);
        }
        if (!oci_execute($statement, OCI_NO_AUTO_COMMIT)) {
            throw new Exception(oracle_error($statement));
        }
        while ($row = oci_fetch_array($statement, OCI_ASSOC | OCI_RETURN_NULLS)) {
            $rows[] = $row;
        }
        if ($editId) {
            $record = $rows[0] ?? [];
        }
    } elseif ($section === 'beds' || $section === 'equipment') {
        if ($section === 'beds') {
            if ($adminHospitalId === 554) {
                $sql = 'SELECT b.*, h.hospitalName
                FROM hospitalBeds_view b
                JOIN Hospital h ON h.hospitalId = b.hospitalId';
            } else {
                $sql = 'SELECT b.*, h.hospitalName
                FROM ICU_Bed b
                JOIN Hospital h ON h.hospitalId = b.hospitalId';
            }
            $sql .= '
                WHERE (TO_CHAR(b.bedId) LIKE :search
                       OR LOWER(b.bedNumber) LIKE :search
                       OR TO_CHAR(h.hospitalId) LIKE :search
                       OR LOWER(h.hospitalName) LIKE :search)';
            if ($editId) {
                $sql .= '
                AND b.bedId = :editId';
            }
        } else {
            $sql = 'SELECT e.*, h.hospitalName
                FROM Equipment e
                JOIN Hospital h ON h.hospitalId = e.hospitalId
                WHERE (TO_CHAR(e.equipmentId) LIKE :search
                           OR LOWER(e.equipmentName) LIKE :search
                           OR TO_CHAR(h.hospitalId) LIKE :search
                           OR LOWER(h.hospitalName) LIKE :search)';
            if ($editId) {
                $sql .= '
                AND e.equipmentId = :editId';
            }
        }
        if ($adminHospitalId) {
            $sql .= '
                AND h.hospitalId = :hospitalId';
        }
        if ($section === 'beds') {
            $sql .= '
                ORDER BY b.bedId';
        } else {
            $sql .= '
                ORDER BY e.equipmentId';
        }
        if ($editId) {
            $offset = 0;
        }
        $sql .= '
                OFFSET :offset ROWS FETCH NEXT 100 ROWS ONLY';
        $statement = oci_parse($oracleConnection, $sql);
        $searchValue = '%' . strtolower($search) . '%';
        oci_bind_by_name($statement, ':search', $searchValue);
        oci_bind_by_name($statement, ':offset', $offset, -1, SQLT_INT);
        if ($adminHospitalId) {
            oci_bind_by_name($statement, ':hospitalId', $adminHospitalId, -1, SQLT_INT);
        }
        if ($editId) {
            oci_bind_by_name($statement, ':editId', $editId, -1, SQLT_INT);
        }
        if (!oci_execute($statement, OCI_NO_AUTO_COMMIT)) {
            throw new Exception(oracle_error($statement));
        }
        while ($row = oci_fetch_array($statement, OCI_ASSOC | OCI_RETURN_NULLS)) {
            $rows[] = $row;
        }
        if ($editId) {
            $record = $rows[0] ?? [];
        }
    } elseif ($section === 'capacity') {
        $sql = 'SELECT hospitalId, hospitalName
                FROM Hospital
                WHERE TO_CHAR(hospitalId) LIKE :search
                   OR LOWER(hospitalName) LIKE :search
                ORDER BY hospitalId';
        $statement = oci_parse($oracleConnection, $sql);
        $searchValue = '%' . strtolower($search) . '%';
        oci_bind_by_name($statement, ':search', $searchValue);
        if (!oci_execute($statement, OCI_NO_AUTO_COMMIT)) {
            throw new Exception(oracle_error($statement));
        }
        while ($row = oci_fetch_array($statement, OCI_ASSOC)) {
            $row['TOTALBEDS'] = 0;
            $row['AVAILABLEBEDS'] = 0;
            $row['USEDBEDS'] = 0;
            $row['ACTIVESTAYS'] = 0;
            $row['TOTALEQUIPMENT'] = 0;
            $row['AVAILABLEEQUIPMENT'] = 0;
            $row['USEDEQUIPMENT'] = 0;
            $rows[(int) $row['HOSPITALID']] = $row;
        }
        $sql = 'SELECT b.hospitalId, b.status, COUNT(*) AS bedCount
                FROM ICU_Bed b
                GROUP BY b.hospitalId, b.status
                ORDER BY b.hospitalId';
        $statement = oci_parse($oracleConnection, $sql);
        if (!oci_execute($statement, OCI_NO_AUTO_COMMIT)) {
            throw new Exception(oracle_error($statement));
        }
        while ($row = oci_fetch_array($statement, OCI_ASSOC)) {
            $id = (int) $row['HOSPITALID'];
            if (!isset($rows[$id])) {
                continue;
            }
            $rows[$id]['TOTALBEDS'] += (int) $row['BEDCOUNT'];
            if ($row['STATUS'] === 'Available') {
                $rows[$id]['AVAILABLEBEDS'] = (int) $row['BEDCOUNT'];
            } elseif ($row['STATUS'] === 'In use') {
                $rows[$id]['USEDBEDS'] = (int) $row['BEDCOUNT'];
            }
        }
        // Count each relationship separately so beds, stays and equipment cannot multiply rows.
        $sql = 'SELECT b.hospitalId, COUNT(*) AS activeStays
                FROM ICU_Stay s
                JOIN ICU_Bed b ON b.bedId = s.bedId
                WHERE s.leavingDate IS NULL
                GROUP BY b.hospitalId';
        $statement = oci_parse($oracleConnection, $sql);
        if (!oci_execute($statement, OCI_NO_AUTO_COMMIT)) {
            throw new Exception(oracle_error($statement));
        }
        while ($row = oci_fetch_array($statement, OCI_ASSOC)) {
            $id = (int) $row['HOSPITALID'];
            if (isset($rows[$id])) {
                $rows[$id]['ACTIVESTAYS'] = (int) $row['ACTIVESTAYS'];
            }
        }
        $sql = 'SELECT e.hospitalId, e.status, COUNT(*) AS equipmentCount
                FROM Equipment e
                GROUP BY e.hospitalId, e.status';
        $statement = oci_parse($oracleConnection, $sql);
        if (!oci_execute($statement, OCI_NO_AUTO_COMMIT)) {
            throw new Exception(oracle_error($statement));
        }
        while ($row = oci_fetch_array($statement, OCI_ASSOC)) {
            $id = (int) $row['HOSPITALID'];
            if (!isset($rows[$id])) {
                continue;
            }
            $rows[$id]['TOTALEQUIPMENT'] += (int) $row['EQUIPMENTCOUNT'];
            if ($row['STATUS'] === 'Available') {
                $rows[$id]['AVAILABLEEQUIPMENT'] = (int) $row['EQUIPMENTCOUNT'];
            } elseif ($row['STATUS'] === 'In use') {
                $rows[$id]['USEDEQUIPMENT'] = (int) $row['EQUIPMENTCOUNT'];
            }
        }
    } elseif ($section === 'charges') {
        $sql = "SELECT staleness, TO_CHAR(last_refresh_date, 'YYYY-MM-DD HH24:MI:SS') AS refreshed
                FROM user_mviews
                WHERE mview_name = 'HOSPITALCHARGES_MATVIEW'";
        $statement = oci_parse($oracleConnection, $sql);
        if (!oci_execute($statement, OCI_NO_AUTO_COMMIT)) {
            throw new Exception(oracle_error($statement));
        }
        $chargeView = oci_fetch_array($statement, OCI_ASSOC | OCI_RETURN_NULLS);
        $hasChargesView = (bool) $chargeView;
        if ($hasChargesView) {
            $sql = 'SELECT hospitalId, hospitalName, "SUM(D.AMOUNT)" AS totalCharges
                FROM hospitalCharges_MatView
                WHERE TO_CHAR(hospitalId) LIKE :search
                       OR LOWER(hospitalName) LIKE :search
                ORDER BY hospitalId';
            $statement = oci_parse($oracleConnection, $sql);
            $searchValue = '%' . strtolower($search) . '%';
            oci_bind_by_name($statement, ':search', $searchValue);
            if (!oci_execute($statement, OCI_NO_AUTO_COMMIT)) {
                throw new Exception(oracle_error($statement));
            }
            while ($row = oci_fetch_array($statement, OCI_ASSOC)) {
                $chargeSummary[] = $row;
            }
        }
    } elseif ($section === 'routes' && $adminHospitalId) {
        $rows = neo4j_query('MATCH (a:Hospital {hospitalId: $id})-[:ROUTE]-(b:Hospital)
                             RETURN DISTINCT b.hospitalId AS hospitalId, b.hospitalName AS hospitalName ORDER BY hospitalName',
                             ['id' => $adminHospitalId]);
    }
    if ($editId && !$record) {
        throw new Exception('Record not found.');
    }
} catch (Exception $exception) {
    if ($oracleConnection) {
        oci_rollback($oracleConnection);
    }
    $error = $exception->getMessage();
}
require 'includes/header.php';
?>
<h1>Admin</h1>
<?php if ($mode === 'admin' && $oracleConnection) { ?>
    <nav class="tabs">
        <a href="admin.php">Hospitals</a>
        <a href="admin.php?section=beds">ICU Beds</a>
        <a href="admin.php?section=equipment">Equipment</a>
        <a href="admin.php?section=capacity">Global Capacity</a>
        <a href="admin.php?section=charges">Hospital Charges</a>
        <a href="admin.php?section=routes">Routes</a>
    </nav>
    <?php if (in_array($section, ['hospitals', 'beds', 'equipment', 'capacity', 'charges'], true) && !$editId && !isset($_GET['new'])) { ?>
        <form method="get" class="filters">
            <input type="hidden" name="section" value="<?php echo e($section); ?>">
            <input type="hidden" name="hospitalId" value="<?php echo e($adminHospitalId); ?>">
            <label>
                <?php echo $section === 'capacity' ? 'Hospital ID or name' : 'Record ID, name or hospital'; ?>
                <input name="q" value="<?php echo e($search); ?>">
            </label>
            <button>Search</button>
            <a href="admin.php?section=<?php echo e($section); ?>">Clear Search</a>
        </form>
    <?php } ?>
    <?php if ($section === 'hospitals') { ?>
        <?php if (isset($_GET['new']) || $record) { ?>
            <section class="panel narrow">
                <h2><?php echo $editId ? 'Edit Hospital #' . e($editId) : 'Add Hospital'; ?></h2>
                <form method="post" data-confirm="Save hospital details?">
                    <?php csrf_fields(); ?>
                    <input type="hidden" name="editId" value="<?php echo e($editId); ?>">
                    <div class="form-grid">
                        <label>
                            Hospital Name
                            <input name="hospitalName" maxlength="100" value="<?php echo e($record['HOSPITALNAME'] ?? ''); ?>" required>
                        </label>
                        <label>
                            Country
                            <input name="country" maxlength="50" value="<?php echo e($record['COUNTRY'] ?? ''); ?>" required>
                        </label>
                        <label>
                            City
                            <input name="city" maxlength="50" value="<?php echo e($record['CITY'] ?? ''); ?>" required>
                        </label>
                        <label>
                            Street
                            <input name="street" maxlength="150" value="<?php echo e($record['STREET'] ?? ''); ?>" required>
                        </label>
                        <label>
                            Phone
                            <input name="phone" maxlength="10" value="<?php echo e($record['CONTACTNUMBER'] ?? ''); ?>" required>
                        </label>
                        <label>
                            Email
                            <input type="email" name="email" maxlength="100" value="<?php echo e($record['EMAIL'] ?? ''); ?>" required>
                        </label>
                    </div>
                    <button name="saveHospital">Save</button>
                    <a class="button secondary" href="admin.php">Cancel</a>
                </form>
            </section>
        <?php } else { ?>
            <a class="button" href="admin.php?new=1">Add Hospital</a>
            <?php if (!$rows) { ?>
                <p>No hospitals found.</p>
            <?php } ?>
            <section class="panel">
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Hospital</th>
                                <th>City</th>
                                <th>Country</th>
                                <th>Contact</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row) { ?>
                                <tr>
                                    <td>#<?php echo e($row['HOSPITALID']); ?></td>
                                    <td><?php echo e($row['HOSPITALNAME']); ?></td>
                                    <td><?php echo e($row['CITY']); ?></td>
                                    <td><?php echo e($row['COUNTRY']); ?></td>
                                    <td><?php echo e($row['CONTACTNUMBER']); ?><small><?php echo e($row['EMAIL']); ?></small></td>
                                    <td><a href="admin.php?editId=<?php echo e($row['HOSPITALID']); ?>">Edit</a></td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php } ?>
    <?php } elseif ($section === 'beds' || $section === 'equipment') { ?>
        <?php if (isset($_GET['new']) || $record) { ?>
            <section class="panel narrow">
                <h2><?php echo $section === 'beds' ? 'ICU Bed' : 'Equipment'; ?><?php if ($editId) { echo ' #' . e($editId); } ?></h2>
                <form method="post">
                    <?php csrf_fields(); ?>
                    <input type="hidden" name="editId" value="<?php echo e($editId); ?>">
                    <?php if ($section === 'beds') { ?>
                        <label>
                            Bed Number
                            <input name="bedNumber" maxlength="20" value="<?php echo e($record['BEDNUMBER'] ?? ''); ?>" required>
                        </label>
                    <?php } else { ?>
                        <label>
                            Equipment Name
                            <input name="equipmentName" maxlength="100" value="<?php echo e($record['EQUIPMENTNAME'] ?? ''); ?>" required>
                        </label>
                    <?php } ?>
                    <?php if ($section === 'equipment' && $hasEquipmentType) { ?>
                        <label>
                            Equipment Type
                            <input name="equipmentType" maxlength="100" value="<?php echo e($record['EQUIPMENTTYPE'] ?? ''); ?>" required>
                        </label>
                    <?php } ?>
                    <label>
                        Hospital
                        <select name="hospitalId" required>
                            <option value="">Select Hospital</option>
                            <?php foreach ($hospitals as $row) { ?>
                                <option value="<?php echo e($row['HOSPITALID']); ?>" <?php if ((int) $row['HOSPITALID'] === (int) ($record['HOSPITALID'] ?? $adminHospitalId)) { echo 'selected'; } ?>>#<?php echo e($row['HOSPITALID']); ?> — <?php echo e($row['HOSPITALNAME']); ?></option>
                            <?php } ?>
                        </select>
                    </label>
                    <?php if ($record) { ?>
                        <p>Status: <?php echo e($record['STATUS']); ?></p>
                    <?php } ?>
                    <button name="<?php echo $section === 'beds' ? 'saveBed' : 'saveEquipment'; ?>">Save</button>
                    <a class="button secondary" href="admin.php?section=<?php echo e($section); ?>">Cancel</a>
                    <?php if ($editId && ($record['STATUS'] ?? '') === 'Available') { ?>
                        <button class="danger" name="<?php echo $section === 'beds' ? 'deleteBed' : 'deleteEquipment'; ?>">Delete Unused Item</button>
                    <?php } ?>
                </form>
            </section>
        <?php } else { ?>
            <form method="get" class="filters">
                <input type="hidden" name="q" value="<?php echo e($search); ?>">
                <input type="hidden" name="section" value="<?php echo e($section); ?>">
                <label>
                    Hospital
                    <select name="hospitalId">
                        <option value="0">All Hospitals</option>
                        <?php foreach ($hospitals as $row) { ?>
                            <option value="<?php echo e($row['HOSPITALID']); ?>" <?php if ((int) $row['HOSPITALID'] === $adminHospitalId) { echo 'selected'; } ?>>#<?php echo e($row['HOSPITALID']); ?> — <?php echo e($row['HOSPITALNAME']); ?></option>
                        <?php } ?>
                    </select>
                </label>
                <button>View</button>
                <a class="button secondary" href="admin.php?section=<?php echo e($section); ?>&amp;new=1&amp;hospitalId=<?php echo $adminHospitalId; ?>">Add <?php echo $section === 'beds' ? 'Bed' : 'Equipment'; ?></a>
            </form>
            <section class="panel">
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th><?php echo $section === 'beds' ? 'Bed' : 'Equipment'; ?></th>
                                <th>Hospital</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row) { ?>
                                <?php $id = $section === 'beds' ? $row['BEDID'] : $row['EQUIPMENTID']; ?>
                                <tr>
                                    <td>#<?php echo e($id); ?></td>
                                    <td><?php echo e($section === 'beds' ? $row['BEDNUMBER'] : $row['EQUIPMENTNAME']); ?><?php if (isset($row['EQUIPMENTTYPE'])) { ?><small><?php echo e($row['EQUIPMENTTYPE']); ?></small><?php } ?></td>
                                    <td>#<?php echo e($row['HOSPITALID']); ?> — <?php echo e($row['HOSPITALNAME']); ?></td>
                                    <td><?php echo e($row['STATUS']); ?></td>
                                    <td><a href="admin.php?section=<?php echo e($section); ?>&amp;editId=<?php echo e($id); ?>">Edit</a></td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
                <?php if (!$rows) { ?>
                    <p>No records found.</p>
                <?php } ?>
            </section>
        <?php } ?>
    <?php } elseif ($section === 'capacity') { ?>
        <section class="panel">
            <h2>Global Capacity</h2>
            <div class="table-wrap">
                <table id="global-capacity">
                    <thead>
                        <tr>
                            <th>Hospital ID</th>
                            <th>Hospital Name</th>
                            <th>Total ICU Beds</th>
                            <th>Available Beds</th>
                            <th>Beds in Use</th>
                            <th>Active ICU Stays</th>
                            <th>Total Equipment</th>
                            <th>Available Equipment</th>
                            <th>Equipment in Use</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row) { ?>
                            <tr>
                                <td>#<?php echo e($row['HOSPITALID']); ?></td>
                                <td><?php echo e($row['HOSPITALNAME']); ?></td>
                                <td><?php echo e($row['TOTALBEDS']); ?></td>
                                <td><?php echo e($row['AVAILABLEBEDS']); ?></td>
                                <td><?php echo e($row['USEDBEDS']); ?></td>
                                <td><?php echo e($row['ACTIVESTAYS']); ?></td>
                                <td><?php echo e($row['TOTALEQUIPMENT']); ?></td>
                                <td><?php echo e($row['AVAILABLEEQUIPMENT']); ?></td>
                                <td><?php echo e($row['USEDEQUIPMENT']); ?></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
            <?php if (!$rows) { ?>
                <p>No hospitals found.</p>
            <?php } ?>
        </section>
    <?php } elseif ($section === 'charges') { ?>
        <section class="panel">
            <h2>Hospital Charge Summary</h2>
            <?php if ($hasChargesView) { ?>
                <p>Last refreshed: <?php echo e($chargeView['REFRESHED']); ?> · <?php echo e($chargeView['STALENESS']); ?></p>
                <div class="table-wrap">
                    <table id="hospital-charges">
                        <thead>
                            <tr>
                                <th>Hospital ID</th>
                                <th>Hospital</th>
                                <th>Total Charges</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($chargeSummary as $row) { ?>
                                <tr>
                                    <td>#<?php echo e($row['HOSPITALID']); ?></td>
                                    <td><?php echo e($row['HOSPITALNAME']); ?></td>
                                    <td><?php echo e(number_format((float) $row['TOTALCHARGES'], 2)); ?></td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
                <?php if (!$chargeSummary) { ?>
                    <p>No charges found.</p>
                <?php } ?>
            <?php } else { ?>
                <p>Hospital charge summary is unavailable.</p>
            <?php } ?>
        </section>
    <?php } elseif ($section === 'routes') { ?>
        <section class="panel narrow">
            <h2>Add Route</h2>
            <form method="post">
                <?php csrf_fields(); ?>
                <label>
                    From
                    <select name="sourceId" required>
                        <option value="">Select Hospital</option>
                        <?php foreach ($hospitals as $row) { ?>
                            <option value="<?php echo e($row['HOSPITALID']); ?>">#<?php echo e($row['HOSPITALID']); ?> — <?php echo e($row['HOSPITALNAME']); ?></option>
                        <?php } ?>
                    </select>
                </label>
                <label>
                    To
                    <select name="destinationId" required>
                        <option value="">Select Hospital</option>
                        <?php foreach ($hospitals as $row) { ?>
                            <option value="<?php echo e($row['HOSPITALID']); ?>">#<?php echo e($row['HOSPITALID']); ?> — <?php echo e($row['HOSPITALNAME']); ?></option>
                        <?php } ?>
                    </select>
                </label>
                <button name="saveRoute">Save Route</button>
            </form>
        </section>
        <form method="get" class="filters">
            <input type="hidden" name="q" value="<?php echo e($search); ?>">
            <input type="hidden" name="section" value="routes">
            <label>
                Hospital
                <select name="hospitalId" required>
                    <option value="">Select Hospital</option>
                    <?php foreach ($hospitals as $row) { ?>
                        <option value="<?php echo e($row['HOSPITALID']); ?>" <?php if ((int) $row['HOSPITALID'] === $adminHospitalId) { echo 'selected'; } ?>>#<?php echo e($row['HOSPITALID']); ?> — <?php echo e($row['HOSPITALNAME']); ?></option>
                    <?php } ?>
                </select>
            </label>
            <button>View Routes</button>
        </form>
        <section class="panel">
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Connected Hospital</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row) { ?>
                            <tr>
                                <td>#<?php echo e($row['hospitalId']); ?> — <?php echo e($row['hospitalName']); ?></td>
                                <td><form method="post" data-confirm="Remove this route? Transfer history will stay."><?php csrf_fields(); ?><input type="hidden" name="sourceId" value="<?php echo e($adminHospitalId); ?>"><input type="hidden" name="destinationId" value="<?php echo e($row['hospitalId']); ?>"><button class="secondary" name="removeRoute">Remove</button></form></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
            <?php if (!$rows) { ?>
                <p>No routes selected.</p>
            <?php } ?>
        </section>
    <?php } ?>
    <?php if (in_array($section, ['hospitals', 'beds', 'equipment'], true) && !$editId && !isset($_GET['new'])) { ?>
        <div class="links">
            <?php if ($offset > 0) { ?>
                <a href="admin.php?section=<?php echo e($section); ?>&amp;hospitalId=<?php echo $adminHospitalId; ?>&amp;q=<?php echo urlencode($search); ?>&amp;offset=<?php echo max(0, $offset - 100); ?>">Previous</a>
            <?php } ?>
            <?php if (count($rows) === 100) { ?>
                <a href="admin.php?section=<?php echo e($section); ?>&amp;hospitalId=<?php echo $adminHospitalId; ?>&amp;q=<?php echo urlencode($search); ?>&amp;offset=<?php echo $offset + 100; ?>">Next</a>
            <?php } ?>
        </div>
    <?php } ?>
<?php } ?>
<?php require 'includes/footer.php'; ?>
