<?php
include('config.php');

$stmt = $conn->prepare("UPDATE visitors SET status='approved' WHERE id=?");
$stmt->bind_param("i", $id);
$conn->query("UPDATE visitors SET status='rejected' WHERE id=$id");

header("Location: resident.php");
?>