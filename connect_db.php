<?php
/* ======================================================================
   connect_db.php - database connection
   ====================================================================== */

$servername = "162.159.24.67";
$username   = "ecamonit_ECAMonitoring";
$password   = "awsdrftg1432!@#";
$database   = "ecamonit_delivery";

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli($servername, $username, $password, $database);
    $conn->set_charset("utf8mb4");
} catch (mysqli_sql_exception $e) {
    error_log('DB connection failed: ' . $e->getMessage());
    http_response_code(500);
    exit('The site cannot reach its database right now. Please try again shortly.');
}

return $conn;
