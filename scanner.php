<?php include('config.php'); ?>

<h2>📷 Scan Visitor QR</h2>

<div id="reader" style="width:300px;"></div>

<p id="result"></p>

<script src="https://unpkg.com/html5-qrcode"></script>

<script>
function onScanSuccess(decodedText) {
    document.getElementById("result").innerHTML = "Scanning...";

    fetch("verify.php", {
        method: "POST",
        headers: {
            "Content-Type": "application/x-www-form-urlencoded"
        },
        body: "qr=" + decodedText
    })
    .then(res => res.text())
    .then(data => {
        document.getElementById("result").innerHTML = data;
    });
}

let scanner = new Html5QrcodeScanner("reader", {
    fps: 10,
    qrbox: 250
});

scanner.render(onScanSuccess);
</script>