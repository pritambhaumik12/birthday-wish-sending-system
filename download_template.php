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

// 1. Add Header Row
// New Format: ID, Name, Phone, DOB, Mail Type, Email
fputcsv($output, ['Student ID', 'Name', 'Phone', 'DOB (DD-MM-YYYY)', 'Mail Type (University/Personal)', 'Email (If Personal)']);

// 2. Add Example Rows

// Example 1: Personal Email
fputcsv($output, ['BWU/BTS/24/001', 'John Doe', '9876543210', '15-05-2003', 'Personal', 'john.doe@gmail.com']);

// Example 2: University Email (Email column can be empty)
fputcsv($output, ['BWU/BTS/24/002', 'Jane Smith', '9123456780', '20-11-2002', 'University', '']);

fclose($output);
exit();
?>