<?php
include('config.php');

$stmt = $conn->prepare("UPDATE visitors SET status='approved' WHERE id=?");
$stmt->bind_param("i", $id);

// Unique QR text
$qrText = "VISITOR_" . $id . "_" . time();

// Save QR path
$qrPath = "qrcodes/" . $qrText . ".png";

// Include library
include('phpqrcode/qrlib.php');

// Generate QR
QRcode::png($qrText, $qrPath);

// Update DB
$conn->query("UPDATE visitors 
SET status='approved', qr_code='$qrText' 
WHERE id=$id");

header("Location: resident.php");
?>