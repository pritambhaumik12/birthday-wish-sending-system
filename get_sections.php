<?php
// birthday_system01/get_sections.php
require_once "config.php";

header('Content-Type: application/json');

if (isset($_POST['dept_name']) && isset($_POST['year_val'])) {
    
    $dept_name = trim($_POST['dept_name']);
    $year_val = trim($_POST['year_val']);
    
    $response = [];
    
    // UPDATED SQL: Added "AND s.reg_open = 1"
    $sql = "SELECT s.section_name 
            FROM sections_master s
            JOIN departments_master d ON s.dept_id = d.id
            JOIN years_master y ON s.year_id = y.id
            WHERE d.dept_name = ? AND y.year_val = ? AND s.reg_open = 1
            ORDER BY s.section_name ASC";
            
    if ($stmt = mysqli_prepare($link, $sql)) {
        mysqli_stmt_bind_param($stmt, "ss", $dept_name, $year_val);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        while ($row = mysqli_fetch_assoc($result)) {
            $response[] = $row['section_name'];
        }
        mysqli_stmt_close($stmt);
    }
    
    echo json_encode($response);
} else {
    echo json_encode([]);
}
mysqli_close($link);
?>