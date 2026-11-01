<?php
require 'includes/connect.php';
$title = 'Patients';
$patients = [];
$patient = [];
$emails = [];
$phones = [];
$patientId = 0;
$search = '';
$offset = 0;

try {
    $patientId = id_value($_GET, 'patientId');
    $search = text_value($_GET, 'q', 100, false);
    $offset = id_value($_GET, 'offset');
    if ($oracleConnection && isset($_POST['savePatient'])) {
        check_post();
        $patientId = id_value($_POST, 'patientId');
        $firstName = text_value($_POST, 'firstName', 50);
        $middleName = text_value($_POST, 'middleName', 50);
        $lastName = text_value($_POST, 'lastName', 50);
        $birthDate = date_value($_POST, 'birthDate');
        $nationalId = text_value($_POST, 'nationalId', 15);
        $gender = text_value($_POST, 'gender', 1);
        if (!in_array($gender, ['M', 'F'], true)) {
            throw new Exception('Select a gender.');
        }
        if ($patientId) {
            $sql = "UPDATE Patient SET Fname = :firstName, Mname = :middleName, Lname = :lastName,
                    dateOfBirth = TO_DATE(:birthDate, 'YYYY-MM-DD'), nationalId = :nationalId, gender = :gender
                WHERE patientId = :patientId";
        } else {
            $sql = "INSERT INTO Patient (Fname, Mname, Lname, dateOfBirth, nationalId, gender)
                    VALUES (:firstName, :middleName, :lastName, TO_DATE(:birthDate, 'YYYY-MM-DD'), :nationalId, :gender)

                RETURNING patientId INTO :patientId";
        }
        $statement = oci_parse($oracleConnection, $sql);
        oci_bind_by_name($statement, ':firstName', $firstName);
        oci_bind_by_name($statement, ':middleName', $middleName);
        oci_bind_by_name($statement, ':lastName', $lastName);
        oci_bind_by_name($statement, ':birthDate', $birthDate);
        oci_bind_by_name($statement, ':nationalId', $nationalId);
        oci_bind_by_name($statement, ':gender', $gender);
        oci_bind_by_name($statement, ':patientId', $patientId, -1, SQLT_INT);
        if (!oci_execute($statement, OCI_NO_AUTO_COMMIT)) {
            throw new Exception(oracle_error($statement));
        }
        if (oci_num_rows($statement) !== 1 || !oci_commit($oracleConnection)) {
            throw new Exception('Patient could not be saved.');
        }
        $_SESSION['message'] = 'Patient saved.';
        header('Location: patients.php?patientId=' . $patientId, true, 303);
        exit;
    }
    if ($oracleConnection && (isset($_POST['saveContact']) || isset($_POST['removeContact']))) {
        check_post();
        $patientId = id_value($_POST, 'patientId');
        $kind = text_value($_POST, 'kind', 5);
        $oldValue = text_value($_POST, 'oldValue', 100, false);
        if ($kind === 'email') {
            $table = 'Patient_Email';
            $column = 'email';
            $value = text_value($_POST, 'value', 100);
            if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Enter a valid email.');
            }
        } elseif ($kind === 'phone') {
            $table = 'Patient_Phone';
            $column = 'phoneNumber';
            $value = text_value($_POST, 'value', 10);
        } else {
            throw new Exception('Invalid contact.');
        }
        if (isset($_POST['removeContact'])) {
            $sql = "DELETE FROM $table WHERE patientId = :patientId AND $column = :value";
        } elseif ($oldValue !== '') {
            $sql = "UPDATE $table SET $column = :value WHERE patientId = :patientId AND $column = :oldValue";
        } else {
            $sql = "INSERT INTO $table (patientId, $column) VALUES (:patientId, :value)";
        }
        $statement = oci_parse($oracleConnection, $sql);
        oci_bind_by_name($statement, ':patientId', $patientId);
        oci_bind_by_name($statement, ':value', $value);
        if (!isset($_POST['removeContact']) && $oldValue !== '') {
            oci_bind_by_name($statement, ':oldValue', $oldValue);
        }
        if (!oci_execute($statement, OCI_NO_AUTO_COMMIT)) {
            throw new Exception(oracle_error($statement));
        }
        if (oci_num_rows($statement) !== 1 || !oci_commit($oracleConnection)) {
            throw new Exception('Contact changed. Reload the page.');
        }
        $_SESSION['message'] = 'Contact saved.';
        header('Location: patients.php?patientId=' . $patientId, true, 303);
        exit;
    }
    if ($oracleConnection) {
        if ($patientId) {
            $sql = "SELECT patientId, Fname, Mname, Lname, gender, nationalId,
                           TO_CHAR(dateOfBirth, 'YYYY-MM-DD') AS birthDate
                FROM Patient
                WHERE patientId = :patientId";
            $statement = oci_parse($oracleConnection, $sql);
            oci_bind_by_name($statement, ':patientId', $patientId);
            if (!oci_execute($statement, OCI_NO_AUTO_COMMIT)) {
                throw new Exception(oracle_error($statement));
            }
            $patient = oci_fetch_array($statement, OCI_ASSOC);
            if (!$patient) {
                throw new Exception('Patient not found.');
            }
            $sql = "SELECT 'email' AS kind, email AS value
                FROM Patient_Email
                WHERE patientId = :patientId
                    UNION ALL SELECT 'phone', phoneNumber
                FROM Patient_Phone
                WHERE patientId = :patientId";
            $statement = oci_parse($oracleConnection, $sql);
            oci_bind_by_name($statement, ':patientId', $patientId);
            if (!oci_execute($statement, OCI_NO_AUTO_COMMIT)) {
                throw new Exception(oracle_error($statement));
            }
            while ($row = oci_fetch_array($statement, OCI_ASSOC)) {
                if ($row['KIND'] === 'email') {
                    $emails[] = $row['VALUE'];
                } else {
                    $phones[] = $row['VALUE'];
                }
            }
        } else {
            $sql = "SELECT patientId, Fname, Mname, Lname, nationalId, gender
                FROM Patient
                WHERE TO_CHAR(patientId) LIKE :search
                    OR LOWER(Fname || ' ' || Mname || ' ' || Lname) LIKE :search
                    OR nationalId LIKE :search
                ORDER BY patientId DESC OFFSET :offset ROWS FETCH NEXT 100 ROWS ONLY";
            $statement = oci_parse($oracleConnection, $sql);
            $searchValue = '%' . strtolower($search) . '%';
            oci_bind_by_name($statement, ':search', $searchValue);
            oci_bind_by_name($statement, ':offset', $offset, -1, SQLT_INT);
            if (!oci_execute($statement, OCI_NO_AUTO_COMMIT)) {
                throw new Exception(oracle_error($statement));
            }
            while ($row = oci_fetch_array($statement, OCI_ASSOC)) {
                $patients[] = $row;
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
<h1>Patients</h1>
<?php if ($oracleConnection) { ?>
    <?php if (!$patientId && !isset($_GET['new'])) { ?>
        <form method="get" class="filters">
            <label>
                Patient ID, name or national ID
                <input name="q" value="<?php echo e($search); ?>">
            </label>
            <button>Search</button>
            <a href="patients.php">Clear Search</a>
            <a class="button secondary" href="patients.php?new=1">Add Patient</a>
        </form>
        <section class="panel">
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>National ID</th>
                            <th>Gender</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($patients as $row) { ?>
                            <tr>
                                <td>#<?php echo e($row['PATIENTID']); ?></td>
                                <td><?php echo e($row['FNAME'] . ' ' . $row['MNAME'] . ' ' . $row['LNAME']); ?></td>
                                <td><?php echo e($row['NATIONALID']); ?></td>
                                <td><?php echo e($row['GENDER']); ?></td>
                                <td><a href="patients.php?patientId=<?php echo e($row['PATIENTID']); ?>">Open</a></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
            <?php if (!$patients) { ?>
                <p>No patients found.</p>
            <?php } ?>
        </section>
        <div class="links">
            <?php if ($offset > 0) { ?>
                <a href="patients.php?offset=<?php echo max(0, $offset - 100); ?>&amp;q=<?php echo urlencode($search); ?>">Previous</a>
            <?php } ?>
            <?php if (count($patients) === 100) { ?>
                <a href="patients.php?offset=<?php echo $offset + 100; ?>&amp;q=<?php echo urlencode($search); ?>">Next</a>
            <?php } ?>
        </div>
    <?php } elseif (!$patientId || $patient) { ?>
        <section class="panel narrow">
            <h2><?php echo $patientId ? 'Edit Patient #' . e($patientId) : 'Add Patient'; ?></h2>
            <form method="post">
                <?php csrf_fields(); ?>
                <input type="hidden" name="patientId" value="<?php echo e($patientId); ?>">
                <div class="form-grid">
                    <label>
                        First Name
                        <input name="firstName" maxlength="50" value="<?php echo e($patient['FNAME'] ?? ''); ?>" required>
                    </label>
                    <label>
                        Middle Name
                        <input name="middleName" maxlength="50" value="<?php echo e($patient['MNAME'] ?? ''); ?>" required>
                    </label>
                    <label>
                        Last Name
                        <input name="lastName" maxlength="50" value="<?php echo e($patient['LNAME'] ?? ''); ?>" required>
                    </label>
                    <label>
                        Date of Birth
                        <input type="date" name="birthDate" max="<?php echo date('Y-m-d'); ?>" value="<?php echo e($patient['BIRTHDATE'] ?? ''); ?>" required>
                    </label>
                    <label>
                        National ID
                        <input name="nationalId" maxlength="15" value="<?php echo e($patient['NATIONALID'] ?? ''); ?>" required>
                    </label>
                    <label>
                        Gender
                        <select name="gender" required>
                            <option value="">Select</option>
                            <option value="M" <?php if (($patient['GENDER'] ?? '') === 'M') { echo 'selected'; } ?>>Male</option>
                            <option value="F" <?php if (($patient['GENDER'] ?? '') === 'F') { echo 'selected'; } ?>>Female</option>
                        </select>
                    </label>
                </div>
                <button name="savePatient">Save</button>
                <a class="button secondary" href="patients.php">Cancel</a>
            </form>
        </section>
        <?php if ($patientId && $patient) { ?>
            <div class="links">
                <a href="stays.php?new=1&amp;patientId=<?php echo e($patientId); ?>">Create Stay</a>
                <a href="stays.php?patientId=<?php echo e($patientId); ?>">ICU Stays</a>
            </div>
            <div class="two-column">
                <?php foreach (['email' => $emails, 'phone' => $phones] as $kind => $contacts) { ?>
                    <section class="panel">
                        <h2><?php echo $kind === 'email' ? 'Emails' : 'Phone Numbers'; ?></h2>
                        <?php $contacts[] = ''; ?>
                        <?php foreach ($contacts as $value) { ?>
                            <form method="post" class="contact-row">
                                <?php csrf_fields(); ?>
                                <input type="hidden" name="patientId" value="<?php echo e($patientId); ?>">
                                <input type="hidden" name="kind" value="<?php echo e($kind); ?>">
                                <input type="hidden" name="oldValue" value="<?php echo e($value); ?>">
                                <label>
                                    <?php echo $kind === 'email' ? 'Email' : 'Phone'; ?>
                                    <input name="value" type="<?php echo $kind === 'email' ? 'email' : 'tel'; ?>" maxlength="<?php echo $kind === 'email' ? 100 : 10; ?>" value="<?php echo e($value); ?>" required>
                                </label>
                                <button name="saveContact"><?php echo $value === '' ? 'Add' : 'Save'; ?></button>
                                <?php if ($value !== '') { ?>
                                    <button class="secondary" name="removeContact">Remove</button>
                                <?php } ?>
                            </form>
                        <?php } ?>
                    </section>
                <?php } ?>
            </div>
        <?php } ?>
    <?php } ?>
<?php } ?>
<?php require 'includes/footer.php'; ?>
