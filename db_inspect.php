<?php
define('DB_HOST','localhost');
define('DB_NAME','cc');
define('DB_USER','root');
define('DB_PASS','');

$pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
]);

$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>DB Inspector</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;600;700&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'DM Sans',sans-serif;background:#f5f7f6;padding:24px;}
h1{font-size:1.3rem;font-weight:700;color:#1a2e22;margin-bottom:20px;}
.table-block{background:#fff;border:1px solid #e0ece6;border-radius:12px;margin-bottom:20px;overflow:hidden;}
.table-name{background:#1a5c3a;color:#fff;padding:12px 18px;font-size:.9rem;font-weight:700;display:flex;justify-content:space-between;align-items:center;}
.table-name span{font-size:.75rem;opacity:.7;font-weight:400;}
table{width:100%;border-collapse:collapse;}
th{background:#f0f9f4;font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#5a7060;padding:9px 14px;text-align:left;border-bottom:1px solid #e0ece6;}
td{padding:9px 14px;font-size:.82rem;border-bottom:1px solid #f0f4f2;color:#1a2e22;}
tr:last-child td{border-bottom:none;}
.type{color:#6d28d9;font-family:monospace;font-size:.8rem;}
.null-yes{color:#dc2626;font-size:.75rem;}
.null-no{color:#16a34a;font-size:.75rem;}
.key-pri{background:#fef3c7;color:#92400e;padding:1px 6px;border-radius:4px;font-size:.68rem;font-weight:700;}
.key-uni{background:#ede9fe;color:#5b21b6;padding:1px 6px;border-radius:4px;font-size:.68rem;font-weight:700;}
.count-badge{background:#e8f5ee;color:#2d7a52;padding:2px 8px;border-radius:99px;font-size:.72rem;font-weight:700;}
.actions{display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap;}
.btn{padding:9px 18px;border-radius:9px;font-family:inherit;font-size:.84rem;font-weight:600;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:6px;border:none;}
.btn-green{background:#1e7a50;color:#fff;}
.btn-gray{background:#fff;color:#5a7060;border:1.5px solid #e0ece6;}
</style>
</head>
<body>
<h1>🗄️ Database Inspector - <?= DB_NAME ?></h1>

<div class="actions">
  <a href="/shivam/fix_db.php"     class="btn btn-green">🔧 Fix & Rebuild Tables</a>
  <a href="/shivam/seed_data.php"  class="btn btn-gray">🌱 Run Seeder</a>
  <a href="/shivam/login.php"      class="btn btn-gray">→ Login</a>
</div>

<?php foreach($tables as $table): ?>
<?php
  $cols  = $pdo->query("DESCRIBE `$table`")->fetchAll();
  $count = $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
?>
<div class="table-block">
  <div class="table-name">
    <?= $table ?>
    <span><span class="count-badge"><?= $count ?> rows</span> &nbsp; <?= count($cols) ?> columns</span>
  </div>
  <table>
    <thead><tr><th>Column</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr></thead>
    <tbody>
    <?php foreach($cols as $col): ?>
    <tr>
      <td><strong><?= $col['Field'] ?></strong></td>
      <td><span class="type"><?= $col['Type'] ?></span></td>
      <td><span class="<?= $col['Null']==='YES'?'null-yes':'null-no' ?>"><?= $col['Null'] ?></span></td>
      <td>
        <?php if($col['Key']==='PRI'): ?><span class="key-pri">PRI</span><?php endif; ?>
        <?php if($col['Key']==='UNI'): ?><span class="key-uni">UNI</span><?php endif; ?>
      </td>
      <td style="color:var(--text-muted);font-size:.78rem"><?= $col['Default']??'NULL' ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endforeach; ?>

</body>
</html>