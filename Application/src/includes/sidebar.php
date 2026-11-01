<nav class="main-nav" aria-label="Main menu">
    <a href="index.php" <?php if ($page === 'index.php') { echo 'class="active"'; } ?>>Dashboard</a>
    <a href="patients.php" <?php if ($page === 'patients.php') { echo 'class="active"'; } ?>>Patients</a>
    <a href="stays.php" <?php if ($page === 'stays.php') { echo 'class="active"'; } ?>>ICU Stays</a>
    <a href="equipment.php" <?php if ($page === 'equipment.php') { echo 'class="active"'; } ?>>Equipment</a>
    <a href="billing.php" <?php if ($page === 'billing.php') { echo 'class="active"'; } ?>>Daily Charges</a>
    <a href="medical.php" <?php if ($page === 'medical.php') { echo 'class="active"'; } ?>>Medical Information</a>
    <a href="transfers.php" <?php if ($page === 'transfers.php') { echo 'class="active"'; } ?>>Transfers</a>
    <a href="routes.php" <?php if ($page === 'routes.php') { echo 'class="active"'; } ?>>Routes</a>
    <?php if ($mode === 'admin') { ?>
        <a href="admin.php" <?php if ($page === 'admin.php') { echo 'class="active"'; } ?>>Admin</a>
    <?php } ?>
</nav>
