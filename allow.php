<?php
include 'db.php';
$id = $_GET['id'];

$conn->query("UPDATE visitors SET status='approved' WHERE id=$id");

header("Location: gate_staff.php");
?>