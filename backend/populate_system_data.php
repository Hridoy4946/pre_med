<?php
/**
 * PreMed Rich System Data Population Script
 * Generates comprehensive data across all 4 roles and 9 features:
 * - 5+ rows in all patient tables (Appointments, Symptoms, Visits, Prescriptions, Documents) to test 5-row fixed scroll boxes
 * - Real sample documents in uploads/ for testing View, Download, and Delete
 * - High, Medium, Low risk patient profiles
 * - Staff inventory with low stock (<50) alerts and healthy stock
 * - Today's operations pulse metrics (booked rooms, free rooms, lab tests, reports)
 * - Saved invoices with settled and pending balances for Print Bill testing
 * - Lab tests and diagnostic reports for Print Report testing and delivery toggling
 * - Pre-populated audit log entries
 */

require_once __DIR__ . '/db.php';

echo "========================================================\n";
echo "POPULATING PREMED SYSTEM DATA FOR FULL SYSTEM TESTING\n";
echo "========================================================\n\n";

try {
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

    // 1. Ensure Departments
    echo "1. Populating Departments...\n";
    $depts = [
        [1, 'Cardiology'],
        [2, 'Neurology'],
        [3, 'General Medicine'],
        [4, 'Pediatrics'],
        [5, 'Orthopedics & Trauma']
    ];
    foreach ($depts as $d) {
        $stmt = $pdo->prepare("INSERT INTO DEPARTMENT (DeptID, DeptName) VALUES (?, ?) ON DUPLICATE KEY UPDATE DeptName = VALUES(DeptName)");
        $stmt->execute($d);
    }

    // 2. Ensure Clinic Rooms
    echo "2. Populating Clinic Rooms...\n";
    $rooms = [
        [1, '101'],
        [2, '102'],
        [3, '201'],
        [4, '202'],
        [5, '301'],
        [6, '302'],
        [7, 'ICU-1']
    ];
    foreach ($rooms as $r) {
        $stmt = $pdo->prepare("INSERT INTO CLINIC_ROOM (RoomID, RoomNumber) VALUES (?, ?) ON DUPLICATE KEY UPDATE RoomNumber = VALUES(RoomNumber)");
        $stmt->execute($r);
    }

    // 3. Ensure Core Users (All passwords = DemoPass123!)
    echo "3. Populating Core Users (Password: DemoPass123!)...\n";
    $pwdHash = '$2y$10$r0ZB2fX/6NgwGTJNsdCC4Ot9hMisNCb3a7jS8teJ.CFm8JY.j7pJe';
    $users = [
        [1,  'Dr. Sarah Chen',      'demo.doctor@example.com',   $pwdHash, '12 Hospital Lane'],
        [2,  'Dr. James Okafor',    'doctor2@example.com',        $pwdHash, '34 Medical Avenue'],
        [3,  'Dr. Priya Sharma',    'doctor3@example.com',        $pwdHash, '56 Clinic Road'],
        [4,  'Alex Rahman',         'demo.patient@example.com',  $pwdHash, '78 Garden Road'],
        [5,  'Maria Santos',        'patient2@example.com',       $pwdHash, '90 River View'],
        [6,  'Robert Khan',         'patient3@example.com',       $pwdHash, '23 Hill Street'],
        [7,  'Fatima Begum',        'patient4@example.com',       $pwdHash, '45 Lake Road'],
        [8,  'John Smith',          'patient5@example.com',       $pwdHash, '67 City Centre'],
        [9,  'Nurse David Lee',     'demo.staff@example.com',    $pwdHash, '89 Staff Quarter'],
        [10, 'Mary Begum',          'demo.guardian@example.com', $pwdHash, '45 Lake Road'],
        [11, 'Dr. Aris Thorne',     'doctor4@example.com',        $pwdHash, '101 Wellness Way'],
    ];
    foreach ($users as $u) {
        $stmt = $pdo->prepare("INSERT INTO `USER` (UserID, Name, Email, Password, Address) VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE Name = VALUES(Name), Email = VALUES(Email), Password = VALUES(Password), Address = VALUES(Address)");
        $stmt->execute($u);
    }

    // 4. Ensure Roles
    echo "4. Assigning Roles...\n";
    // Doctors
    $doctors = [
        [1, 1], // Dr. Chen -> Cardiology
        [2, 1], // Dr. Okafor -> Cardiology
        [3, 2], // Dr. Sharma -> Neurology
        [11, 3] // Dr. Thorne -> General Medicine
    ];
    foreach ($doctors as $doc) {
        $pdo->prepare("INSERT INTO DOCTOR (UserID, DeptID) VALUES (?, ?) ON DUPLICATE KEY UPDATE DeptID = VALUES(DeptID)")->execute($doc);
    }

    // Staff
    $pdo->prepare("INSERT INTO STAFF (UserID, DeptID, Title) VALUES (9, 3, 'Care Operations Coordinator') ON DUPLICATE KEY UPDATE Title = VALUES(Title)")->execute();

    // Patients
    $patients = [
        [4, 'PRE-00004', 1, 'Medium', 'Requires Attention', 'O+',  'Male',   '1992-05-15'],
        [5, 'PRE-00005', 2, 'Low',    'Stable',             'A+',  'Female', '1990-09-22'],
        [6, 'PRE-00006', 2, 'Low',    'Stable',             'B+',  'Male',   '1995-11-08'],
        [7, 'PRE-00007', 3, 'High',   'Requires Attention', 'AB-', 'Female', '1958-03-14'],
        [8, 'PRE-00008', 2, 'Low',    'Stable',             'O-',  'Male',   '1980-07-30'],
    ];
    foreach ($patients as $p) {
        $stmt = $pdo->prepare("INSERT INTO PATIENT (UserID, PatientCode, AssignedDoctorID, RiskLevel, ProfileStatus, BloodGroup, Gender, DateOfBirth)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE PatientCode = VALUES(PatientCode), AssignedDoctorID = VALUES(AssignedDoctorID), RiskLevel = VALUES(RiskLevel), ProfileStatus = VALUES(ProfileStatus), BloodGroup = VALUES(BloodGroup), Gender = VALUES(Gender), DateOfBirth = VALUES(DateOfBirth)");
        $stmt->execute($p);
    }

    // Guardian
    $pdo->prepare("INSERT INTO GUARDIAN (PatientID, GuardianUserID, GuardianName, Phone) VALUES (7, 10, 'Mary Begum', '+880-1711-234567')
        ON DUPLICATE KEY UPDATE GuardianName = VALUES(GuardianName), Phone = VALUES(Phone)")->execute();

    // Insurance
    $insurances = [
        [4, 80.00, 'HealthFirst BD'],
        [5, 60.00, 'MediCare Plus'],
        [6, 40.00, 'National Health'],
        [7, 100.00, 'Senior Care Plan (Full Coverage)'],
        [8, 0.00,  'Self-pay Standard'],
    ];
    foreach ($insurances as $ins) {
        $pdo->prepare("INSERT INTO PATIENT_INSURANCE (PatientID, CoveragePercentage, ProviderName) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE CoveragePercentage = VALUES(CoveragePercentage), ProviderName = VALUES(ProviderName)")->execute($ins);
    }

    // 5. Expand Pharmacy & Supplies Inventory (MEDICATION)
    echo "5. Populating Comprehensive Medications & Supplies Inventory...\n";
    $meds = [
        [1,  'Amoxicillin 500mg',           220, 12.50, 'Available'],
        [2,  'Metformin 500mg',             180, 8.00,  'Available'],
        [3,  'Lisinopril 10mg',             140, 10.00, 'Available'],
        [4,  'Azithromycin 250mg',          32,  18.00, 'Reorder Needed'],  // Low stock
        [5,  'Atorvastatin 20mg',           150, 15.00, 'Available'],
        [6,  'Omeprazole 20mg',             25,  6.50,  'Reorder Needed'],  // Low stock
        [7,  'Paracetamol 500mg',           450, 3.00,  'Available'],
        [8,  'Ibuprofen 400mg',             210, 5.00,  'Available'],
        [9,  'Salbutamol Inhaler 100mcg',   18,  22.00, 'Reorder Needed'],  // Low stock
        [10, 'Amlodipine 5mg',              160, 9.00,  'Available'],
        [11, 'EpiPen Auto-Injector 0.3mg',  12,  95.00, 'Reorder Needed'],  // Low stock
        [12, 'Sterile Gauze Pads 10x10cm',  300, 2.50,  'Available'],
        [13, 'Surgical Latex Gloves (Box)', 85,  14.00, 'Available'],
        [14, 'IV Normal Saline 500mL',      40,  7.50,  'Reorder Needed'],  // Low stock
        [15, 'Disposable Syringes 5mL',     500, 1.20,  'Available'],
        [16, 'N95 Protective Face Masks',   200, 4.00,  'Available'],
    ];
    foreach ($meds as $m) {
        $stmt = $pdo->prepare("INSERT INTO MEDICATION (MedicationID, MedicationName, StockQuantity, UnitCost, InventoryStatus) VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE MedicationName = VALUES(MedicationName), StockQuantity = VALUES(StockQuantity), UnitCost = VALUES(UnitCost), InventoryStatus = VALUES(InventoryStatus)");
        $stmt->execute($m);
    }

    // 6. Populate Appointments (Ensure 6+ rows for Alex to test 5-row fixed box scrollbar)
    echo "6. Populating Appointments Ledger (Enabling 5-Row Fixed Scroll Box for Alex)...\n";
    $pdo->exec("DELETE FROM APPOINTMENT");
    $appts = [
        // Alex Rahman (4) - 7 appointments across past & future
        ['2026-08-10 10:00:00', 30, 'Completed', 4, 1, 1],
        ['2026-08-18 11:30:00', 45, 'Completed', 4, 1, 2],
        ['2026-08-25 09:30:00', 30, 'Completed', 4, 1, 1],
        ['2026-09-01 14:00:00', 30, 'Completed', 4, 1, 3],
        ['2026-09-04 10:30:00', 30, 'Confirmed', 4, 1, 1],  // TODAY appointment
        ['2026-09-06 15:00:00', 45, 'Scheduled', 4, 1, 2],
        ['2026-09-12 11:00:00', 30, 'Scheduled', 4, 1, 1],

        // Maria Santos (5)
        ['2026-08-28 14:00:00', 45, 'Completed', 5, 2, 2],
        ['2026-09-04 14:30:00', 30, 'Confirmed', 5, 2, 2],  // TODAY appointment
        ['2026-09-08 10:00:00', 30, 'Scheduled', 5, 2, 1],

        // Robert Khan (6)
        ['2026-08-30 09:00:00', 30, 'Completed', 6, 2, 1],
        ['2026-09-05 16:00:00', 30, 'Scheduled', 6, 2, 4],

        // John Smith (8)
        ['2026-09-02 11:00:00', 60, 'Completed', 8, 2, 3],
        ['2026-09-04 16:00:00', 45, 'Scheduled', 8, 2, 3],  // TODAY appointment
        ['2026-09-10 10:00:00', 30, 'Scheduled', 8, 2, 1],

        // Fatima Begum (7) - no future appointments to test follow-up alert!
        ['2026-08-22 10:00:00', 45, 'Completed', 7, 3, 2],
        ['2026-08-29 11:00:00', 30, 'Completed', 7, 3, 2],
    ];
    $insAppt = $pdo->prepare("INSERT INTO APPOINTMENT (AppointmentDate, DurationMinutes, Status, PatientID, DoctorID, RoomID) VALUES (?, ?, ?, ?, ?, ?)");
    foreach ($appts as $a) {
        $insAppt->execute($a);
    }

    // 7. Populate Visits, Consultations, Lab Tests, Diagnoses, Prescriptions
    echo "7. Populating Clinical Records, Visits & Results (Enabling 5-Row Fixed Scroll Box for Alex)...\n";
    $pdo->exec("DELETE FROM INVOICE");
    $pdo->exec("DELETE FROM PRESCRIPTION_ITEM");
    $pdo->exec("DELETE FROM PRESCRIPTION");
    $pdo->exec("DELETE FROM LAB_TEST");
    $pdo->exec("DELETE FROM CONSULTATION");
    $pdo->exec("DELETE FROM DIAGNOSIS");
    $pdo->exec("DELETE FROM VISIT");

    // Visits: 7 visits for Alex Rahman (Patient 4), plus other patients
    $visits = [
        // Alex Rahman (4) - Visits 1 through 7
        [1,  '2026-08-10 10:00:00', 4],
        [2,  '2026-08-18 11:30:00', 4],
        [3,  '2026-08-25 09:30:00', 4],
        [4,  '2026-08-28 15:00:00', 4],
        [5,  '2026-09-01 14:00:00', 4],
        [6,  '2026-09-03 10:00:00', 4],
        [7,  '2026-09-04 09:00:00', 4], // Today
        // Maria (5)
        [8,  '2026-08-28 14:00:00', 5],
        [9,  '2026-09-02 11:00:00', 5],
        // Fatima (7)
        [10, '2026-08-22 10:00:00', 7],
        [11, '2026-08-29 11:00:00', 7],
        // Robert (6)
        [12, '2026-08-30 09:00:00', 6],
        // John (8)
        [13, '2026-09-02 11:00:00', 8],
    ];
    $insVisit = $pdo->prepare("INSERT INTO VISIT (VisitID, AdmissionDate, PatientID) VALUES (?, ?, ?)");
    foreach ($visits as $v) {
        $insVisit->execute($v);
    }

    // Diagnoses
    $diagnoses = [
        [1,  'Acute upper respiratory tract infection with mild bacterial rhinitis'],
        [2,  'Secondary bronchial inflammation with dry cough'],
        [3,  'Community-acquired pneumonia, right lower lobe consolidation'],
        [4,  'Resolving bacterial pneumonia; significant clinical improvement noted'],
        [5,  'Mild persistent bronchial hyperresponsiveness; post-viral phase'],
        [6,  'Seasonal allergic rhinitis with mild mucosal edema'],
        [7,  'Clinical recovery confirmed; pulmonary clear to auscultation bilaterally'],
        [8,  'Migraine with aura — chronic episodic neurological pattern'],
        [9,  'Tension headache with cervical muscle spasm'],
        [10, 'Hypertensive cardiovascular disease with paroxysmal atrial fibrillation'],
        [11, 'Essential hypertension, Stage II, with left ventricular strain pattern'],
        [12, 'Acute viral gastroenteritis with moderate dehydration'],
        [13, 'Routine preventive health evaluation — no acute pathology identified'],
    ];
    $insDiag = $pdo->prepare("INSERT INTO DIAGNOSIS (VisitID, DiagnosisText) VALUES (?, ?)");
    foreach ($diagnoses as $d) {
        $insDiag->execute($d);
    }

    // Consultations
    $consultations = [
        ['Initial assessment: 3-day fever, dry cough, general malaise. Started empirical oral antibiotics.', 1, 1, 600.00],
        ['Follow-up visit. Cough persisting, breath sounds diminished at right base. Chest imaging ordered.', 2, 1, 650.00],
        ['Review of chest X-ray: consolidation confirmed. Escalated antibiotic therapy to amoxicillin-clavulanate.', 3, 1, 850.00],
        ['Re-evaluation: oxygen saturation 98% on ambient air, fever resolved. Advised hydration & rest.', 4, 1, 550.00],
        ['Pulmonary follow-up. Spirometry shows normal peak expiratory flow. Tapered bronchodilator.', 5, 1, 500.00],
        ['Allergy follow-up: symptoms exacerbated by pollen. Prescribed non-sedating antihistamines.', 6, 1, 450.00],
        ['Discharge clearance check. Patient fully recovered and cleared for normal physical activity.', 7, 1, 400.00],
        ['Comprehensive neurological consultation for recurrent aura migraines. Screen time ergonomic review.', 8, 2, 750.00],
        ['Follow-up for migraine prophylaxis and trigger avoidance review.', 9, 2, 500.00],
        ['Urgent cardiac consultation: irregular pulse, elevated troponin I negative. Started beta-blocker.', 10, 3, 1400.00],
        ['Cardiology follow-up: BP 142/88 mmHg. Rate controlled. Echocardiogram review completed.', 11, 3, 1100.00],
        ['Gastroenterology visit: acute nausea and cramps. Prescribed oral rehydration therapy.', 12, 2, 450.00],
        ['Annual executive health checkup: ECG, blood chemistry, fasting lipid profile reviewed.', 13, 2, 600.00],
    ];
    $insCons = $pdo->prepare("INSERT INTO CONSULTATION (Notes, VisitID, DoctorID, Cost) VALUES (?, ?, ?, ?)");
    foreach ($consultations as $c) {
        $insCons->execute($c);
    }

    // Lab Tests
    $labTests = [
        ['CBC: WBC 12.8×10⁹/L (elevated), CRP 48 mg/L. Indicates acute bacterial response.', 1, 400.00],
        ['Sputum Gram Stain & Culture: Streptococcus pneumoniae sensitive to amoxicillin.', 2, 550.00],
        ['Digital Chest X-Ray: Infiltrate in right lower lobe confirming lobar pneumonia.', 3, 750.00],
        ['Follow-up CRP: Dropped to 12 mg/L. Significant resolution of systemic inflammatory markers.', 4, 350.00],
        ['Peak Expiratory Flow (PEF) Spirometry: FEV1/FVC ratio 82% (within normal limits).', 5, 450.00],
        ['Total Serum IgE & Eosinophil Count: IgE 180 IU/mL (mild allergic elevation).', 6, 420.00],
        ['Repeat Chest X-Ray: Complete clearance of previously noted right lower lobe infiltrate.', 7, 700.00],
        ['High-Resolution Brain MRI: Normal brain parenchyma and ventricles. No intracranial lesion.', 8, 2800.00],
        ['Serum Electrolytes & Magnesium: Na 140 mmol/L, K 4.2 mmol/L, Mg 2.1 mg/dL (Normal).', 9, 320.00],
        ['12-Lead Electrocardiogram (ECG): Irregular rhythm with fibrillatory waves. Rate 98 bpm.', 10, 650.00],
        ['Transthoracic Echocardiogram (TTE): LVEF 58%, mild concentric left ventricular hypertrophy.', 11, 1600.00],
        ['Rotavirus Rapid Antigen Test: Positive. Stool Occult Blood: Negative.', 12, 450.00],
        ['Comprehensive Metabolic Panel (CMP) + Lipid Panel: Cholesterol 185 mg/dL, Fasting Glucose 94 mg/dL.', 13, 650.00],
    ];
    $insLab = $pdo->prepare("INSERT INTO LAB_TEST (Result, VisitID, Cost) VALUES (?, ?, ?)");
    foreach ($labTests as $l) {
        $insLab->execute($l);
    }

    // Prescriptions (7 prescriptions for Alex Rahman to test 5-row fixed box scrollbar)
    $prescriptions = [
        [1,  1, '2026-08-10 10:30:00'],
        [2,  2, '2026-08-18 12:00:00'],
        [3,  3, '2026-08-25 10:15:00'],
        [4,  4, '2026-08-28 15:30:00'],
        [5,  5, '2026-09-01 14:45:00'],
        [6,  6, '2026-09-03 10:45:00'],
        [7,  7, '2026-09-04 09:30:00'],
        [8,  8, '2026-08-28 14:45:00'],
        [9,  10, '2026-08-22 10:45:00'],
        [10, 11, '2026-08-29 11:30:00'],
    ];
    $insPresc = $pdo->prepare("INSERT INTO PRESCRIPTION (PrescriptionID, VisitID, PrescribedAt) VALUES (?, ?, ?)");
    foreach ($prescriptions as $pr) {
        $insPresc->execute($pr);
    }

    // Prescription Items
    $items = [
        [1, 1, 'Amoxicillin 500mg — 1 capsule 3 times daily for 7 days', 21],
        [2, 7, 'Paracetamol 500mg — 1 tablet every 6 hours as needed for fever', 16],
        [3, 1, 'Amoxicillin 500mg — 1 capsule twice daily for 10 days', 20],
        [4, 8, 'Ibuprofen 400mg — 1 tablet with food twice daily for 5 days', 10],
        [5, 9, 'Salbutamol Inhaler — 2 puffs every 6 hours as needed', 1],
        [6, 7, 'Paracetamol 500mg — 1 tablet twice daily for 3 days', 6],
        [7, 12, 'Sterile Gauze Pads 10x10cm — apply to skin surface as needed', 5],
        [8, 2, 'Metformin 500mg — 1 tablet with evening meal daily', 30],
        [9, 3, 'Lisinopril 10mg — 1 tablet every morning', 30],
        [10, 10, 'Amlodipine 5mg — 1 tablet daily', 30],
    ];
    $insItem = $pdo->prepare("INSERT INTO PRESCRIPTION_ITEM (PrescriptionID, MedicationID, Dosage, Quantity) VALUES (?, ?, ?, ?)");
    foreach ($items as $it) {
        $insItem->execute($it);
    }

    // 8. Populate Saved Invoices (Ensure multiple invoices for billing page & testing Print Bill)
    echo "8. Populating Saved Invoices & Billing Records...\n";
    $invoices = [
        // Visit 1 (Alex - 80% Insured)
        ['2026-08-11', 252.50, 1],
        // Visit 3 (Alex - 80% Insured)
        ['2026-08-26', 370.00, 3],
        // Visit 6 (Alex - 80% Insured)
        ['2026-09-03', 178.00, 6],
        // Visit 8 (Maria - 60% Insured)
        ['2026-08-29', 1516.00, 8],
        // Visit 10 (Fatima - 100% Insured, OutOfPocket = 0)
        ['2026-08-23', 0.00, 10],
        // Visit 13 (John - Self-Pay, 0% Insured)
        ['2026-09-02', 1250.00, 13],
    ];
    $insInv = $pdo->prepare("INSERT INTO INVOICE (Date, OutOfPocket, VisitID) VALUES (?, ?, ?)");
    foreach ($invoices as $iv) {
        $insInv->execute($iv);
    }

    // 9. Populate Uploaded Patient Documents & Real Files
    echo "9. Generating Real Uploaded Sample Documents in resources/uploads/...\n";
    $pdo->exec("DELETE FROM PATIENT_DOCUMENT");
    $uploadDir = dirname(__DIR__) . '/resources/uploads';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    // Helper to write minimal valid mock PDF
    $createMockPdf = function($filePath, $title, $patientName) {
        $content = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n";
        $content .= "3 0 obj<</Type/Page/MediaBox[0 0 612 792]/Parent 2 0 R/Resources<<>>/Contents 4 0 R>>endobj\n";
        $stream = "BT /F1 16 Tf 50 720 Td (PreMed EHR Authenticated Record: $title) Tj ET\n";
        $stream .= "BT /F1 12 Tf 50 690 Td (Patient: $patientName) Tj ET\n";
        $stream .= "BT /F1 10 Tf 50 660 Td (Generated for system verification testing.) Tj ET\n";
        $len = strlen($stream);
        $content .= "4 0 obj<</Length $len>>stream\n$stream\nendstream\nendobj\nxref\n0 5\n0000000000 65535 f \n";
        $content .= "trailer<</Size 5/Root 1 0 R>>\nstartxref\n" . strlen($content) . "\n%%EOF\n";
        file_put_contents($filePath, $content);
    };

    // Helper to write 1x1 transparent PNG
    $createMockPng = function($filePath) {
        $pngBase64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
        file_put_contents($filePath, base64_decode($pngBase64));
    };

    $docs = [
        // Alex Rahman (Patient 4) - 6 documents to test 5-row fixed scrollable box
        ['Blood_Chemistry_Panel_2026.pdf',   'doc_alex_blood_panel.pdf',      'application/pdf', '2026-08-10 11:00:00', 4, 'pdf'],
        ['Chest_XRay_Right_Lobe.png',         'doc_alex_chest_xray.png',       'image/png',       '2026-08-25 10:30:00', 4, 'png'],
        ['Spirometry_Pulmonary_Report.pdf',   'doc_alex_spirometry.pdf',       'application/pdf', '2026-08-28 16:00:00', 4, 'pdf'],
        ['Discharge_Summary_Aug2026.pdf',     'doc_alex_discharge_summary.pdf','application/pdf', '2026-09-01 15:00:00', 4, 'pdf'],
        ['Prescription_Slip_Sep2026.pdf',     'doc_alex_prescription_scan.pdf','application/pdf', '2026-09-03 11:15:00', 4, 'pdf'],
        ['Immunization_Vaccine_Card.pdf',     'doc_alex_vaccine_card.pdf',     'application/pdf', '2026-09-04 09:45:00', 4, 'pdf'],

        // Fatima Begum (Patient 7)
        ['Cardiac_Echocardiogram_Color.pdf',  'doc_fatima_echo_scan.pdf',      'application/pdf', '2026-08-29 11:45:00', 7, 'pdf'],
        ['ECG_Rhythm_Strip_Aug2026.png',      'doc_fatima_ecg_strip.png',      'image/png',       '2026-08-22 11:15:00', 7, 'png'],
    ];

    $insDoc = $pdo->prepare("INSERT INTO PATIENT_DOCUMENT (FileName, StoredName, MimeType, UploadedAt, PatientID) VALUES (?, ?, ?, ?, ?)");
    foreach ($docs as $d) {
        $filePath = $uploadDir . '/' . $d[1];
        if ($d[5] === 'pdf') {
            $createMockPdf($filePath, $d[0], $d[4] === 4 ? 'Alex Rahman' : 'Fatima Begum');
        } else {
            $createMockPng($filePath);
        }
        $insDoc->execute([$d[0], $d[1], $d[2], $d[3], $d[4]]);
    }

    // 10. Populate Pre-Generated Audit Log Entries
    echo "10. Populating Audit Log Trail Entries...\n";
    $pdo->exec("DELETE FROM AUDIT_LOG");
    $audits = [
        ['UPDATE', 'DIAGNOSIS',     1,  '{"DiagnosisText":"Upper respiratory infection"}', '{"DiagnosisText":"Acute upper respiratory tract infection with mild bacterial rhinitis"}', '2026-08-10 11:15:00', 1],
        ['UPDATE', 'PRESCRIPTION',  1,  '{"VisitID":1,"PrescribedAt":"2026-08-10 10:00:00"}', '{"VisitID":1,"PrescribedAt":"2026-08-10 10:30:00"}', '2026-08-10 11:20:00', 1],
        ['UPDATE', 'MEDICATION',    1,  '{"MedicationName":"Amoxicillin 500mg","StockQuantity":200}', '{"MedicationName":"Amoxicillin 500mg","StockQuantity":220,"InventoryStatus":"Available"}', '2026-08-15 09:00:00', 9],
        ['DELETE', 'DIAGNOSIS',     99, '{"DiagnosisText":"Incorrect trial diagnosis entry - deleted by physician"}', NULL, '2026-08-19 14:00:00', 1],
        ['UPDATE', 'DIAGNOSIS',     3,  '{"DiagnosisText":"Pneumonia suspected"}', '{"DiagnosisText":"Community-acquired pneumonia, right lower lobe consolidation"}', '2026-08-25 10:00:00', 1],
        ['UPDATE', 'MEDICATION',    4,  '{"MedicationName":"Azithromycin 250mg","StockQuantity":52}', '{"MedicationName":"Azithromycin 250mg","StockQuantity":32,"InventoryStatus":"Reorder Needed"}', '2026-08-28 16:30:00', 9],
        ['UPDATE', 'DIAGNOSIS',     8,  '{"DiagnosisText":"Migraine without aura"}', '{"DiagnosisText":"Migraine with aura — chronic episodic neurological pattern"}', '2026-08-29 09:15:00', 2],
        ['UPDATE', 'DIAGNOSIS',     10, '{"DiagnosisText":"Heart disease"}', '{"DiagnosisText":"Hypertensive cardiovascular disease with paroxysmal atrial fibrillation"}', '2026-08-30 11:00:00', 3],
        ['UPDATE', 'MEDICATION',    11, '{"MedicationName":"EpiPen Auto-Injector","StockQuantity":20}', '{"MedicationName":"EpiPen Auto-Injector","StockQuantity":12,"InventoryStatus":"Reorder Needed"}', '2026-09-02 12:00:00', 9],
    ];
    $insAudit = $pdo->prepare("INSERT INTO AUDIT_LOG (ActionType, TableName, RecordID, OldData, NewData, Timestamp, UserID) VALUES (?, ?, ?, ?, ?, ?, ?)");
    foreach ($audits as $au) {
        $insAudit->execute($au);
    }

    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

    echo "\n========================================================\n";
    echo "DATA POPULATION COMPLETED SUCCESSFULLY!\n";
    echo "========================================================\n";

    // Summary counts
    $summary = [
        'Users'             => $pdo->query("SELECT COUNT(*) FROM `USER`")->fetchColumn(),
        'Patients'          => $pdo->query("SELECT COUNT(*) FROM PATIENT")->fetchColumn(),
        'Doctors'           => $pdo->query("SELECT COUNT(*) FROM DOCTOR")->fetchColumn(),
        'Staff'             => $pdo->query("SELECT COUNT(*) FROM STAFF")->fetchColumn(),
        'Guardians'         => $pdo->query("SELECT COUNT(*) FROM GUARDIAN")->fetchColumn(),
        'Clinic Rooms'      => $pdo->query("SELECT COUNT(*) FROM CLINIC_ROOM")->fetchColumn(),
        'Medications'       => $pdo->query("SELECT COUNT(*) FROM MEDICATION")->fetchColumn(),
        'Appointments'      => $pdo->query("SELECT COUNT(*) FROM APPOINTMENT")->fetchColumn(),
        'Alex Appts'        => $pdo->query("SELECT COUNT(*) FROM APPOINTMENT WHERE PatientID = 4")->fetchColumn(),
        'Visits'            => $pdo->query("SELECT COUNT(*) FROM VISIT")->fetchColumn(),
        'Alex Visits'       => $pdo->query("SELECT COUNT(*) FROM VISIT WHERE PatientID = 4")->fetchColumn(),
        'Diagnoses'         => $pdo->query("SELECT COUNT(*) FROM DIAGNOSIS")->fetchColumn(),
        'Consultations'     => $pdo->query("SELECT COUNT(*) FROM CONSULTATION")->fetchColumn(),
        'Lab Tests'         => $pdo->query("SELECT COUNT(*) FROM LAB_TEST")->fetchColumn(),
        'Prescriptions'     => $pdo->query("SELECT COUNT(*) FROM PRESCRIPTION")->fetchColumn(),
        'Alex Prescriptions'=> $pdo->query("SELECT COUNT(*) FROM PRESCRIPTION PR JOIN VISIT V ON PR.VisitID = V.VisitID WHERE V.PatientID = 4")->fetchColumn(),
        'Invoices'          => $pdo->query("SELECT COUNT(*) FROM INVOICE")->fetchColumn(),
        'Documents'         => $pdo->query("SELECT COUNT(*) FROM PATIENT_DOCUMENT")->fetchColumn(),
        'Alex Documents'    => $pdo->query("SELECT COUNT(*) FROM PATIENT_DOCUMENT WHERE PatientID = 4")->fetchColumn(),
        'Audit Entries'     => $pdo->query("SELECT COUNT(*) FROM AUDIT_LOG")->fetchColumn(),
    ];

    foreach ($summary as $k => $v) {
        echo sprintf("%-22s: %s\n", $k, $v);
    }
    echo "========================================================\n";

} catch (Exception $e) {
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    echo "ERROR: " . $e->getMessage() . "\n";
}
