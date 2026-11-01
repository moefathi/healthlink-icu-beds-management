<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>
            <?php echo e($title); ?>
            | HealthLink
        </title>
        <link rel="stylesheet" href="CSS/styles.css">
        <script src="JS/app.js" defer>
        </script>
    </head>
    <body>
        <header class="site-header">
            <div class="header-content">
                <a class="brand" href="index.php">HealthLink</a>
                <form method="post" action="<?php echo e($page); ?>" class="mode-switch">
                    <?php csrf_fields(); ?>
                    <button name="changeMode" value="staff" class="<?php if ($mode === 'staff') { echo 'selected'; } ?>">Staff</button>
                    <button name="changeMode" value="admin" class="<?php if ($mode === 'admin') { echo 'selected'; } ?>">Admin</button>
                </form>
            </div>
            <?php require __DIR__ . '/sidebar.php'; ?>
        </header>
        <div class="workspace">
            <?php if ($mode === 'staff') { ?>
                <div class="hospital-bar">
                    <form method="post" action="<?php echo e($page); ?>" class="hospital-selector">
                        <?php csrf_fields(); ?>
                        <label for="staffHospital">
                            Staff Hospital
                        </label>
                        <select id="staffHospital" name="hospitalId" required>
                            <?php if (!$hospitalId) { ?>
                                <option value="">Select Hospital</option>
                            <?php } ?>
                            <?php foreach ($staffHospitals as $hospital) { ?>
                                <option value="<?php echo e($hospital['HOSPITALID']); ?>" <?php if ((int) $hospital['HOSPITALID'] === $hospitalId) { echo 'selected'; } ?>>#<?php echo e($hospital['HOSPITALID']); ?> — <?php echo e($hospital['HOSPITALNAME']); ?></option>
                            <?php } ?>
                        </select>
                        <button name="selectHospital">Switch</button>
                    </form>
                </div>
            <?php } ?>
            <main>
                <?php if ($error !== '') { ?>
                    <p class="notice error"><?php echo e($error); ?></p>
                <?php } ?>
                <?php if ($message !== '') { ?>
                    <p class="notice success"><?php echo e($message); ?></p>
                <?php } ?>
                <?php if ($mode === 'staff' && $missingHospitals) { ?>
                    <p class="notice">Hospital IDs not found: <?php echo e(implode(', ', $missingHospitals)); ?>.</p>
                <?php } ?>
                <?php if (!$canOperate && $page !== 'patients.php' && $mode === 'staff') { ?>
                    <p class="notice">Select Hospital to continue.</p>
                <?php } ?>
