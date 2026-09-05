<?php
/**
 * Notifications Helper for PreMed Portal
 * Gathers relevant notifications based on user role and database state.
 * Uses Procedural MySQLi.
 */

if (!function_exists('get_user_notifications')) {
    function get_user_notifications($conn, int $userId, string $role): array
    {
        $notifs = [];

        try {
            if ($role === 'Doctor') {
                // 1. Critical/Severe Symptom Alerts for assigned patients (Severity >= 7 in last 7 days)
                $sql1 = "
                    SELECT SL.SymptomName, SL.SeverityScore, SL.LoggedAt, U.Name AS PatientName
                    FROM SYMPTOM_LOG SL
                    JOIN PATIENT P ON SL.PatientID = P.UserID
                    JOIN `USER` U ON P.UserID = U.UserID
                    WHERE P.AssignedDoctorID = ? AND SL.SeverityScore >= 7
                      AND SL.LoggedAt >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                    ORDER BY SL.LoggedAt DESC LIMIT 3
                ";
                $rows1 = db_fetch_all($conn, $sql1, [$userId]);
                foreach ($rows1 as $r) {
                    $notifs[] = [
                        'id'    => 'sym_' . $r['LoggedAt'],
                        'type'  => 'urgent',
                        'badge' => 'Severe ' . $r['SeverityScore'] . '/10',
                        'title' => 'Urgent Symptom: ' . htmlspecialchars($r['PatientName']),
                        'body'  => htmlspecialchars($r['PatientName']) . ' logged "' . htmlspecialchars($r['SymptomName']) . '" with high severity.',
                        'time'  => date('M j, g:i A', strtotime($r['LoggedAt'])),
                        'link'  => 'doctor_analytics.php'
                    ];
                }

                // 2. Upcoming consultations for this doctor
                $sql2 = "
                    SELECT A.AppointmentID, A.AppointmentDate, U.Name AS PatientName, R.RoomNumber
                    FROM APPOINTMENT A
                    JOIN PATIENT P ON A.PatientID = P.UserID
                    JOIN `USER` U ON P.UserID = U.UserID
                    JOIN CLINIC_ROOM R ON A.RoomID = R.RoomID
                    WHERE A.DoctorID = ? AND A.Status = 'Scheduled' AND A.AppointmentDate >= NOW()
                    ORDER BY A.AppointmentDate ASC LIMIT 2
                ";
                $rows2 = db_fetch_all($conn, $sql2, [$userId]);
                foreach ($rows2 as $a) {
                    $notifs[] = [
                        'id'    => 'appt_' . $a['AppointmentID'],
                        'type'  => 'info',
                        'badge' => 'Scheduled',
                        'title' => 'Consultation: ' . htmlspecialchars($a['PatientName']),
                        'body'  => 'Appointment set for ' . date('M j, g:i A', strtotime($a['AppointmentDate'])) . ' in Room ' . htmlspecialchars($a['RoomNumber']) . '.',
                        'time'  => date('M j, g:i A', strtotime($a['AppointmentDate'])),
                        'link'  => 'doctor_appointments.php'
                    ];
                }

                // 3. Reorder alerts for medications
                $sql3 = "
                    SELECT MedicationName, StockQuantity
                    FROM MEDICATION
                    WHERE InventoryStatus = 'Reorder Needed' OR StockQuantity < 50
                    LIMIT 2
                ";
                $rows3 = db_fetch_all($conn, $sql3);
                foreach ($rows3 as $m) {
                    $notifs[] = [
                        'id'    => 'med_' . $m['MedicationName'],
                        'type'  => 'warning',
                        'badge' => 'Low Stock',
                        'title' => 'Medication Reorder Warning',
                        'body'  => htmlspecialchars($m['MedicationName']) . ' is low on stock (' . (int)$m['StockQuantity'] . ' units remaining).',
                        'time'  => 'Inventory Alert',
                        'link'  => 'clinical_records.php'
                    ];
                }

            } elseif ($role === 'Patient') {
                // 1. Daily check-in reminder if no symptoms logged today
                $chk = db_fetch_one($conn, "SELECT 1 FROM SYMPTOM_LOG WHERE PatientID = ? AND DATE(LoggedAt) = CURRENT_DATE() LIMIT 1", [$userId]);
                if (!$chk) {
                    $notifs[] = [
                        'id'    => 'daily_checkin',
                        'type'  => 'reminder',
                        'badge' => 'Daily Task',
                        'title' => 'Daily Symptom Log Reminder',
                        'body'  => 'You have not logged your symptoms today. Record them to keep your recovery trend accurate.',
                        'time'  => 'Today',
                        'link'  => 'symptom_log.php'
                    ];
                }

                // 2. Upcoming appointments for this patient
                $sqlPA = "
                    SELECT A.AppointmentID, A.AppointmentDate, DU.Name AS DoctorName, R.RoomNumber, A.Status
                    FROM APPOINTMENT A
                    JOIN DOCTOR D ON A.DoctorID = D.UserID
                    JOIN `USER` DU ON D.UserID = DU.UserID
                    JOIN CLINIC_ROOM R ON A.RoomID = R.RoomID
                    WHERE A.PatientID = ? AND A.Status != 'Cancelled' AND A.AppointmentDate >= NOW()
                    ORDER BY A.AppointmentDate ASC LIMIT 2
                ";
                $rowsPA = db_fetch_all($conn, $sqlPA, [$userId]);
                foreach ($rowsPA as $pa) {
                    $notifs[] = [
                        'id'    => 'p_appt_' . $pa['AppointmentID'],
                        'type'  => 'info',
                        'badge' => htmlspecialchars($pa['Status']),
                        'title' => 'Upcoming Appointment',
                        'body'  => 'With ' . htmlspecialchars(format_doctor_name($pa['DoctorName'])) . ' on ' . date('M j, g:i A', strtotime($pa['AppointmentDate'])) . ' (Room ' . htmlspecialchars($pa['RoomNumber']) . ').',
                        'time'  => date('M j, g:i A', strtotime($pa['AppointmentDate'])),
                        'link'  => 'book_appointment.php'
                    ];
                }

                // 3. Recent diagnosis / clinical records update
                $sqlDiag = "
                    SELECT D.DiagnosisText, D.CreatedAt
                    FROM DIAGNOSIS D
                    JOIN VISIT V ON D.VisitID = V.VisitID
                    WHERE V.PatientID = ?
                    ORDER BY D.CreatedAt DESC LIMIT 1
                ";
                $diag = db_fetch_one($conn, $sqlDiag, [$userId]);
                if ($diag) {
                    $notifs[] = [
                        'id'    => 'diag_' . $diag['CreatedAt'],
                        'type'  => 'success',
                        'badge' => 'Record Added',
                        'title' => 'Clinical Diagnosis On File',
                        'body'  => 'Diagnosis: ' . htmlspecialchars($diag['DiagnosisText']),
                        'time'  => date('M j, Y', strtotime($diag['CreatedAt'])),
                        'link'  => 'patient_records.php'
                    ];
                }

            } elseif ($role === 'Staff') {
                // 1. Pending patient check-ins / scheduled consultations
                $sqlSA = "
                    SELECT A.AppointmentID, A.AppointmentDate, PU.Name AS PatientName, DU.Name AS DoctorName, R.RoomNumber
                    FROM APPOINTMENT A
                    JOIN PATIENT P ON A.PatientID = P.UserID
                    JOIN `USER` PU ON P.UserID = PU.UserID
                    JOIN DOCTOR D ON A.DoctorID = D.UserID
                    JOIN `USER` DU ON D.UserID = DU.UserID
                    JOIN CLINIC_ROOM R ON A.RoomID = R.RoomID
                    WHERE A.Status IN ('Scheduled', 'Confirmed') AND A.AppointmentDate >= NOW()
                    ORDER BY A.AppointmentDate ASC LIMIT 3
                ";
                $rowsSA = db_fetch_all($conn, $sqlSA);
                foreach ($rowsSA as $sa) {
                    $notifs[] = [
                        'id'    => 'staff_appt_' . $sa['AppointmentID'],
                        'type'  => 'info',
                        'badge' => 'Check-in Queue',
                        'title' => 'Pending Patient Check-in',
                        'body'  => htmlspecialchars($sa['PatientName']) . ' with ' . htmlspecialchars(format_doctor_name($sa['DoctorName'])) . ' (Room ' . htmlspecialchars($sa['RoomNumber']) . ').',
                        'time'  => date('M j, g:i A', strtotime($sa['AppointmentDate'])),
                        'link'  => 'staff_appointments.php'
                    ];
                }

                // 2. Medication low stock
                $sqlSM = "
                    SELECT MedicationName, StockQuantity
                    FROM MEDICATION
                    WHERE InventoryStatus = 'Reorder Needed' OR StockQuantity < 50
                    LIMIT 2
                ";
                $rowsSM = db_fetch_all($conn, $sqlSM);
                foreach ($rowsSM as $sm) {
                    $notifs[] = [
                        'id'    => 'staff_med_' . $sm['MedicationName'],
                        'type'  => 'warning',
                        'badge' => 'Restock',
                        'title' => 'Inventory Reorder Needed',
                        'body'  => htmlspecialchars($sm['MedicationName']) . ' is down to ' . (int)$sm['StockQuantity'] . ' units.',
                        'time'  => 'Warehouse Alert',
                        'link'  => 'staff_overview.php'
                    ];
                }

            } elseif ($role === 'Guardian') {
                // 1. Ward latest symptoms
                $sqlW = "
                    SELECT UW.Name AS WardName, SL.SymptomName, SL.SeverityScore, SL.LoggedAt
                    FROM GUARDIAN G
                    JOIN PATIENT P ON G.PatientID = P.UserID
                    JOIN `USER` UW ON P.UserID = UW.UserID
                    JOIN SYMPTOM_LOG SL ON P.UserID = SL.PatientID
                    WHERE G.GuardianUserID = ?
                    ORDER BY SL.LoggedAt DESC LIMIT 2
                ";
                $rowsW = db_fetch_all($conn, $sqlW, [$userId]);
                foreach ($rowsW as $w) {
                    $isSevere = (int)$w['SeverityScore'] >= 7;
                    $notifs[] = [
                        'id'    => 'w_sym_' . $w['LoggedAt'],
                        'type'  => $isSevere ? 'urgent' : 'info',
                        'badge' => 'Score ' . $w['SeverityScore'] . '/10',
                        'title' => ($isSevere ? '⚠ High Severity Alert: ' : 'Ward Logged Symptom: ') . htmlspecialchars($w['WardName']),
                        'body'  => htmlspecialchars($w['WardName']) . ' reported "' . htmlspecialchars($w['SymptomName']) . '" (Severity: ' . $w['SeverityScore'] . '/10).',
                        'time'  => date('M j, g:i A', strtotime($w['LoggedAt'])),
                        'link'  => 'guardian_profile.php'
                    ];
                }

                // 2. Ward upcoming appointment
                $sqlWA = "
                    SELECT A.AppointmentDate, DU.Name AS DoctorName, UW.Name AS WardName, R.RoomNumber
                    FROM GUARDIAN G
                    JOIN PATIENT P ON G.PatientID = P.UserID
                    JOIN `USER` UW ON P.UserID = UW.UserID
                    JOIN APPOINTMENT A ON P.UserID = A.PatientID
                    JOIN DOCTOR D ON A.DoctorID = D.UserID
                    JOIN `USER` DU ON D.UserID = DU.UserID
                    JOIN CLINIC_ROOM R ON A.RoomID = R.RoomID
                    WHERE G.GuardianUserID = ? AND A.Status != 'Cancelled' AND A.AppointmentDate >= NOW()
                    ORDER BY A.AppointmentDate ASC LIMIT 1
                ";
                $wa = db_fetch_one($conn, $sqlWA, [$userId]);
                if ($wa) {
                    $notifs[] = [
                        'id'    => 'w_appt_' . $wa['AppointmentDate'],
                        'type'  => 'info',
                        'badge' => 'Ward Visit',
                        'title' => 'Ward Scheduled Consultation',
                        'body'  => htmlspecialchars($wa['WardName']) . ' has an appointment with ' . htmlspecialchars(format_doctor_name($wa['DoctorName'])) . ' on ' . date('M j, g:i A', strtotime($wa['AppointmentDate'])) . '.',
                        'time'  => date('M j, g:i A', strtotime($wa['AppointmentDate'])),
                        'link'  => 'guardian_profile.php'
                    ];
                }
            }
        } catch (Throwable $e) {
            // Graceful fallback if query fails
        }

        return $notifs;
    }
}
