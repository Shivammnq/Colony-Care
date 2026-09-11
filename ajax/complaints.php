<?php
include "../config.php";

$result = $conn->query("SELECT * FROM cc_complaints");

echo "<h3>Complaints</h3>";

while($row = $result->fetch_assoc()){
    echo "<p>{$row['complaint_text']}</p><hr>";
}

?>