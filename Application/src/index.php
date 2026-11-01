<?php
require 'includes/connect.php';
$title = 'Dashboard';
$totals = [];
$beds = [];
$search = '';

try {
    $search = text_value($_GET, 'q', 100, false);
    if ($canOperate) {
        $sql = "SELECT COUNT(*) AS total
                FROM ICU_Bed";
        if ($mode === 'staff') {
            $sql .= '
                WHERE hospitalId = :hospitalId';
        }
        $statement = oci_parse($oracleConnection, $sql);
        if ($mode === 'staff') {
            oci_bind_by_name($statement, ':hospitalId', $hospitalId, -1, SQLT_INT);
        }
        if (!oci_execute($statement, OCI_NO_AUTO_COMMIT)) {
            throw new Exception(oracle_error($statement));
        }
        $row = oci_fetch_array($statement, OCI_ASSOC);
        $totals['TOTALBEDS'] = $row['TOTAL'];
        oci_free_statement($statement);

        $sql = "SELECT COUNT(*) AS total
                FROM ICU_Bed b
                WHERE b.status = 'Available'";
        if ($mode === 'staff') {
            $sql .= '
                AND hospitalId = :hospitalId';
        }
        $statement = oci_parse($oracleConnection, $sql);
        if ($mode === 'staff') {
            oci_bind_by_name($statement, ':hospitalId', $hospitalId, -1, SQLT_INT);
        }
        if (!oci_execute($statement, OCI_NO_AUTO_COMMIT)) {
            throw new Exception(oracle_error($statement));
        }
        $row = oci_fetch_array($statement, OCI_ASSOC);
        $totals['AVAILABLEBEDS'] = $row['TOTAL'];
        oci_free_statement($statement);

        $sql = "SELECT COUNT(*) AS total
                FROM ICU_Bed b
                WHERE b.status = 'In use'";
        if ($mode === 'staff') {
            $sql .= '
                AND hospitalId = :hospitalId';
        }
        $statement = oci_parse($oracleConnection, $sql);
        if ($mode === 'staff') {
            oci_bind_by_name($statement, ':hospitalId', $hospitalId, -1, SQLT_INT);
        }
        if (!oci_execute($statement, OCI_NO_AUTO_COMMIT)) {
            throw new Exception(oracle_error($statement));
        }
        $row = oci_fetch_array($statement, OCI_ASSOC);
        $totals['OCCUPIEDBEDS'] = $row['TOTAL'];
        oci_free_statement($statement);

        $sql = "SELECT COUNT(*) AS total
                FROM ICU_Stay s
                JOIN ICU_Bed b ON b.bedId = s.bedId
                WHERE s.leavingDate IS NULL";
        if ($mode === 'staff') {
            $sql .= '
                AND b.hospitalId = :hospitalId';
        }
        $statement = oci_parse($oracleConnection, $sql);
        if ($mode === 'staff') {
            oci_bind_by_name($statement, ':hospitalId', $hospitalId, -1, SQLT_INT);
        }
        if (!oci_execute($statement, OCI_NO_AUTO_COMMIT)) {
            throw new Exception(oracle_error($statement));
        }
        $row = oci_fetch_array($statement, OCI_ASSOC);
        $totals['ACTIVESTAYS'] = $row['TOTAL'];
        oci_free_statement($statement);

        $sql = "SELECT COUNT(*) AS total
                FROM Equipment e
                WHERE e.status = 'Available'";
        if ($mode === 'staff') {
            $sql .= '
                AND hospitalId = :hospitalId';
        }
        $statement = oci_parse($oracleConnection, $sql);
        if ($mode === 'staff') {
            oci_bind_by_name($statement, ':hospitalId', $hospitalId, -1, SQLT_INT);
        }
        if (!oci_execute($statement, OCI_NO_AUTO_COMMIT)) {
            throw new Exception(oracle_error($statement));
        }
        $row = oci_fetch_array($statement, OCI_ASSOC);
        $totals['AVAILABLEEQUIPMENT'] = $row['TOTAL'];
        oci_free_statement($statement);
        if ($mode === 'staff') {
            if ($hospitalId === 554) {
                $sql = 'SELECT bedId, bedNumber, status, hospitalId
                FROM hospitalBeds_view
                WHERE hospitalId = :hospitalId';
            } else {
                $sql = 'SELECT bedId, bedNumber, status, hospitalId
                FROM ICU_Bed
                WHERE hospitalId = :hospitalId';
            }
            $sql .= ' AND (TO_CHAR(bedId) LIKE :search OR LOWER(bedNumber) LIKE :search)
                      ORDER BY bedId';
            $statement = oci_parse($oracleConnection, $sql);
            $searchValue = '%' . strtolower($search) . '%';
            oci_bind_by_name($statement, ':hospitalId', $hospitalId, -1, SQLT_INT);
            oci_bind_by_name($statement, ':search', $searchValue);
            if (!oci_execute($statement, OCI_NO_AUTO_COMMIT)) {
                throw new Exception(oracle_error($statement));
            }
            while ($row = oci_fetch_array($statement, OCI_ASSOC)) {
                $beds[] = $row;
            }
        }
    }
} catch (Exception $exception) {
    $error = $exception->getMessage();
}
require 'includes/header.php';
?>
<h1>Dashboard</h1>
<?php if ($canOperate) { ?>
    <h2><?php echo $mode === 'staff' ? '#' . e($hospitalId) . ' — ' . e($hospitalName) : 'All Hospitals'; ?></h2>
    <?php if (count($totals) === 5) { ?>
        <section class="metrics">
            <article>
                <span>Total ICU Beds</span>
                <strong><?php echo e($totals['TOTALBEDS']); ?></strong>
            </article>
            <article>
                <span>Available Beds</span>
                <strong><?php echo e($totals['AVAILABLEBEDS']); ?></strong>
            </article>
            <article>
                <span>Beds in Use</span>
                <strong><?php echo e($totals['OCCUPIEDBEDS']); ?></strong>
            </article>
            <article>
                <span>Active Stays</span>
                <strong><?php echo e($totals['ACTIVESTAYS']); ?></strong>
            </article>
            <article>
                <span>Available Equipment</span>
                <strong><?php echo e($totals['AVAILABLEEQUIPMENT']); ?></strong>
            </article>
        </section>
    <?php } ?>
    <?php if ($mode === 'staff') { ?>
        <section class="panel">
            <h2>ICU Beds</h2>
            <form method="get" class="filters">
                <label>
                    Bed ID or number
                    <input name="q" value="<?php echo e($search); ?>">
                </label>
                <button>Search</button>
                <a href="index.php">Clear Search</a>
            </form>
            <div class="table-wrap">
                <table id="beds">
                    <thead>
                        <tr>
                            <th>Bed ID</th>
                            <th>Bed Number</th>
                            <th>Status</th>
                            <th>Hospital ID</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($beds as $row) { ?>
                            <tr>
                                <td>#<?php echo e($row['BEDID']); ?></td>
                                <td><?php echo e($row['BEDNUMBER']); ?></td>
                                <td><?php echo e($row['STATUS']); ?></td>
                                <td>#<?php echo e($row['HOSPITALID']); ?></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
            <?php if (!$beds) { ?>
                <p>No beds found.</p>
            <?php } ?>
        </section>
    <?php } else { ?>
        <p><a href="admin.php?section=capacity">Global Capacity</a></p>
    <?php } ?>
    <a class="button" href="stays.php?new=1">Create Stay</a>
<?php } ?>
<?php require 'includes/footer.php'; ?>
