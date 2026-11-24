<?php
// birthday_system01/download_template.php
session_start();

// Check if the user is logged in
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: index.php");
    exit;
}

// Set headers to force download
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="student_import_template.csv"');

// Open output stream
$output = fopen('php://output', 'w');

// 1. Add Header Row - Updated to DD-MM-YYYY
fputcsv($output, ['Student ID', 'Name', 'Phone', 'DOB (DD-MM-YYYY)']);

// 2. Add Example Row - Updated example to match format
fputcsv($output, ['BWU/BTS/24/001', 'John Doe', '9876543210', '15-05-2003']);

fclose($output);
exit();
?>