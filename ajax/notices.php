<?php
include "../config.php";

$result = $conn->query("
    SELECT * FROM cc_notices 
    ORDER BY created_at DESC
");

echo "<h3>Notices</h3>";

while($row = $result->fetch_assoc()){
    echo "<div class='notice'>";
    echo "<strong>{$row['title']}</strong><br>";
    echo "<small>{$row['created_at']}</small>";
    echo "</div><hr>";
}
?>