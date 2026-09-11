<?php include('config.php'); ?>

<h2>Guard Panel - Add Visitor</h2>

<form method="POST">
    <input type="text" name="name" placeholder="Visitor Name" required><br><br>
    <input type="text" name="phone" placeholder="Phone" required><br><br>
    <input type="text" name="flat_no" placeholder="Flat No" required><br><br>
    <textarea name="purpose" placeholder="Purpose"></textarea><br><br>

    <button type="submit" name="add">Add Visitor</button>
</form>

<?php
if (isset($_POST['add'])) {
    $name = $_POST['name'];
    $phone = $_POST['phone'];
    $flat = $_POST['flat_no'];
    $purpose = $_POST['purpose'];

    $stmt = $conn->prepare("INSERT INTO visitors (name, phone, flat_no, purpose) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $name, $phone, $flat, $purpose);
    $stmt->execute();
    
    echo "Visitor Added!";
}
?>