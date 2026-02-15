<?php
header('Content-Type: application/json');

include $_SERVER['DOCUMENT_ROOT'] . "/admin/fungsi/koneksi.php";
session_start();

// Validasi session
if (
  !isset($_SESSION['id_login']) || 
  !isset($_SESSION['subunit']) || 
  !isset($_SESSION['status']) || 
  $_SESSION['status'] !== 'active' || 
  !isset($_SESSION['level']) || 
  ($_SESSION['level'] !== 'guru' && $_SESSION['level'] !== 'admin')
) {
  header("location: ../admin/login.php");
  exit();
}


$result = mysqli_query($koneksi, "SHOW TABLES");

if (!$result) {
    echo json_encode([
        "error" => "Gagal mengambil daftar tabel"
    ]);
    exit;
}

$structure = "";

while ($tableRow = mysqli_fetch_array($result)) {
    $tableName = $tableRow[0];
    $structure .= "TABEL: {$tableName}\n";

    $columns = mysqli_query($koneksi, "DESCRIBE {$tableName}");
    if (!$columns) continue;

    while ($col = mysqli_fetch_assoc($columns)) {
        $structure .= "- {$col['Field']} ({$col['Type']})";
        if ($col['Key'] === 'PRI') $structure .= " [PRIMARY KEY]";
        $structure .= "\n";
    }

    $structure .= "\n";
}

echo json_encode([
    "structure" => trim($structure)
]);
