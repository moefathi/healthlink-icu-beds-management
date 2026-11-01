<?php
require 'includes/connect.php';
$title = 'Daily Charges';
$stayId = 0;
$stay = [];
$stays = [];
$search = '';
$offset = 0;
$charges = [];
$total = 0;

try {
    $stayId = id_value($_GET, 'stayId');
    $search = text_value($_GET, 'q', 100, false);
    $offset = id_value($_GET, 'offset');
    if (isset($_POST['addCharge'])) {
        check_post(true);
        $stayId = id_value($_POST, 'stayId');
    }
    if ($canOperate && $stayId) {
        $sql = "SELECT s.stayId, s.patientId, h.hospitalId, s.leavingDate, TO_CHAR(s.enteringDate, 'YYYY-MM-DD') AS enteringDate,
                       h.hospitalName, p.Fname || ' ' || p.Mname || ' ' || p.Lname AS patientName
                FROM ICU_Stay s
                JOIN ICU_Bed b ON b.bedId = s.bedId
                JOIN Hospital h ON h.hospitalId = b.hospitalId
                JOIN Patient p ON p.patientId = s.patientId
                WHERE s.stayId = :stayId";
        if ($mode === 'staff') {
            $sql .= '
                AND b.hospitalId = :hospitalId';
        }
        if (isset($_POST['addCharge'])) {
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
        if (isset($_POST['addCharge'])) {
            $chargeDate = date_value($_POST, 'chargeDate');
            $amount = text_value($_POST, 'amount', 11);
            if ($stay['LEAVINGDATE'] !== null) {
                throw new Exception('This stay has ended.');
            }
            if ($chargeDate < $stay['ENTERINGDATE']) {
                throw new Exception('Charge date is before admission.');
            }
            if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/D', $amount) || (float) $amount <= 0) {
                throw new Exception('Enter a positive amount with at most two decimal places.');
            }
            $sql = "SELECT chargeId
                FROM Daily_Charge
                WHERE stayId = :stayId
                    AND chargeDate >= TO_DATE(:chargeDate, 'YYYY-MM-DD')
                    AND chargeDate < TO_DATE(:chargeDate, 'YYYY-MM-DD') + 1";
            $statement = oci_parse($oracleConnection, $sql);
            oci_bind_by_name($statement, ':stayId', $stayId);
            oci_bind_by_name($statement, ':chargeDate', $chargeDate);
            if (!oci_execute($statement, OCI_NO_AUTO_COMMIT)) {
                throw new Exception(oracle_error($statement));
            }
            if (oci_fetch_array($statement, OCI_ASSOC)) {
                throw new Exception('A charge already exists for this date.');
            }
            // The existing package commits the charge and its log entry.
            $sql = "BEGIN billing_PKG.addBill_PRC(:stayId, TO_DATE(:chargeDate, 'YYYY-MM-DD'),
                    TO_NUMBER(:amount, '99999999D99', 'NLS_NUMERIC_CHARACTERS=''.,''')); END;";
            $statement = oci_parse($oracleConnection, $sql);
            $amount = number_format((float) $amount, 2, '.', '');
            oci_bind_by_name($statement, ':stayId', $stayId);
            oci_bind_by_name($statement, ':chargeDate', $chargeDate);
            oci_bind_by_name($statement, ':amount', $amount);
            if (!oci_execute($statement, OCI_NO_AUTO_COMMIT)) {
                throw new Exception(oracle_error($statement));
            }
            $_SESSION['message'] = 'Daily charge added.';
            header('Location: billing.php?stayId=' . $stayId, true, 303);
            exit;
        }
        $sql = "SELECT chargeId, TO_CHAR(chargeDate, 'YYYY-MM-DD') AS chargeDate, amount
                FROM Daily_Charge
                WHERE stayId = :stayId
                ORDER BY chargeDate DESC";
        $statement = oci_parse($oracleConnection, $sql);
        oci_bind_by_name($statement, ':stayId', $stayId);
        if (!oci_execute($statement, OCI_NO_AUTO_COMMIT)) {
            throw new Exception(oracle_error($statement));
        }
        while ($row = oci_fetch_array($statement, OCI_ASSOC)) {
            $charges[] = $row;
        }
        if ($charges) {
            $sql = 'SELECT billing_PKG.getStayTotal_FUNC(:stayId) AS total
                FROM dual';
            $statement = oci_parse($oracleConnection, $sql);
            oci_bind_by_name($statement, ':stayId', $stayId);
            if (!oci_execute($statement, OCI_NO_AUTO_COMMIT)) {
                throw new Exception(oracle_error($statement));
            }
            $row = oci_fetch_array($statement, OCI_ASSOC);
            $total = $row['TOTAL'];
        }
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
<h1>Daily Charges</h1>
<?php if ($canOperate) { ?>
    <form method="get" class="filters">
        <label>
            Stay ID, patient name, national ID or hospital
            <input name="q" value="<?php echo e($search); ?>">
        </label>
        <button>Search Stays</button>
        <a href="billing.php">Clear Search</a>
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
                                <td><a href="billing.php?stayId=<?php echo e($row['STAYID']); ?>">#<?php echo e($row['STAYID']); ?></a></td>
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
                <a href="billing.php?q=<?php echo urlencode($search); ?>&amp;offset=<?php echo max(0, $offset - 100); ?>">Previous</a>
            <?php } ?>
            <?php if (count($stays) === 100) { ?>
                <a href="billing.php?q=<?php echo urlencode($search); ?>&amp;offset=<?php echo $offset + 100; ?>">Next</a>
            <?php } ?>
        </div>
    <?php } ?>
    <?php if ($stay) { ?>
        <section class="context">
            <strong>Stay #<?php echo e($stayId); ?></strong>
            <span>Patient #<?php echo e($stay['PATIENTID']); ?> — <?php echo e($stay['PATIENTNAME']); ?></span>
            <span>Hospital #<?php echo e($stay['HOSPITALID']); ?> — <?php echo e($stay['HOSPITALNAME']); ?></span>
        </section>
        <section class="panel">
            <h2>Daily Charges</h2>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Date</th>
                            <th>Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($charges as $row) { ?>
                            <tr>
                                <td>#<?php echo e($row['CHARGEID']); ?></td>
                                <td><?php echo e($row['CHARGEDATE']); ?></td>
                                <td><?php echo e(number_format((float) $row['AMOUNT'], 2)); ?></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
            <?php if (!$charges) { ?>
                <p>No charges.</p>
            <?php } ?>
            <p><strong>Total: <?php echo e(number_format((float) $total, 2)); ?></strong></p>
        </section>
        <?php if ($stay['LEAVINGDATE'] === null) { ?>
            <section class="panel narrow">
                <h2>Add Daily Charge</h2>
                <form method="post" data-confirm="Save this daily charge?">
                    <?php csrf_fields(); ?>
                    <input type="hidden" name="stayId" value="<?php echo e($stayId); ?>">
                    <label>
                        Date
                        <input type="date" name="chargeDate" value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>" required>
                    </label>
                    <label>
                        Amount
                        <input type="number" name="amount" min="0.01" max="99999999.99" step="0.01" required>
                    </label>
                    <button name="addCharge">Save</button>
                </form>
            </section>
        <?php } else { ?>
            <p>This stay has ended.</p>
        <?php } ?>
    <?php } ?>
<?php } ?>
<?php require 'includes/footer.php'; ?>
