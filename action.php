<?php
include('includes/db.php');

$id = $_GET['id'];
$action = $_GET['action'];

if($action == 'allow'){
    mysqli_query($conn, "UPDATE visitors SET status='approved' WHERE id=$id");
}
else{
    mysqli_query($conn, "UPDATE visitors SET status='denied' WHERE id=$id");
}

header("Location: gate_staff.php");
?>