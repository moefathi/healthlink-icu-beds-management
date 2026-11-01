<?php
require 'includes/connect.php';
$title = 'Routes';
$sourceId = $mode === 'staff' ? $hospitalId : 0;
$destinationId = 0;
$route = [];
$searched = false;
try {
    if ($mode === 'admin') {
        $sourceId = id_value($_GET, 'sourceId');
    }
    $destinationId = id_value($_GET, 'destinationId');
    if ($canOperate && $sourceId && $destinationId) {
        if ($sourceId === $destinationId) {
            throw new Exception('Select another hospital.');
        }
        $knownIds = array_column($hospitals, 'HOSPITALID');
        if (!in_array($sourceId, $knownIds) || !in_array($destinationId, $knownIds)) {
            throw new Exception('Hospital not found.');
        }
        $result = neo4j_query('MATCH (a:Hospital {hospitalId: $sourceId}), (b:Hospital {hospitalId: $destinationId})
                              MATCH p = shortestPath((a)-[:ROUTE*]-(b))
                              RETURN length(p) AS hops,
                                     [h IN nodes(p) | {hospitalId: h.hospitalId, hospitalName: h.hospitalName}] AS hospitals',
                              ['sourceId' => $sourceId, 'destinationId' => $destinationId]);
        $route = $result[0] ?? [];
        $searched = true;
    }
} catch (Exception $exception) {
    $error = $exception->getMessage();
}
require 'includes/header.php';
?>
<h1>Routes</h1>
<?php if ($canOperate) { ?>
    <form method="get" class="filters">
        <?php if ($mode === 'admin') { ?>
            <label>
                Source Hospital
                <select name="sourceId" required>
                    <option value="">Select Hospital</option>
                    <?php foreach ($hospitals as $row) { ?>
                        <option value="<?php echo e($row['HOSPITALID']); ?>" <?php if ((int) $row['HOSPITALID'] === $sourceId) { echo 'selected'; } ?>>#<?php echo e($row['HOSPITALID']); ?> — <?php echo e($row['HOSPITALNAME']); ?></option>
                    <?php } ?>
                </select>
            </label>
        <?php } else { ?>
            <p>From: <strong>#<?php echo e($hospitalId); ?> — <?php echo e($hospitalName); ?></strong></p>
        <?php } ?>
        <label>
            Destination Hospital
            <select name="destinationId" required>
                <option value="">Select Hospital</option>
                <?php foreach ($hospitals as $row) { ?>
                    <?php if ((int) $row['HOSPITALID'] !== $sourceId) { ?>
                        <option value="<?php echo e($row['HOSPITALID']); ?>" <?php if ((int) $row['HOSPITALID'] === $destinationId) { echo 'selected'; } ?>>#<?php echo e($row['HOSPITALID']); ?> — <?php echo e($row['HOSPITALNAME']); ?></option>
                    <?php } ?>
                <?php } ?>
            </select>
        </label>
        <button>Find Route</button>
    </form>
    <?php if ($route) { ?>
        <section class="panel narrow">
            <h2>Minimum-Hop Route</h2>
            <p><?php echo e($route['hops']); ?> hops</p>
            <ol class="route-path">
                <?php foreach ($route['hospitals'] as $row) { ?>
                    <li>
                        <?php echo e($row['hospitalName']); ?>
                        <small>#<?php echo e($row['hospitalId']); ?></small>
                    </li>
                <?php } ?>
            </ol>
        </section>
    <?php } elseif ($searched) { ?>
        <p>No route found.</p>
    <?php } ?>
<?php } ?>
<?php require 'includes/footer.php'; ?>
