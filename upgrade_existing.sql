USE PRE_MED;

ALTER TABLE PATIENT
    ADD COLUMN IF NOT EXISTS PatientCode VARCHAR(12) NULL,
    ADD COLUMN IF NOT EXISTS AssignedDoctorID INT UNSIGNED NULL;

UPDATE PATIENT
SET PatientCode = CONCAT('PRE-', LPAD(UserID, 5, '0'))
WHERE PatientCode IS NULL OR PatientCode = '';

SET @has_patient_code_unique = (SELECT COUNT(*) FROM information_schema.statistics WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'PATIENT' AND COLUMN_NAME = 'PatientCode' AND NON_UNIQUE = 0);
SET @patient_code_unique_sql = IF(@has_patient_code_unique = 0, 'ALTER TABLE PATIENT ADD CONSTRAINT uq_patient_code_upgrade UNIQUE (PatientCode)', 'SELECT 1');
PREPARE patient_code_unique_statement FROM @patient_code_unique_sql;
EXECUTE patient_code_unique_statement;
DEALLOCATE PREPARE patient_code_unique_statement;

SET @has_patient_doctor_fk = (SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'PATIENT' AND COLUMN_NAME = 'AssignedDoctorID' AND REFERENCED_TABLE_NAME = 'DOCTOR');
SET @patient_doctor_sql = IF(@has_patient_doctor_fk = 0, 'ALTER TABLE PATIENT ADD CONSTRAINT fk_patient_doctor_upgrade FOREIGN KEY (AssignedDoctorID) REFERENCES DOCTOR(UserID) ON DELETE SET NULL', 'SELECT 1');
PREPARE patient_doctor_statement FROM @patient_doctor_sql;
EXECUTE patient_doctor_statement;
DEALLOCATE PREPARE patient_doctor_statement;

ALTER TABLE APPOINTMENT
    ADD COLUMN IF NOT EXISTS DurationMinutes SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    ADD COLUMN IF NOT EXISTS Status ENUM('Scheduled', 'Confirmed', 'Completed', 'Cancelled') NOT NULL DEFAULT 'Scheduled';

SET @has_duration_check = (SELECT COUNT(*) FROM information_schema.table_constraints WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'APPOINTMENT' AND CONSTRAINT_NAME = 'chk_appointment_duration_upgrade');
SET @duration_check_sql = IF(@has_duration_check = 0, 'ALTER TABLE APPOINTMENT ADD CONSTRAINT chk_appointment_duration_upgrade CHECK (DurationMinutes BETWEEN 15 AND 240)', 'SELECT 1');
PREPARE duration_check_statement FROM @duration_check_sql;
EXECUTE duration_check_statement;
DEALLOCATE PREPARE duration_check_statement;

CREATE TABLE IF NOT EXISTS PATIENT_DOCUMENT (
    DocumentID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    PatientID INT UNSIGNED NOT NULL,
    FileName VARCHAR(255) NOT NULL,
    StoredName VARCHAR(255) NOT NULL,
    MimeType VARCHAR(100) NOT NULL,
    UploadedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_doc_patient_upgrade FOREIGN KEY (PatientID) REFERENCES PATIENT(UserID) ON DELETE CASCADE
) ENGINE=InnoDB;

ALTER TABLE SYMPTOM_LOG
    ADD COLUMN IF NOT EXISTS SymptomName VARCHAR(80) NOT NULL DEFAULT 'General',
    ADD COLUMN IF NOT EXISTS SymptomNote TEXT NULL;

CREATE TABLE IF NOT EXISTS DIAGNOSIS (
    DiagnosisID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    VisitID INT UNSIGNED NOT NULL,
    DiagnosisText VARCHAR(500) NOT NULL,
    CreatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_diagnosis_visit_upgrade FOREIGN KEY (VisitID) REFERENCES VISIT(VisitID) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS STAFF (
    UserID INT UNSIGNED PRIMARY KEY,
    DeptID INT UNSIGNED NOT NULL,
    Title VARCHAR(100) NULL,
    CONSTRAINT fk_staff_user_upgrade FOREIGN KEY (UserID) REFERENCES `USER`(UserID) ON DELETE CASCADE,
    CONSTRAINT fk_staff_department_upgrade FOREIGN KEY (DeptID) REFERENCES DEPARTMENT(DeptID)
) ENGINE=InnoDB;

ALTER TABLE GUARDIAN
    ADD COLUMN IF NOT EXISTS GuardianUserID INT UNSIGNED NULL;

SET @has_guardian_user_fk = (SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'GUARDIAN' AND COLUMN_NAME = 'GuardianUserID' AND REFERENCED_TABLE_NAME = 'USER');
SET @guardian_user_fk_sql = IF(@has_guardian_user_fk = 0, 'ALTER TABLE GUARDIAN ADD CONSTRAINT fk_guardian_user_upgrade FOREIGN KEY (GuardianUserID) REFERENCES `USER`(UserID) ON DELETE CASCADE', 'SELECT 1');
PREPARE guardian_user_fk_statement FROM @guardian_user_fk_sql;
EXECUTE guardian_user_fk_statement;
DEALLOCATE PREPARE guardian_user_fk_statement;

SET @has_guardian_user_unique = (SELECT COUNT(*) FROM information_schema.statistics WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'GUARDIAN' AND INDEX_NAME = 'uq_guardian_user_upgrade');
SET @guardian_user_unique_sql = IF(@has_guardian_user_unique = 0, 'ALTER TABLE GUARDIAN ADD CONSTRAINT uq_guardian_user_upgrade UNIQUE (GuardianUserID)', 'SELECT 1');
PREPARE guardian_user_unique_statement FROM @guardian_user_unique_sql;
EXECUTE guardian_user_unique_statement;
DEALLOCATE PREPARE guardian_user_unique_statement;

DELIMITER $$
DROP TRIGGER IF EXISTS symptom_status_after_insert$$
CREATE TRIGGER symptom_status_after_insert
AFTER INSERT ON SYMPTOM_LOG
FOR EACH ROW
BEGIN
    UPDATE PATIENT
    SET ProfileStatus = CASE WHEN (SELECT AVG(SeverityScore) FROM SYMPTOM_LOG WHERE PatientID = NEW.PatientID AND LoggedAt >= DATE_SUB(NOW(), INTERVAL 3 DAY)) >= 7 THEN 'Requires Attention' ELSE 'Stable' END,
        RiskLevel = CASE
            WHEN DateOfBirth IS NOT NULL AND TIMESTAMPDIFF(YEAR, DateOfBirth, CURDATE()) >= 60 AND (SELECT COUNT(*) FROM SYMPTOM_LOG WHERE PatientID = NEW.PatientID AND SeverityScore > 8) >= 2 THEN 'High'
            WHEN (SELECT COUNT(*) FROM SYMPTOM_LOG WHERE PatientID = NEW.PatientID AND SeverityScore >= 7) >= 2 THEN 'Medium'
            ELSE 'Low'
        END
    WHERE UserID = NEW.PatientID;
END$$

DROP TRIGGER IF EXISTS prescription_item_after_insert$$
CREATE TRIGGER prescription_item_after_insert
AFTER INSERT ON PRESCRIPTION_ITEM
FOR EACH ROW
BEGIN
    IF (SELECT StockQuantity FROM MEDICATION WHERE MedicationID = NEW.MedicationID) < NEW.Quantity THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Insufficient medication stock';
    END IF;
    UPDATE MEDICATION
    SET StockQuantity = StockQuantity - NEW.Quantity,
        InventoryStatus = CASE WHEN StockQuantity - NEW.Quantity < 50 THEN 'Reorder Needed' ELSE 'Available' END
    WHERE MedicationID = NEW.MedicationID;
END$$

DROP TRIGGER IF EXISTS diagnosis_or_prescription_update_audit$$
CREATE TRIGGER diagnosis_or_prescription_update_audit
AFTER UPDATE ON PRESCRIPTION
FOR EACH ROW
BEGIN
    INSERT INTO AUDIT_LOG (ActionType, TableName, RecordID, OldData, NewData, UserID)
    VALUES ('UPDATE', 'PRESCRIPTION', OLD.PrescriptionID, JSON_OBJECT('VisitID', OLD.VisitID, 'PrescribedAt', OLD.PrescribedAt), JSON_OBJECT('VisitID', NEW.VisitID, 'PrescribedAt', NEW.PrescribedAt), @app_user_id);
END$$

DROP TRIGGER IF EXISTS prescription_delete_audit$$
CREATE TRIGGER prescription_delete_audit
AFTER DELETE ON PRESCRIPTION
FOR EACH ROW
BEGIN
    INSERT INTO AUDIT_LOG (ActionType, TableName, RecordID, OldData, UserID)
    VALUES ('DELETE', 'PRESCRIPTION', OLD.PrescriptionID, JSON_OBJECT('VisitID', OLD.VisitID, 'PrescribedAt', OLD.PrescribedAt), @app_user_id);
END$$

DROP TRIGGER IF EXISTS diagnosis_update_audit$$
CREATE TRIGGER diagnosis_update_audit
AFTER UPDATE ON DIAGNOSIS
FOR EACH ROW
BEGIN
    INSERT INTO AUDIT_LOG (ActionType, TableName, RecordID, OldData, NewData, UserID)
    VALUES ('UPDATE', 'DIAGNOSIS', OLD.DiagnosisID, JSON_OBJECT('DiagnosisText', OLD.DiagnosisText), JSON_OBJECT('DiagnosisText', NEW.DiagnosisText), @app_user_id);
END$$

DROP TRIGGER IF EXISTS diagnosis_delete_audit$$
CREATE TRIGGER diagnosis_delete_audit
AFTER DELETE ON DIAGNOSIS
FOR EACH ROW
BEGIN
    INSERT INTO AUDIT_LOG (ActionType, TableName, RecordID, OldData, UserID)
    VALUES ('DELETE', 'DIAGNOSIS', OLD.DiagnosisID, JSON_OBJECT('DiagnosisText', OLD.DiagnosisText), @app_user_id);
END$$
DELIMITER ;
