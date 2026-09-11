<?php
include('config.php');

if (isset($_POST['qr'])) {
    $qr = $_POST['qr'];

    // ✅ Prepared Statement
    $stmt = $conn->prepare("SELECT * FROM visitors WHERE qr_code=?");
    $stmt->bind_param("s", $qr);
    $stmt->execute();

    $result = $stmt->get_result();
    $visitor = $result->fetch_assoc();

    if ($visitor && !$visitor['is_entered']) {

        $update = $conn->prepare("UPDATE visitors SET is_entered=1 WHERE qr_code=?");
        $update->bind_param("s", $qr);
        $update->execute();

        echo "✅ Entry Allowed for " . $visitor['name'];
    } else {
        echo "❌ Invalid or Already Entered";
    }
}
?>

<form method="POST">
    <input type="text" name="qr" placeholder="Scan QR Code">
    <button type="submit">Verify</button>
</form>