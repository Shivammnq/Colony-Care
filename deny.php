<?php
include 'db.php';
$id = $_GET['id'];

$conn->query("UPDATE visitors SET status='denied' WHERE id=$id");

header("Location: gate_staff.php");
?>