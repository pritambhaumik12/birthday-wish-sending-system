<?php
// birthday_system01/config.php

// 1. Set System Timezone
date_default_timezone_set('Asia/Kolkata');

// 2. Database Credentials
define('DB_SERVER', 'localhost');
define('DB_USERNAME', 'root');     // Change if using a live server
define('DB_PASSWORD', '');         // Change if using a live server
define('DB_NAME', 'bwu_bday');

// 3. Attempt to connect to MySQL database
$link = mysqli_connect(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// 4. Check connection
if($link === false){
    die("ERROR: Could not connect. " . mysqli_connect_error());
}

// 5. Global Settings
define('UPLOAD_DIR', 'uploads/');

// 6. Helper Functions for Dynamic Table Names
// These functions standardize how we reference the year-wise tables throughout the app.

if (!function_exists('get_student_table')) {
    /**
     * Returns the student table name for a specific year.
     * @param string|int $year (e.g., "24", "25")
     * @return string (e.g., "students_24")
     */
    function get_student_table($year) {
        // Sanitize to ensure only digits
        $clean_year = preg_replace('/\D/', '', $year); 
        if (empty($clean_year)) return "students_error"; // Safety fallback
        return "students_" . $clean_year;
    }
}

if (!function_exists('get_log_table')) {
    /**
     * Returns the email log table name for a specific year.
     * @param string|int $year (e.g., "24", "25")
     * @return string (e.g., "email_logs_24")
     */
    function get_log_table($year) {
        $clean_year = preg_replace('/\D/', '', $year);
        if (empty($clean_year)) return "email_logs_error";
        return "email_logs_" . $clean_year;
    }
}
?>