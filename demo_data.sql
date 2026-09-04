USE PRE_MED;


-- PreMed Rich Demo Data — covers all 9 proposal features
-- All passwords = DemoPass123!
-- bcrypt hash: $2y$10$r0ZB2fX/6NgwGTJNsdCC4Ot9hMisNCb3a7jS8teJ.CFm8JY.j7pJe
-- =========================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ── Clean previous demo data ──────────────────────────────
TRUNCATE TABLE AUDIT_LOG;
TRUNCATE TABLE INVOICE;
TRUNCATE TABLE PRESCRIPTION_ITEM;
TRUNCATE TABLE PRESCRIPTION;
TRUNCATE TABLE LAB_TEST;
TRUNCATE TABLE CONSULTATION;
TRUNCATE TABLE DIAGNOSIS;
TRUNCATE TABLE VISIT;
TRUNCATE TABLE APPOINTMENT;
TRUNCATE TABLE SYMPTOM_LOG;
TRUNCATE TABLE GUARDIAN;
TRUNCATE TABLE PATIENT_INSURANCE;
TRUNCATE TABLE PATIENT;
TRUNCATE TABLE STAFF;
TRUNCATE TABLE DOCTOR;
DELETE FROM `USER`;

SET FOREIGN_KEY_CHECKS = 1;

-- ── Reset medications to full stock ──────────────────────
UPDATE MEDICATION SET StockQuantity = 300, InventoryStatus = 'Available';

-- ── Users ──────────────────────────────────────────────────
-- Primary demo accounts (4 roles)
INSERT INTO `USER` (UserID, Name, Email, Password, Address) VALUES
(1,  'Dr. Sarah Chen',      'demo.doctor@example.com',    '$2y$10$r0ZB2fX/6NgwGTJNsdCC4Ot9hMisNCb3a7jS8teJ.CFm8JY.j7pJe', '12 Hospital Lane'),
(2,  'Dr. James Okafor',    'doctor2@example.com',         '$2y$10$r0ZB2fX/6NgwGTJNsdCC4Ot9hMisNCb3a7jS8teJ.CFm8JY.j7pJe', '34 Medical Avenue'),
(3,  'Dr. Priya Sharma',    'doctor3@example.com',         '$2y$10$r0ZB2fX/6NgwGTJNsdCC4Ot9hMisNCb3a7jS8teJ.CFm8JY.j7pJe', '56 Clinic Road'),
(4,  'Alex Rahman',         'demo.patient@example.com',   '$2y$10$r0ZB2fX/6NgwGTJNsdCC4Ot9hMisNCb3a7jS8teJ.CFm8JY.j7pJe', '78 Garden Road'),
(5,  'Maria Santos',        'patient2@example.com',        '$2y$10$r0ZB2fX/6NgwGTJNsdCC4Ot9hMisNCb3a7jS8teJ.CFm8JY.j7pJe', '90 River View'),
(6,  'Robert Khan',         'patient3@example.com',        '$2y$10$r0ZB2fX/6NgwGTJNsdCC4Ot9hMisNCb3a7jS8teJ.CFm8JY.j7pJe', '23 Hill Street'),
(7,  'Fatima Begum',        'patient4@example.com',        '$2y$10$r0ZB2fX/6NgwGTJNsdCC4Ot9hMisNCb3a7jS8teJ.CFm8JY.j7pJe', '45 Lake Road'),
(8,  'John Smith',          'patient5@example.com',        '$2y$10$r0ZB2fX/6NgwGTJNsdCC4Ot9hMisNCb3a7jS8teJ.CFm8JY.j7pJe', '67 City Centre'),
(9,  'Nurse David Lee',     'demo.staff@example.com',     '$2y$10$r0ZB2fX/6NgwGTJNsdCC4Ot9hMisNCb3a7jS8teJ.CFm8JY.j7pJe', '89 Staff Quarter'),
(10, 'Mary Begum',          'demo.guardian@example.com',  '$2y$10$r0ZB2fX/6NgwGTJNsdCC4Ot9hMisNCb3a7jS8teJ.CFm8JY.j7pJe', '45 Lake Road');

-- ── Roles ─────────────────────────────────────────────────
INSERT INTO DOCTOR (UserID, DeptID) VALUES
(1, 1),  -- Dr. Chen → Cardiology
(2, 1),  -- Dr. Okafor → Cardiology (same dept as Dr. Chen, for transfer demo)
(3, 2);  -- Dr. Sharma → Neurology

INSERT INTO STAFF (UserID, DeptID, Title) VALUES
(9, 3, 'Care Operations Coordinator');

-- ── Patients ───────────────────────────────────────────────
-- DOB set so Fatima (7) is 67 years old → enables High risk demo
INSERT INTO PATIENT (UserID, PatientCode, AssignedDoctorID, RiskLevel, ProfileStatus, BloodGroup, Gender, DateOfBirth) VALUES
(4, 'PRE-00004', 1, 'Medium', 'Requires Attention', 'O+',  'Male',   '1992-05-15'),  -- Alex: medium risk, alert status
(5, 'PRE-00005', 1, 'Low',    'Stable',             'A+',  'Female', '1990-09-22'),  -- Maria: stable
(6, 'PRE-00006', 2, 'Low',    'Stable',             'B+',  'Male',   '1995-11-08'),  -- Robert: stable
(7, 'PRE-00007', 3, 'High',   'Requires Attention', 'AB-', 'Female', '1958-03-14'),  -- Fatima: 67 y/o, high-risk, no upcoming appointment (follow-up demo)
(8, 'PRE-00008', 1, 'Low',    'Stable',             'O-',  'Male',   '1980-07-30');  -- John: stable

-- ── Guardian ──────────────────────────────────────────────
INSERT INTO GUARDIAN (PatientID, GuardianUserID, GuardianName, Phone)
VALUES (7, 10, 'Mary Begum', '+880-1711-234567');

-- ── Insurance ─────────────────────────────────────────────
INSERT INTO PATIENT_INSURANCE (PatientID, CoveragePercentage, ProviderName) VALUES
(4, 80.00, 'HealthFirst BD'),
(5, 60.00, 'MediCare Plus'),
(6, 40.00, 'National Health'),
(7, 100.00, 'Senior Care Plan'),
(8, 0.00,  'Self-pay');

-- ── Symptom Logs ──────────────────────────────────────────
-- Alex (4): 14 days of escalating then recovering symptoms → full chart + alert
INSERT INTO SYMPTOM_LOG (PatientID, SymptomName, SymptomNote, SeverityScore, LoggedAt) VALUES
(4, 'Fatigue',            'Feeling tired after work',            3, DATE_SUB(NOW(), INTERVAL 13 DAY)),
(4, 'Headache',           'Mild morning headache',               4, DATE_SUB(NOW(), INTERVAL 12 DAY)),
(4, 'Fever',              'Low grade fever 37.8°C',              5, DATE_SUB(NOW(), INTERVAL 11 DAY)),
(4, 'Cough',              'Dry cough started',                   5, DATE_SUB(NOW(), INTERVAL 10 DAY)),
(4, 'Fever',              'Fever persisting, 38.2°C',            6, DATE_SUB(NOW(), INTERVAL 9 DAY)),
(4, 'Shortness of Breath','Mild breathlessness on exertion',     6, DATE_SUB(NOW(), INTERVAL 8 DAY)),
(4, 'Chest Pain',         'Sharp pain when breathing deeply',    8, DATE_SUB(NOW(), INTERVAL 7 DAY)),
(4, 'Chest Pain',         'Chest pain worse, hospital visit',    9, DATE_SUB(NOW(), INTERVAL 6 DAY)),
(4, 'Chest Pain',         'Slight improvement after antibiotics',8, DATE_SUB(NOW(), INTERVAL 5 DAY)),
(4, 'Fever',              'Fever returned slightly',             7, DATE_SUB(NOW(), INTERVAL 4 DAY)),
(4, 'Cough',              'Productive cough, yellow sputum',     7, DATE_SUB(NOW(), INTERVAL 3 DAY)),
(4, 'Fatigue',            'Very tired, recovering slowly',       7, DATE_SUB(NOW(), INTERVAL 2 DAY)),
(4, 'Shortness of Breath','Breathing better today',              5, DATE_SUB(NOW(), INTERVAL 1 DAY)),
(4, 'Fatigue',            'Still recovering, less pain',         6, NOW()),

-- Maria (5): moderate headache pattern → medium risk demo
(5, 'Headache',  'Tension headache',       4, DATE_SUB(NOW(), INTERVAL 7 DAY)),
(5, 'Nausea',    'Morning nausea',         4, DATE_SUB(NOW(), INTERVAL 6 DAY)),
(5, 'Headache',  'Migraine episode',       7, DATE_SUB(NOW(), INTERVAL 5 DAY)),
(5, 'Blurred Vision','Visual aura before migraine', 7, DATE_SUB(NOW(), INTERVAL 5 DAY)),
(5, 'Headache',  'Still throbbing',        6, DATE_SUB(NOW(), INTERVAL 4 DAY)),
(5, 'Nausea',    'Nausea with headache',   5, DATE_SUB(NOW(), INTERVAL 3 DAY)),
(5, 'Headache',  'Improving with meds',    3, DATE_SUB(NOW(), INTERVAL 1 DAY)),

-- Fatima (7): HIGH RISK — age 67, severity > 8 in last 7 days, NO upcoming appointment (follow-up list demo)
(7, 'Chest Pain',   'Severe squeezing chest pain, radiating to arm', 9, DATE_SUB(NOW(), INTERVAL 6 DAY)),
(7, 'Palpitations', 'Racing heart, felt dizzy',                      9, DATE_SUB(NOW(), INTERVAL 5 DAY)),
(7, 'Shortness of Breath','Cannot climb a single flight of stairs',  8, DATE_SUB(NOW(), INTERVAL 4 DAY)),
(7, 'Dizziness',    'Dizzy spells when standing',                    8, DATE_SUB(NOW(), INTERVAL 3 DAY)),
(7, 'Chest Pain',   'Severe again, called for help',                 9, DATE_SUB(NOW(), INTERVAL 2 DAY)),
(7, 'Fatigue',      'Exhausted, cannot do daily activities',         8, DATE_SUB(NOW(), INTERVAL 1 DAY)),

-- Robert (6) and John (8): low severity logs
(6, 'Stomach Pain', 'After heavy meals',     4, DATE_SUB(NOW(), INTERVAL 3 DAY)),
(6, 'Nausea',       'Morning nausea',        3, DATE_SUB(NOW(), INTERVAL 1 DAY)),
(8, 'Body Ache',    'After exercise',        3, DATE_SUB(NOW(), INTERVAL 2 DAY)),
(8, 'Fatigue',      'Long work day',         2, DATE_SUB(NOW(), INTERVAL 1 DAY));

-- ── Update patient statuses to match their symptom history ──
UPDATE PATIENT SET ProfileStatus = 'Requires Attention', RiskLevel = 'Medium' WHERE UserID = 4;
UPDATE PATIENT SET ProfileStatus = 'Stable',             RiskLevel = 'Low'    WHERE UserID = 5;
UPDATE PATIENT SET ProfileStatus = 'Stable',             RiskLevel = 'Low'    WHERE UserID = 6;
UPDATE PATIENT SET ProfileStatus = 'Requires Attention', RiskLevel = 'High'   WHERE UserID = 7; -- age 67 + sev>8 ×2
UPDATE PATIENT SET ProfileStatus = 'Stable',             RiskLevel = 'Low'    WHERE UserID = 8;

-- ── Appointments ──────────────────────────────────────────
-- Future: Alex(4), Maria(5), Robert(6), John(8) have upcoming appts
-- Fatima(7) has NO upcoming appointment → appears in follow-up list
INSERT INTO APPOINTMENT (AppointmentDate, DurationMinutes, PatientID, DoctorID, RoomID) VALUES
(DATE_ADD(CURDATE(), INTERVAL 2 DAY) + INTERVAL '10:00' HOUR_MINUTE, 30, 4, 1, 1),  -- Alex  → Dr.Chen, Room 101
(DATE_ADD(CURDATE(), INTERVAL 4 DAY) + INTERVAL '14:00' HOUR_MINUTE, 45, 5, 1, 2),  -- Maria → Dr.Chen, Room 102
(DATE_ADD(CURDATE(), INTERVAL 6 DAY) + INTERVAL '09:00' HOUR_MINUTE, 30, 6, 2, 1),  -- Robert→ Dr.Okafor, Room 101
(DATE_ADD(CURDATE(), INTERVAL 3 DAY) + INTERVAL '11:30' HOUR_MINUTE, 60, 8, 1, 3),  -- John  → Dr.Chen, Room 201
-- Second appointment for conflict-detection demo (same doctor, different time)
(DATE_ADD(CURDATE(), INTERVAL 2 DAY) + INTERVAL '11:00' HOUR_MINUTE, 30, 5, 2, 2);  -- Maria → Dr.Okafor (already booked, conflict if someone tries 10:30)

-- ── Visits ────────────────────────────────────────────────
INSERT INTO VISIT (VisitID, AdmissionDate, PatientID) VALUES
(1, DATE_SUB(NOW(), INTERVAL 13 DAY), 4),  -- Alex: initial visit
(2, DATE_SUB(NOW(), INTERVAL 6 DAY),  4),  -- Alex: follow-up (pneumonia)
(3, DATE_SUB(NOW(), INTERVAL 5 DAY),  5),  -- Maria: migraine visit
(4, DATE_SUB(NOW(), INTERVAL 7 DAY),  7),  -- Fatima: cardiac visit
(5, DATE_SUB(NOW(), INTERVAL 3 DAY),  6),  -- Robert: gastro visit
(6, DATE_SUB(NOW(), INTERVAL 1 DAY),  8);  -- John: routine

-- ── Diagnoses ─────────────────────────────────────────────
INSERT INTO DIAGNOSIS (VisitID, DiagnosisText) VALUES
(1, 'Acute upper respiratory tract infection with bacterial component'),
(2, 'Community-acquired pneumonia, mild-to-moderate severity, right lower lobe'),
(3, 'Migraine with aura — chronic episodic pattern'),
(4, 'Hypertensive heart disease with paroxysmal atrial fibrillation'),
(5, 'Acute viral gastroenteritis'),
(6, 'Routine health check — no acute findings');

-- ── Consultations ─────────────────────────────────────────
INSERT INTO CONSULTATION (Notes, VisitID, DoctorID, Cost) VALUES
('Patient presents with 3-day fever, dry cough, fatigue. Chest auscultation: mild crackles at base. Started empirical antibiotics.', 1, 1, 600.00),
('Chest X-ray confirmed right lower lobe consolidation. Upgraded to amoxicillin-clavulanate. Oxygen saturation 96%.', 2, 1, 900.00),
('Recurrent migraine with visual aura. Trigger factors identified: sleep deprivation, screen time. Initiated triptans.', 3, 1, 700.00),
('Cardiac evaluation: irregular rhythm noted. Echo-cardiogram ordered. Started rate control. Referral to cardiologist urgent.', 4, 3, 1400.00),
('Viral gastroenteritis, likely rotavirus. Oral rehydration therapy initiated. No antibiotics needed.', 5, 2, 400.00),
('Annual health screening. BP, glucose, BMI within normal range. Advised regular exercise and diet modification.', 6, 1, 350.00);

-- ── Lab Tests ─────────────────────────────────────────────
INSERT INTO LAB_TEST (Result, VisitID, Cost) VALUES
('CBC: WBC 12.5×10⁹/L (elevated). CRP: 48 mg/L. Procalcitonin: 0.4 ng/mL. Indicates bacterial infection.', 1, 400.00),
('Chest X-ray: Consolidation in right lower lobe confirming pneumonia. Sputum culture: S. pneumoniae sensitive to amoxicillin.', 2, 700.00),
('MRI Brain: No structural abnormality. EEG: Normal interictal pattern. Consistent with migrainous aura.', 3, 2800.00),
('12-lead ECG: Irregular rhythm with variable R-R intervals. BNP: 280 pg/mL slightly elevated. ECHO pending.', 4, 950.00),
('Stool culture: No bacterial pathogen isolated. Rotavirus antigen rapid test: Positive.', 5, 500.00),
('FBC, LFT, RFT, HbA1c: All within normal limits. Fasting glucose 5.1 mmol/L.', 6, 600.00);

-- ── Prescriptions ─────────────────────────────────────────
INSERT INTO PRESCRIPTION (PrescriptionID, VisitID, PrescribedAt) VALUES
(1, 1, DATE_SUB(NOW(), INTERVAL 13 DAY)),
(2, 2, DATE_SUB(NOW(), INTERVAL 6 DAY)),
(3, 3, DATE_SUB(NOW(), INTERVAL 5 DAY)),
(4, 4, DATE_SUB(NOW(), INTERVAL 7 DAY));

-- Trigger @app_user_id for audit trail on prescription items
SET @app_user_id = 1;

-- Insert prescription items (triggers inventory deduction + reorder check)
INSERT INTO PRESCRIPTION_ITEM (PrescriptionID, MedicationID, Dosage, Quantity) VALUES
(1, 1, 'Amoxicillin 500mg — three times daily for 7 days',          21),
(2, 1, 'Amoxicillin 875mg — twice daily for 10 days',               20),
(3, 2, 'Metformin 500mg — once daily with meals',                   30),
(4, 3, 'Lisinopril 10mg — once daily, morning',                     30);

-- ── Invoices ──────────────────────────────────────────────
-- Visit 1 (Alex): Consult $600 + Lab $400 + Rx(21×$12.50=$262.50) = $1262.50. Insurance 80% → $252.50 out-of-pocket
INSERT INTO INVOICE (Date, OutOfPocket, VisitID) VALUES
(DATE_SUB(NOW(), INTERVAL 12 DAY), 252.50, 1),
-- Visit 3 (Maria): Consult $700 + Lab $2800 + Rx(30×$8=$240) = $3740. Insurance 60% → $1496.00
(DATE_SUB(NOW(), INTERVAL 4 DAY), 1496.00, 3);

-- ── Audit Log — pre-populated entries ─────────────────────
-- These simulate the triggers having already fired on past edits
SET @app_user_id = 1;
INSERT INTO AUDIT_LOG (ActionType, TableName, RecordID, OldData, NewData, Timestamp, UserID) VALUES
('UPDATE', 'DIAGNOSIS',     '1', '{"DiagnosisText":"Upper respiratory infection"}',             '{"DiagnosisText":"Acute upper respiratory tract infection with bacterial component"}', DATE_SUB(NOW(), INTERVAL 12 DAY), 1),
('UPDATE', 'DIAGNOSIS',     '3', '{"DiagnosisText":"Migraine"}',                                '{"DiagnosisText":"Migraine with aura — chronic episodic pattern"}',                  DATE_SUB(NOW(), INTERVAL 4 DAY),  1),
('UPDATE', 'PRESCRIPTION',  '1', '{"VisitID":1}',                                              '{"VisitID":1}',                                                                     DATE_SUB(NOW(), INTERVAL 11 DAY), 1),
('DELETE', 'DIAGNOSIS',     '99','{"DiagnosisText":"Incorrect entry — viral fever (deleted)"}', NULL,                                                                                DATE_SUB(NOW(), INTERVAL 10 DAY), 1),
('UPDATE', 'DIAGNOSIS',     '4', '{"DiagnosisText":"Heart disease"}',                           '{"DiagnosisText":"Hypertensive heart disease with paroxysmal atrial fibrillation"}', DATE_SUB(NOW(), INTERVAL 6 DAY), 3),
('DELETE', 'PRESCRIPTION',  '99','{"VisitID":99}',                                             NULL,                                                                                DATE_SUB(NOW(), INTERVAL 5 DAY),  1);

-- ── Summary ───────────────────────────────────────────────
SELECT 'Demo data loaded successfully!' AS Status;
SELECT
    (SELECT COUNT(*) FROM `USER`)            AS Users,
    (SELECT COUNT(*) FROM PATIENT)           AS Patients,
    (SELECT COUNT(*) FROM DOCTOR)            AS Doctors,
    (SELECT COUNT(*) FROM APPOINTMENT)       AS Appointments,
    (SELECT COUNT(*) FROM SYMPTOM_LOG)       AS SymptomLogs,
    (SELECT COUNT(*) FROM VISIT)             AS Visits,
    (SELECT COUNT(*) FROM DIAGNOSIS)         AS Diagnoses,
    (SELECT COUNT(*) FROM PRESCRIPTION_ITEM) AS PrescriptionItems,
    (SELECT COUNT(*) FROM INVOICE)           AS Invoices,
    (SELECT COUNT(*) FROM AUDIT_LOG)         AS AuditEntries;

SELECT
    'demo.patient@example.com / DemoPass123!'  AS Patient,
    'demo.doctor@example.com / DemoPass123!'   AS Doctor,
    'demo.staff@example.com / DemoPass123!'    AS Staff,
    'demo.guardian@example.com / DemoPass123!' AS Guardian;
