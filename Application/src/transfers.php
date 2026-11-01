<?php
require 'includes/connect.php';
$title = 'Transfers';
$stayId = 0;
$transferId = 0;
$destinationId = 0;
$stay = [];
$activeStays = [];
$destinations = [];
$transfers = [];
$direction = '';
$offset = 0;
$route = [];
$search = '';
$sourceSearch = '';
$destinationSearch = '';

try {
    $stayId = id_value($_GET, 'stayId');
    $transferId = id_value($_GET, 'transferId');
    $destinationId = id_value($_GET, 'destinationId');
    $direction = text_value($_GET, 'direction', 8, false);
    $offset = id_value($_GET, 'offset');
    $search = text_value($_GET, 'q', 100, false);
    $sourceSearch = text_value($_GET, 'sourceSearch', 100, false);
    $destinationSearch = text_value($_GET, 'destinationSearch', 100, false);
    if (!in_array($direction, ['', 'incoming', 'outgoing'], true)) {
        throw new Exception('Select a transfer direction.');
    }
    if (isset($_POST['confirmTransfer'])) {
        check_post(true);
        $stayId = id_value($_POST, 'stayId');
        $destinationId = id_value($_POST, 'destinationId');
    }
    if ($canOperate && $stayId) {
        $sql = "SELECT s.stayId, s.patientId, s.leavingDate,
                       TO_CHAR(s.enteringDate, 'YYYY-MM-DD') AS enteringDate,
                       h.hospitalId, h.hospitalName, p.Fname || ' ' || p.Mname || ' ' || p.Lname AS patientName
                FROM ICU_Stay s
                JOIN ICU_Bed b ON b.bedId = s.bedId
                JOIN Hospital h ON h.hospitalId = b.hospitalId
                JOIN Patient p ON p.patientId = s.patientId
                WHERE s.stayId = :stayId";
        if ($mode === 'staff') {
            $sql .= '
                AND b.hospitalId = :hospitalId';
        }
        if (isset($_POST['confirmTransfer'])) {
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
        if ($stay['LEAVINGDATE'] !== null) {
            throw new Exception('This stay has ended.');
        }
        $sourceId = (int) $stay['HOSPITALID'];
        if ($destinationId === $sourceId) {
            throw new Exception('Select another hospital.');
        }
        $sql = "SELECT h.hospitalId, h.hospitalName, COUNT(b.bedId) AS availableBeds
                FROM Hospital h
                JOIN ICU_Bed b ON b.hospitalId = h.hospitalId
                WHERE b.status = 'Available'
                AND h.hospitalId <> :sourceId
                AND (TO_CHAR(h.hospitalId) LIKE :destinationSearch OR LOWER(h.hospitalName) LIKE :destinationSearch)
                GROUP BY h.hospitalId, h.hospitalName
                ORDER BY h.hospitalName";
        $statement = oci_parse($oracleConnection, $sql);
        oci_bind_by_name($statement, ':sourceId', $sourceId, -1, SQLT_INT);
        $destinationValue = '%' . strtolower($destinationSearch) . '%';
        oci_bind_by_name($statement, ':destinationSearch', $destinationValue);
        if (!oci_execute($statement, OCI_NO_AUTO_COMMIT)) {
            throw new Exception(oracle_error($statement));
        }
        $candidateIds = [];
        while ($row = oci_fetch_array($statement, OCI_ASSOC)) {
            $destinations[(int) $row['HOSPITALID']] = $row;
            $candidateIds[] = (int) $row['HOSPITALID'];
        }
        if ($destinationId && !isset($destinations[$destinationId])) {
            throw new Exception('No available beds at this destination.');
        }
        $paths = neo4j_query('MATCH (a:Hospital {hospitalId: $sourceId})
                             UNWIND $candidateIds AS candidateId
                             MATCH (b:Hospital {hospitalId: candidateId})
                             OPTIONAL MATCH p = shortestPath((a)-[:ROUTE*]-(b))
                             RETURN b.hospitalId AS hospitalId, length(p) AS hops,
                                    [h IN nodes(p) | \'#\' + toString(h.hospitalId) + \' — \' + h.hospitalName] AS hospitals
                             ORDER BY hops, b.hospitalName',
                             ['sourceId' => (int) $sourceId, 'candidateIds' => $candidateIds]);
        $orderedDestinations = [];
        foreach ($paths as $path) {
            if ($path['hops'] !== null && isset($destinations[(int) $path['hospitalId']])) {
                $row = $destinations[(int) $path['hospitalId']];
                $row['hops'] = $path['hops'];
                $row['hospitals'] = $path['hospitals'];
                $orderedDestinations[(int) $path['hospitalId']] = $row;
            }
        }
        foreach ($destinations as $id => $row) {
            if (!isset($orderedDestinations[$id])) {
                $row['hops'] = null;
                $row['hospitals'] = [];
                $orderedDestinations[$id] = $row;
            }
        }
        $destinations = $orderedDestinations;
        if ($destinationId) {
            $route = $destinations[$destinationId];
            if ($route['hops'] === null) {
                throw new Exception('No route found.');
            }
        }
        if (isset($_POST['confirmTransfer'])) {
            if (!$destinationId) {
                throw new Exception('Select a destination.');
            }
            $transferDate = date_value($_POST, 'transferDate');
            if ($transferDate < $stay['ENTERINGDATE']) {
                throw new Exception('Transfer date is before admission.');
            }
            $sql = "SELECT bedId, status
                FROM ICU_Bed
                WHERE hospitalId = :destinationId AND status = 'Available'
                FOR UPDATE WAIT 5";
            $statement = oci_parse($oracleConnection, $sql);
            oci_bind_by_name($statement, ':destinationId', $destinationId);
            if (!oci_execute($statement, OCI_NO_AUTO_COMMIT)) {
                throw new Exception(oracle_error($statement));
            }
            $bed = oci_fetch_array($statement, OCI_ASSOC);
            if (!$bed || $bed['STATUS'] !== 'Available') {
                throw new Exception('No available beds at this destination.');
            }
            // The transfer trigger ends the source stay and releases its resources.
            $sql = "INSERT INTO Hospital_Transfer (transferDate, stayId, fromhospitalId, tohospitalId)
                    VALUES (TO_DATE(:transferDate, 'YYYY-MM-DD'), :stayId, :sourceId, :destinationId)

                RETURNING transferId INTO :transferId";
            $statement = oci_parse($oracleConnection, $sql);
            oci_bind_by_name($statement, ':transferDate', $transferDate);
            oci_bind_by_name($statement, ':stayId', $stayId);
            oci_bind_by_name($statement, ':sourceId', $sourceId, -1, SQLT_INT);
            oci_bind_by_name($statement, ':destinationId', $destinationId);
            oci_bind_by_name($statement, ':transferId', $transferId, -1, SQLT_INT);
            if (!oci_execute($statement, OCI_NO_AUTO_COMMIT)) {
                throw new Exception(oracle_error($statement));
            }
            if (!oci_commit($oracleConnection)) {
                throw new Exception('Transfer save not confirmed. Check its history.');
            }
            $_SESSION['message'] = 'Transfer completed. Destination admission is separate.';
            try {
                $result = neo4j_query('MATCH (a:Hospital {hospitalId: $sourceId}), (b:Hospital {hospitalId: $destinationId})
                                      CREATE (a)-[r:TRANSFERRED_STAY_TO {transferId: $transferId, stayId: $stayId, transferDate: $transferDate}]->(b)
                                      RETURN r.transferId AS transferId',
                                      ['sourceId' => (int) $sourceId, 'destinationId' => (int) $destinationId, 'transferId' => (int) $transferId, 'stayId' => (int) $stayId, 'transferDate' => $transferDate]);
                if (!$result) {
                    throw new Exception('Hospital node missing.');
                }
            } catch (Exception $exception) {
                $_SESSION['message'] = 'Transfer #' . $transferId . ' saved in Oracle. Neo4j history was not confirmed.';
            }
            header('Location: transfers.php?transferId=' . $transferId, true, 303);
            exit;
        }
    }
    if ($canOperate) {
        $sql = "SELECT s.stayId, s.patientId, s.bedId, b.bedNumber, h.hospitalId, h.hospitalName,
                       p.Fname || ' ' || p.Mname || ' ' || p.Lname AS patientName, p.nationalId
                FROM ICU_Stay s
                JOIN ICU_Bed b ON b.bedId = s.bedId
                JOIN Hospital h ON h.hospitalId = b.hospitalId
                JOIN Patient p ON p.patientId = s.patientId
                WHERE s.leavingDate IS NULL
                AND (TO_CHAR(s.stayId) LIKE :search
                     OR LOWER(p.Fname || ' ' || p.Mname || ' ' || p.Lname) LIKE :search
                     OR p.nationalId LIKE :search
                     OR TO_CHAR(b.bedId) LIKE :search
                     OR LOWER(b.bedNumber) LIKE :search
                     OR TO_CHAR(h.hospitalId) LIKE :search
                     OR LOWER(h.hospitalName) LIKE :search)";
        if ($mode === 'staff') {
            $sql .= '
                AND b.hospitalId = :hospitalId';
        }
        $sql .= '
                ORDER BY s.stayId DESC
                FETCH FIRST 100 ROWS ONLY';
        $statement = oci_parse($oracleConnection, $sql);
        $sourceValue = '%' . strtolower($sourceSearch) . '%';
        oci_bind_by_name($statement, ':search', $sourceValue);
        if ($mode === 'staff') {
            oci_bind_by_name($statement, ':hospitalId', $hospitalId, -1, SQLT_INT);
        }
        if (!oci_execute($statement, OCI_NO_AUTO_COMMIT)) {
            throw new Exception(oracle_error($statement));
        }
        while ($row = oci_fetch_array($statement, OCI_ASSOC)) {
            $activeStays[] = $row;
        }
        $sql = "SELECT t.transferId, t.stayId, t.fromhospitalId, t.tohospitalId, s.patientId,
                       TO_CHAR(t.transferDate, 'YYYY-MM-DD') AS transferDate,
                       a.hospitalName AS sourceName, b.hospitalName AS destinationName,
                       p.Fname || ' ' || p.Mname || ' ' || p.Lname AS patientName";
        if ($mode === 'staff' && $hospitalId === 554 && $direction === 'outgoing') {
            $sql .= '
                FROM hospitalTransfers_View t';
        } else {
            $sql .= '
                FROM Hospital_Transfer t';
        }
        $sql .= "\n                JOIN ICU_Stay s ON s.stayId = t.stayId
                JOIN Patient p ON p.patientId = s.patientId
                JOIN Hospital a ON a.hospitalId = t.fromhospitalId
                JOIN Hospital b ON b.hospitalId = t.tohospitalId
                WHERE (TO_CHAR(t.transferId) LIKE :search
                       OR TO_CHAR(t.stayId) LIKE :search
                       OR LOWER(p.Fname || ' ' || p.Mname || ' ' || p.Lname) LIKE :search
                       OR p.nationalId LIKE :search
                       OR TO_CHAR(a.hospitalId) LIKE :search
                       OR TO_CHAR(b.hospitalId) LIKE :search
                       OR LOWER(a.hospitalName) LIKE :search
                       OR LOWER(b.hospitalName) LIKE :search)";
        if ($mode === 'staff') {
            if ($direction === 'incoming') {
                $sql .= '
                AND t.tohospitalId = :hospitalId';
            } elseif ($direction === 'outgoing') {
                $sql .= '
                AND t.fromhospitalId = :hospitalId';
            } else {
                $sql .= '
                AND (t.fromhospitalId = :hospitalId OR t.tohospitalId = :hospitalId)';
            }
        }
        if ($transferId) {
            $sql .= '
                AND t.transferId = :transferId';
            $offset = 0;
        }
        $sql .= '
                ORDER BY t.transferId DESC
                OFFSET :offset ROWS FETCH NEXT 100 ROWS ONLY';
        $statement = oci_parse($oracleConnection, $sql);
        $searchValue = '%' . strtolower($search) . '%';
        oci_bind_by_name($statement, ':search', $searchValue);
        if ($mode === 'staff') {
            oci_bind_by_name($statement, ':hospitalId', $hospitalId, -1, SQLT_INT);
        }
        if ($transferId) {
            oci_bind_by_name($statement, ':transferId', $transferId, -1, SQLT_INT);
        }
        oci_bind_by_name($statement, ':offset', $offset, -1, SQLT_INT);
        if (!oci_execute($statement, OCI_NO_AUTO_COMMIT)) {
            throw new Exception(oracle_error($statement));
        }
        while ($row = oci_fetch_array($statement, OCI_ASSOC)) {
            $transfers[] = $row;
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
<h1>Transfers</h1>
<?php if ($canOperate) { ?>
    <section class="panel">
        <h2>Find Source Stay</h2>
        <form method="get" class="filters">
            <label>
                Stay ID, patient name, national ID, bed or hospital
                <input name="sourceSearch" value="<?php echo e($sourceSearch); ?>">
            </label>
            <button>Search Active Stays</button>
            <a href="transfers.php">Clear Search</a>
        </form>
        <div class="table-wrap">
            <table id="source-stays">
                <thead>
                    <tr>
                        <th>Stay ID</th>
                        <th>Patient</th>
                        <th>National ID</th>
                        <th>Hospital</th>
                        <th>Bed</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($activeStays as $row) { ?>
                        <tr>
                            <td>#<?php echo e($row['STAYID']); ?></td>
                            <td>#<?php echo e($row['PATIENTID']); ?> — <?php echo e($row['PATIENTNAME']); ?></td>
                            <td><?php echo e($row['NATIONALID']); ?></td>
                            <td>#<?php echo e($row['HOSPITALID']); ?> — <?php echo e($row['HOSPITALNAME']); ?></td>
                            <td>#<?php echo e($row['BEDID']); ?> — <?php echo e($row['BEDNUMBER']); ?></td>
                            <td><a href="transfers.php?stayId=<?php echo e($row['STAYID']); ?>">Find Destination</a></td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
        <?php if (!$activeStays) { ?>
            <p>No active stays found.</p>
        <?php } ?>
        <?php if (count($activeStays) === 100) { ?>
            <p>Showing 100 stays. Search to narrow the results.</p>
        <?php } ?>
    </section>
    <?php if ($stay && $error === '') { ?>
        <section class="context">
            <strong>Stay #<?php echo e($stayId); ?></strong>
            <span>Patient #<?php echo e($stay['PATIENTID']); ?> — <?php echo e($stay['PATIENTNAME']); ?></span>
            <span>Hospital #<?php echo e($stay['HOSPITALID']); ?> — <?php echo e($stay['HOSPITALNAME']); ?></span>
        </section>
        <?php if ($route) { ?>
            <section class="panel narrow">
                <h2>Confirm Transfer</h2>
                <p><?php echo e(implode(' → ', $route['hospitals'])); ?> · <?php echo e($route['hops']); ?> hops</p>
                <p>This ends the source stay. Destination admission is separate.</p>
                <form method="post" data-confirm="Confirm this transfer and end the source stay?">
                    <?php csrf_fields(); ?>
                    <input type="hidden" name="stayId" value="<?php echo e($stayId); ?>">
                    <input type="hidden" name="destinationId" value="<?php echo e($destinationId); ?>">
                    <label>
                        Transfer Date
                        <input type="date" name="transferDate" value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>" required>
                    </label>
                    <button name="confirmTransfer">Confirm Transfer</button>
                    <a class="button secondary" href="transfers.php?stayId=<?php echo e($stayId); ?>">Cancel</a>
                </form>
            </section>
        <?php } else { ?>
            <section class="panel">
                <h2>Destination Hospitals</h2>
                <form method="get" class="filters">
                    <input type="hidden" name="stayId" value="<?php echo e($stayId); ?>">
                    <label>
                        Destination hospital ID or name
                        <input name="destinationSearch" value="<?php echo e($destinationSearch); ?>">
                    </label>
                    <button>Search Destinations</button>
                    <a href="transfers.php?stayId=<?php echo e($stayId); ?>">Clear Search</a>
                </form>
                <div class="table-wrap">
                    <table id="destinations">
                        <thead>
                            <tr>
                                <th>Hospital</th>
                                <th>Available Beds</th>
                                <th>Minimum-Hop Route</th>
                                <th>Hops</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($destinations as $row) { ?>
                                <tr>
                                    <td>#<?php echo e($row['HOSPITALID']); ?> — <?php echo e($row['HOSPITALNAME']); ?></td>
                                    <td><?php echo e($row['AVAILABLEBEDS']); ?></td>
                                    <td><?php echo $row['hops'] === null ? 'No route found.' : e(implode(' → ', $row['hospitals'])); ?></td>
                                    <td><?php echo e($row['hops'] ?? '—'); ?></td>
                                    <td><?php if ($row['hops'] !== null) { ?><a href="transfers.php?stayId=<?php echo e($stayId); ?>&amp;destinationId=<?php echo e($row['HOSPITALID']); ?>">Select</a><?php } ?></td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
                <?php if (!$destinations) { ?>
                    <p>No available destinations.</p>
                <?php } ?>
            </section>
        <?php } ?>
    <?php } ?>
    <section class="panel">
        <h2>Transfer History</h2>
        <form method="get" class="filters">
            <label>
                Transfer ID, stay, patient or hospital
                <input name="q" value="<?php echo e($search); ?>">
            </label>
            <?php if ($mode === 'staff') { ?>
                <label>
                    Direction
                    <select name="direction">
                        <option value="">Incoming and Outgoing</option>
                        <option value="incoming" <?php if ($direction === 'incoming') { echo 'selected'; } ?>>Incoming</option>
                        <option value="outgoing" <?php if ($direction === 'outgoing') { echo 'selected'; } ?>>Outgoing</option>
                    </select>
                </label>
            <?php } ?>
            <button>Search History</button>
            <a href="transfers.php">Clear Search</a>
        </form>
        <div class="table-wrap">
            <table id="transfer-history">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Date</th>
                        <th>Stay</th>
                        <th>From</th>
                        <th>To</th>
                        <th>Patient</th>
                        <?php if ($mode === 'staff') { ?>
                            <th>Direction</th>
                        <?php } ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transfers as $row) { ?>
                        <tr>
                            <td>#<?php echo e($row['TRANSFERID']); ?></td>
                            <td><?php echo e($row['TRANSFERDATE']); ?></td>
                            <td>#<?php echo e($row['STAYID']); ?></td>
                            <td>#<?php echo e($row['FROMHOSPITALID']); ?> — <?php echo e($row['SOURCENAME']); ?></td>
                            <td>#<?php echo e($row['TOHOSPITALID']); ?> — <?php echo e($row['DESTINATIONNAME']); ?></td>
                            <td><a href="patients.php?patientId=<?php echo e($row['PATIENTID']); ?>">#<?php echo e($row['PATIENTID']); ?> — <?php echo e($row['PATIENTNAME']); ?></a></td>
                            <?php if ($mode === 'staff') { ?>
                                <td><?php echo (int) $row['FROMHOSPITALID'] === $hospitalId ? 'Outgoing' : 'Incoming'; ?></td>
                            <?php } ?>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
        <?php if (!$transfers) { ?>
            <p>No transfers found.</p>
        <?php } ?>
    </section>
    <div class="links">
        <?php if ($offset > 0) { ?>
            <a href="transfers.php?q=<?php echo urlencode($search); ?>&amp;offset=<?php echo max(0, $offset - 100); ?>&amp;direction=<?php echo urlencode($direction); ?>">Previous</a>
        <?php } ?>
        <?php if (count($transfers) === 100) { ?>
            <a href="transfers.php?q=<?php echo urlencode($search); ?>&amp;offset=<?php echo $offset + 100; ?>&amp;direction=<?php echo urlencode($direction); ?>">Next</a>
        <?php } ?>
    </div>
<?php } ?>
<?php require 'includes/footer.php'; ?>
