--creating the tables
CREATE TABLE Hospital(
hospitalId INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
hospitalName VARCHAR(100) NOT NULL,
country VARCHAR(50) NOT NULL,
city VARCHAR(50) NOT NULL,
street VARCHAR(150) NOT NULL,
contactNumber VARCHAR(10) NOT NULL UNIQUE,
email VARCHAR(100) NOT NULL UNIQUE
);
/

CREATE TABLE ICU_Bed(
bedId INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
bedNumber VARCHAR(20) NOT NULL,
status VARCHAR(20) DEFAULT 'Available' NOT NULL,
hospitalId INT NOT NULL,
CHECK(status IN('Available', 'In use')),
UNIQUE (hospitalId, bedNumber),
CONSTRAINT "Hospital-ICUBed_FK" FOREIGN KEY(hospitalId) 
REFERENCES Hospital(hospitalId) ON DELETE CASCADE
);
/

CREATE TABLE Equipment(
equipmentId INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
equipmentName VARCHAR(100) NOT NULL,
status VARCHAR(20) NOT NULL,
hospitalId INT NOT NULL,
CHECK(status IN('Available', 'In use')),
UNIQUE (hospitalId, equipmentName),
CONSTRAINT "Hospital-Equipment_FK" FOREIGN KEY(hospitalId) 
REFERENCES Hospital(hospitalId) ON DELETE CASCADE
);
/

CREATE TABLE Patient(
patientId INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
Fname VARCHAR(50) NOT NULL,
Mname VARCHAR(50) NOT NULL,
Lname VARCHAR(50) NOT NULL,
dateOfBirth DATE NOT NULL,
gender CHAR(1) NOT NULL,
nationalId VARCHAR(15) NOT NULL UNIQUE,
CHECK(gender IN('M', 'F'))
);
/

CREATE TABLE Patient_Email(
patientId INT NOT NULL,
email VARCHAR(100) NOT NULL UNIQUE,
PRIMARY KEY(patientId, email),
CONSTRAINT "Patient-Email_FK" FOREIGN KEY(patientId) 
REFERENCES Patient(patientId) ON DELETE CASCADE
);
/

CREATE TABLE Patient_Phone(
patientId INT NOT NULL,
phoneNumber VARCHAR(10) NOT NULL UNIQUE,
PRIMARY KEY(patientId, phoneNumber),
CONSTRAINT "Patient-Phone_FK" FOREIGN KEY(patientId) 
REFERENCES Patient(patientId) ON DELETE CASCADE
);
/

CREATE TABLE ICU_Stay(
stayId INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
enteringDate DATE NOT NULL, 
leavingDate DATE NULL,
patientId INT NOT NULL,
bedId INT NOT NULL,
CHECK(leavingDate IS NULL OR leavingDate >= enteringDate),
CONSTRAINT "Patient-ICU_Stay_FK" FOREIGN KEY(patientId) 
REFERENCES Patient(patientId) ON DELETE CASCADE,
CONSTRAINT "ICU_Bed-ICU_Stay_FK" FOREIGN KEY(bedId) 
REFERENCES ICU_Bed(bedId) ON DELETE CASCADE
);
/

CREATE TABLE Equipment_Stay(
stayId INT NOT NULL,
equipmentId INT NOT NULL,
PRIMARY KEY(stayId, equipmentId),
CONSTRAINT "E-Equipment_Stay_FK" FOREIGN KEY(equipmentId) 
REFERENCES Equipment(equipmentId)ON DELETE CASCADE,
CONSTRAINT "S-Equipment_Stay_FK" FOREIGN KEY(stayId) 
REFERENCES ICU_Stay(stayId) ON DELETE CASCADE
);
/

CREATE TABLE Daily_Charge(
chargeId INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
chargeDate DATE NOT NULL,
amount DECIMAL(10,2) NOT NULL,
stayId INT NOT NULL,
CHECK(amount > 0),
UNIQUE (stayId, chargeDate),
CONSTRAINT "ICU_Stay-Daily_Charge_FK" FOREIGN KEY(stayId) 
REFERENCES ICU_Stay(stayId) ON DELETE CASCADE
);
/

CREATE TABLE Hospital_Transfer(
transferId INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
transferDate DATE NOT NULL,
stayId INT NOT NULL,
fromhospitalId INT NOT NULL,
tohospitalId INT NOT NULL,
CHECK(fromhospitalId != tohospitalId),
CONSTRAINT "ICU_Stay-Hospital_Transfer_FK" FOREIGN KEY(stayId) 
REFERENCES ICU_Stay(stayId) ON DELETE CASCADE,
CONSTRAINT "HospitalA-Hospital_Transfer_FK" FOREIGN KEY(fromhospitalId) 
REFERENCES Hospital(hospitalId) ON DELETE CASCADE,
CONSTRAINT "HospitalB-Hospital_Transfer_FK" FOREIGN KEY(tohospitalId) 
REFERENCES Hospital(hospitalId) ON DELETE CASCADE
);
/

--Views and materilized views
--view 1
CREATE VIEW hospitalBeds_view AS 
SELECT bedId, bedNumber, status, hospitalId
FROM ICU_BED
WHERE hospitalId = 554	
WITH CHECK OPTION;
/

--view 2
CREATE VIEW hospitalTransfers_View AS
SELECT transferId, transferDate, stayId, fromhospitalId, tohospitalId
FROM Hospital_Transfer
WHERE fromhospitalId = 554 
WITH CHECK OPTION;
/

--materilized view
CREATE MATERIALIZED VIEW hospitalCharges_MatView BUILD IMMEDIATE REFRESH COMPLETE ON COMMIT AS
SELECT h.hospitalId, h.hospitalName, SUM(d.amount)
FROM Hospital h
INNER JOIN ICU_Bed b
ON h.hospitalId = b.hospitalId
INNER JOIN ICU_Stay s
ON b.bedId = s.bedId
INNER JOIN Daily_Charge d
ON s.stayId = d.stayId
GROUP BY h.hospitalId, h.hospitalName
ORDER BY h.hospitalId ASC;
/

--procedure
CREATE OR REPLACE PROCEDURE finishStay_PRC(
stayIdParameter  IN INT,
leavingDateParameter IN DATE
) AS

enteringDateVar DATE;
leavingDateVar DATE;

BEGIN

SELECT enteringDate, leavingDate
INTO enteringDateVar, leavingDateVar
FROM ICU_Stay
WHERE stayId = stayIdParameter;

IF leavingDateVar IS NULL AND leavingDateParameter >= enteringDateVar THEN
UPDATE ICU_Stay
SET leavingDate = leavingDateParameter
WHERE stayId = stayIdParameter;
DBMS_OUTPUT.PUT_LINE('ICU Stay With Id = ' || stayIdParameter || ' Has Been Finished Successfully');

ELSE
RAISE_APPLICATION_ERROR(-20999, 'An Error Has Occured (e.g., Invalid Date, Stay Has Finished)');

END IF;

EXCEPTION
WHEN NO_DATA_FOUND THEN
DBMS_OUTPUT.PUT_LINE('ICU Stay Does Not Exist');

END finishStay_PRC;
/

--triggers
--trg 1
CREATE OR REPLACE TRIGGER bedStatus_TRG
BEFORE INSERT OR UPDATE OR DELETE ON ICU_Stay
FOR EACH ROW

DECLARE
bedStatusVar VARCHAR(20);

BEGIN

IF INSERTING THEN

IF :NEW.leavingDate IS NULL THEN

SELECT status
INTO bedStatusVar
FROM ICU_Bed
WHERE bedId = :NEW.bedId;

IF bedStatusVar = 'Available' THEN

UPDATE ICU_Bed
SET status = 'In use'
WHERE bedId = :NEW.bedId;

ELSE
RAISE_APPLICATION_ERROR(-20998, 'The Bed is Already Reserved');
END IF;
END IF;
END IF;

IF UPDATING THEN

IF :OLD.leavingDate IS NULL AND :NEW.leavingDate IS NOT NULL THEN

UPDATE ICU_Bed
SET status = 'Available'
WHERE bedId = :OLD.bedId;

ELSE
RAISE_APPLICATION_ERROR(-20997, 'ICU Stay Already Finished');

END IF;
END IF;

IF DELETING THEN
RAISE_APPLICATION_ERROR(-20996, 'You Cant Delete an ICU Stay');
END IF;

END bedStatus_TRG;
/

--trg 2
CREATE OR REPLACE TRIGGER checkTransfers_TRG
BEFORE INSERT OR UPDATE ON Hospital_Transfer
FOR EACH ROW

DECLARE
bedIdVar INT;
enteringDateVar DATE;
leavingDateVar DATE;
hospitalIdVar INT;

BEGIN

SELECT bedId, enteringDate, leavingDate
INTO bedIdVar, enteringDateVar, leavingDateVar
FROM ICU_Stay
WHERE stayId = :NEW.stayId;

SELECT hospitalId
INTO hospitalIdVar
FROM ICU_BED
WHERE bedId = bedIdVar;

IF :NEW.fromhospitalId != hospitalIdVar THEN  
RAISE_APPLICATION_ERROR(-20994, 'Source Hospital Does Not Match the ICU Stay Hospital');
END IF;

IF :NEW.transferDate < enteringDateVar THEN
RAISE_APPLICATION_ERROR(-20993, 'Transfer Date Cant be Before ICU Stay Entering Date');
END IF;

IF leavingDateVar IS NOT NULL THEN
RAISE_APPLICATION_ERROR(-20992, 'Transfer Cannot Be Completed, The ICU Stay is Already Finished');
END IF;


IF INSERTING AND leavingDateVar IS NULL THEN
UPDATE ICU_Stay 
SET leavingDate = :NEW.transferDate
WHERE stayId = :NEW.stayId;
END IF;

END checkTransfers_TRG;
/

--trg 3
CREATE OR REPLACE TRIGGER freeEquipment_TRQ
AFTER UPDATE ON ICU_Stay
FOR EACH ROW

DECLARE
CURSOR c1 IS
SELECT equipmentId
FROM Equipment_Stay
WHERE stayId = :NEW.stayId;

BEGIN

IF :OLD.leavingDate IS NULL AND :NEW.leavingDate IS NOT NULL THEN

FOR equipments IN c1 LOOP

UPDATE Equipment
SET status = 'Available'
WHERE equipmentId = equipments.equipmentId;

END LOOP;
END IF;

END freeEquipment_TRQ;
/

--trg 4
CREATE OR REPLACE TRIGGER  equipmentStatus_TRG
BEFORE INSERT ON Equipment_Stay
FOR EACH ROW

DECLARE

statusVar VARCHAR(20);
equipmentHospitalIdVar INT;
stayHospitalIdVar INT;
bedIdVar INT;
leavingDateVar DATE;

BEGIN

SELECT status, hospitalId
INTO statusVar, equipmentHospitalIdVar
FROM Equipment
WHERE equipmentId = :NEW.equipmentId;

SELECT bedId, leavingDate
INTO bedIdVar, leavingDateVar
FROM ICU_Stay
WHERE stayId = :NEW.stayId;

SELECT hospitalId
INTO stayHospitalIdVar
FROM ICU_Bed
WHERE bedId = bedIdVar;

IF leavingDateVar IS NOT NULL THEN
RAISE_APPLICATION_ERROR(-20991, 'ICU Stay Has Already Finished');
END IF;

IF equipmentHospitalIdVar != stayHospitalIdVar THEN
RAISE_APPLICATION_ERROR(-20990, 'Equipment Does Not Belong to The Stay Hospital');
END IF;

IF statusVar = 'In use' Then
RAISE_APPLICATION_ERROR(-20989, 'The Equipment is Already Reserved');

ELSE

UPDATE Equipment
SET status = 'In use'
WHERE equipmentId = :NEW.equipmentId;

END IF;

END equipmentStatus_TRG;
/

--trg 5
CREATE OR REPLACE TRIGGER protectBills_TRG
BEFORE UPDATE OR DELETE ON Daily_Charge
BEGIN
RAISE_APPLICATION_ERROR(-20988, 'Bills Records Cannot Be Deleted Or Updated');
END protectBills_TRG;
/

--package
--log table
CREATE TABLE log(
logId INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
actionType VARCHAR(50) NOT NULL,
actionDate DATE DEFAULT CURRENT_DATE NOT NULL,
details VARCHAR(100),
stayId INT NOT NULL,
CONSTRAINT "ICU_Stay-Log_FK" FOREIGN KEY(stayId) 
REFERENCES ICU_Stay(stayId) 
);
/

--package specification
CREATE OR REPLACE PACKAGE billing_PKG AS

PROCEDURE addBill_PRC(
stayIdParameter IN INT,
chargeDateParameter IN DATE,
amountParameter IN DECIMAL
);

FUNCTION getStayTotal_FUNC(
stayIdParameter IN INT
)
RETURN DECIMAL;

FUNCTION getBillCount_FUNC(
stayIdParameter IN INT
)
RETURN INT;

END billing_PKG;
/

--package body
CREATE OR REPLACE PACKAGE BODY billing_PKG AS

--prc 1
PROCEDURE addLog_PRC(
stayIdParameter IN INT,
actionTypeParameter IN VARCHAR,
detailsParameter IN VARCHAR
) IS
BEGIN

INSERT INTO log(actionType, details, stayId)
VALUES(actionTypeParameter, detailsParameter, stayIdParameter);

END addLog_PRC;

--prc 2
PROCEDURE addBill_PRC(
stayIdParameter IN INT,
chargeDateParameter IN DATE,
amountParameter IN DECIMAL
) IS

enteringDateVar DATE;
leavingDateVar DATE;

BEGIN

SELECT enteringDate, leavingDate
INTO enteringDateVar, leavingDateVar
FROM ICU_Stay
WHERE stayId = stayIdParameter;

IF chargeDateParameter < enteringDateVar THEN
RAISE_APPLICATION_ERROR(-20987, 'Bill Date Cannot Be Before The Stay Entering Date');
END IF;

IF leavingDateVar IS NOT NULL THEN
RAISE_APPLICATION_ERROR(-20986, 'Cannot Add Bill To an Ended Stay');
END IF;

INSERT INTO Daily_Charge(chargeDate, amount, stayId)
VALUES(chargeDateParameter, amountParameter, stayIdParameter);

addLog_PRC(stayIdParameter, 'Bill Added', 'Bill Amount = ' || amountParameter);
DBMS_OUTPUT.PUT_LINE('Bill Was Successfully Added For Stay With Id = ' || stayIdParameter);

COMMIT;

EXCEPTION
WHEN NO_DATA_FOUND THEN
RAISE_APPLICATION_ERROR(-20985, 'ICU Stay Does Not Exist');

END addBill_PRC;

--func 1
FUNCTION getStayTotal_FUNC(
stayIdParameter IN INT
)
RETURN DECIMAL IS

totalChargesPerStay DECIMAL(10,2);

BEGIN

SELECT SUM(amount)
INTO totalChargesPerStay
FROM Daily_Charge
WHERE stayId = stayIdParameter;

IF totalChargesPerStay IS NULL THEN
RAISE_APPLICATION_ERROR(-20984, 'The Stay Has No Charges Yet');
ELSE
RETURN totalChargesPerStay;
END IF;

END getStayTotal_FUNC;

--func 2
FUNCTION getBillCount_FUNC(
stayIdParameter IN INT
)
RETURN INT IS

billCountVar INT;
stayIdVar INT;

BEGIN

SELECT stayId
INTO stayIdVar
FROM ICU_Stay
WHERE stayId = stayIdParameter;

SELECT COUNT(*)
INTO billCountVar
FROM Daily_Charge
WHERE stayId = stayIdParameter;

RETURN billCountVar;

EXCEPTION
WHEN NO_DATA_FOUND THEN
RAISE_APPLICATION_ERROR(-20983, 'ICU Stay Does Not Exist');

END getBillCount_FUNC;

END billing_PKG;
/

