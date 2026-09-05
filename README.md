# PreMed - Patient Symptom Progression & Clinic Management System

A web-based healthcare management platform designed for patient symptom monitoring, clinical consultation, appointment scheduling, pharmacy inventory, and medical billing.

---

## Tech Stack

- **Backend:** PHP 8+ (Procedural MySQLi, Session authentication, CSRF protection, Prepared Statements)
- **Database:** MariaDB / MySQL
- **Frontend:** HTML5, CSS3, JavaScript
- **Server:** Apache (XAMPP) or PHP built-in web server

---

## Features

- **Patient Portal:** Symptom logging with severity tracking (1–10), appointment booking, and access to medical records, prescriptions, and lab documents.
- **Doctor Portal:** Clinical analytics, patient risk stratification, treatment efficacy monitoring, diagnoses, lab orders, prescriptions, and inter-physician referrals.
- **Staff Operations:** Receptionist appointment scheduling, clinic room allocation, lab report dispatch, and pharmaceutical inventory tracking with low-stock alerts.
- **Guardian Access:** Read-only monitoring dashboard for designated ward health tracking.
- **Billing & Auditing:** Automated itemized invoicing (consultations, lab tests, pharmacy) with insurance coverage calculation and clinical audit logging.

---

## Getting Started

### Prerequisites
- [XAMPP](https://www.apachefriends.org/) (PHP 8.0+ and MySQL / MariaDB)

### 1. Database Setup
1. Start **Apache** and **MySQL** from the XAMPP Control Panel.
2. Import the database schema followed by the demo data:

```powershell
# Run from the project directory in PowerShell:
Get-Content .\resources\sql\schema.sql | & 'C:\xampp\mysql\bin\mysql.exe' -u root
Get-Content .\resources\sql\demo_data.sql | & 'C:\xampp\mysql\bin\mysql.exe' -u root
```
*(Alternatively, import `resources/sql/schema.sql` then `resources/sql/demo_data.sql` via phpMyAdmin).*

### 2. Access the Application
- If using Apache in XAMPP (`C:\xampp\htdocs\pre_med`), open:
  ```
  http://localhost/pre_med/
  ```
  *(Visiting the root URL automatically directs to the frontend portal).*
- Or run the PHP built-in server:
  ```powershell
  & 'C:\xampp\php\php.exe' -S 127.0.0.1:8080
  ```
  and navigate to `http://127.0.0.1:8080/`.

---

## Directory Structure

```
pre_med/
├── backend/                  # Server-side business logic, database, actions & APIs
│   ├── db.php                # Procedural MySQLi connection, helper functions, CSRF tokens & security utilities
│   ├── notifications.php     # Notification query engine
│   ├── logout.php            # Session teardown handler
│   ├── download_document.php # Document access control & streamer
│   ├── delete_document.php   # Document deletion handler
│   └── populate_system_data.php # Database seeder script
├── frontend/                 # User-facing pages, views & layouts
│   ├── includes/             # Shared partials (nav, footer)
│   ├── index.php             # Frontend gateway router
│   ├── login.php             # Authentication sign-in
│   ├── signup.php            # Registration portal
│   ├── dashboard.php         # Central command center
│   ├── billing.php           # Invoices & billing
│   ├── patient_records.php   # Patient health records & uploads
│   └── ...                   # Doctor & staff clinical modules
└── resources/                # Static assets, uploads & SQL scripts
    ├── css/                  # Unified CSS stylesheet
    ├── uploads/              # Uploaded patient records & .htaccess
    └── sql/                  # Database DDL schema & DML seed dumps
```

---

## Demo Credentials

All test accounts share the default password: `DemoPass123!`

| Role | Email | Password |
| :--- | :--- | :--- |
| **Doctor** | `demo.doctor@example.com` | `DemoPass123!` |
| **Patient** | `demo.patient@example.com` | `DemoPass123!` |
| **Staff / Receptionist** | `demo.staff@example.com` | `DemoPass123!` |
| **Guardian** | `demo.guardian@example.com` | `DemoPass123!` |
