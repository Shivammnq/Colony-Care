<?php
session_start();

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'super_admin') {
    header('Location: /shivam/login.php?redirect=' . urlencode('/shivam/super_admin_pages.php')); exit;
}

define('DB_HOST', 'localhost');
define('DB_NAME', 'cc');
define('DB_USER', 'root');
define('DB_PASS', '');

$msg = $_SESSION['sa_flash_msg'] ?? '';
$err = $_SESSION['sa_flash_err'] ?? '';
unset($_SESSION['sa_flash_msg'], $_SESSION['sa_flash_err']);
$pageTitle = 'Pages';
$activeNav = 'pages';
$pages_list = [];

try {
    $pdo = new PDO(
        "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    $pdo->exec("CREATE TABLE IF NOT EXISTS cms_pages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(150) NOT NULL,
        slug VARCHAR(150) NOT NULL UNIQUE,
        content TEXT,
        status ENUM('draft','published') DEFAULT 'draft',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        if (isset($_POST['add_page'])) {
            $title   = trim($_POST['title']   ?? '');
            $slug    = trim($_POST['slug']    ?? '');
            $content = trim($_POST['content'] ?? '');
            $status  = ($_POST['status'] ?? 'draft') === 'published' ? 'published' : 'draft';

            if ($slug === '') $slug = '/';
            if ($slug[0] !== '/') $slug = '/' . $slug;
            if ($slug !== '/') $slug = rtrim($slug, '/');

            if (empty($title)) {
                $err = 'Page title is required.';
            } else {
                $chk = $pdo->prepare("SELECT id FROM cms_pages WHERE slug=?");
                $chk->execute([$slug]);
                if ($chk->fetch()) {
                    $err = 'A page with this URL already exists.';
                } else {
                    $pdo->prepare("INSERT INTO cms_pages (title, slug, content, status) VALUES (?,?,?,?)")
                        ->execute([$title, $slug, $content, $status]);
                    $msg = "Page \"{$title}\" created.";
                }
            }
        }

        if (isset($_POST['edit_page'])) {
            $pid     = (int)$_POST['page_id'];
            $title   = trim($_POST['title']   ?? '');
            $slug    = trim($_POST['slug']    ?? '');
            $content = trim($_POST['content'] ?? '');
            $status  = ($_POST['status'] ?? 'draft') === 'published' ? 'published' : 'draft';

            if ($slug === '') $slug = '/';
            if ($slug[0] !== '/') $slug = '/' . $slug;
            if ($slug !== '/') $slug = rtrim($slug, '/');

            if (empty($title)) {
                $err = 'Page title is required.';
            } else {
                $chk = $pdo->prepare("SELECT id FROM cms_pages WHERE slug=? AND id!=?");
                $chk->execute([$slug, $pid]);
                if ($chk->fetch()) {
                    $err = 'Another page already uses this URL.';
                } else {
                    $pdo->prepare("UPDATE cms_pages SET title=?, slug=?, content=?, status=? WHERE id=?")
                        ->execute([$title, $slug, $content, $status, $pid]);
                    $msg = 'Page updated.';
                }
            }
        }

        if (isset($_POST['delete_page'])) {
            $pid = (int)$_POST['page_id'];
            $pdo->prepare("DELETE FROM cms_pages WHERE id=?")->execute([$pid]);
            $msg = 'Page deleted.';
        }

        if (!empty($msg)) $_SESSION['sa_flash_msg'] = $msg;
        if (!empty($err)) $_SESSION['sa_flash_err'] = $err;
        header('Location: /shivam/super_admin_pages.php'); exit;
    }

    $pages_list = $pdo->query("SELECT * FROM cms_pages ORDER BY (slug='/') DESC, title ASC")->fetchAll();

} catch (PDOException $e) {
    $err = 'Database error: ' . $e->getMessage();
}

include __DIR__ . '/super_admin_header.php';
?>

<div style="display:flex;justify-content:space-between;align-items:flex-end;margin-bottom:22px;flex-wrap:wrap;gap:12px;">
    <div>
        <h1 style="font-size:1.4rem;font-weight:700;">Pages</h1>
        <p style="color:var(--text-muted);font-size:.87rem;margin-top:4px;">Website content pages and their publish state</p>
    </div>
    <button onclick="openAddPage()" style="background:var(--green-btn);color:#fff;border:none;padding:11px 20px;border-radius:9px;font-family:inherit;font-size:.87rem;font-weight:700;cursor:pointer;"><i class="fa fa-plus"></i> Add page</button>
</div>

<div style="background:#fff;border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;">
    <div style="padding:16px 20px;border-bottom:1px solid var(--border);"><h3 style="font-size:.95rem;font-weight:700;">All pages</h3></div>
<?php if (empty($pages_list)): ?>
    <div style="text-align:center;padding:60px 20px;color:var(--text-muted);"><i class="fa fa-file-lines" style="font-size:2rem;display:block;margin-bottom:10px;color:var(--border);"></i>No pages yet. Click "Add page" to create one.</div>
<?php else: ?>
<div style="overflow-x:auto;">
<table style="width:100%;border-collapse:collapse;">
<tr style="border-bottom:1px solid var(--border);">
    <th style="text-align:left;padding:12px 16px;font-size:.72rem;text-transform:uppercase;color:var(--text-muted);">Page</th>
    <th style="text-align:left;padding:12px 16px;font-size:.72rem;text-transform:uppercase;color:var(--text-muted);">URL</th>
    <th style="text-align:left;padding:12px 16px;font-size:.72rem;text-transform:uppercase;color:var(--text-muted);">Last Updated</th>
    <th style="text-align:left;padding:12px 16px;font-size:.72rem;text-transform:uppercase;color:var(--text-muted);">Status</th>
    <th style="text-align:left;padding:12px 16px;font-size:.72rem;text-transform:uppercase;color:var(--text-muted);">Actions</th>
</tr>
<?php foreach ($pages_list as $p): ?>
<tr style="border-bottom:1px solid var(--border);">
    <td style="padding:12px 16px;font-size:.85rem;font-weight:600;"><?= htmlspecialchars($p['title']) ?></td>
    <td style="padding:12px 16px;font-size:.85rem;color:var(--text-sub);"><?= htmlspecialchars($p['slug']) ?></td>
    <td style="padding:12px 16px;font-size:.85rem;"><?= date('d M Y', strtotime($p['updated_at'])) ?></td>
    <td style="padding:12px 16px;">
        <span style="background:<?= $p['status']==='published' ? '#dcfce7;color:#166534' : '#fef3c7;color:#92400e' ?>;font-size:.68rem;font-weight:700;padding:3px 10px;border-radius:99px;text-transform:capitalize;"><?= ucfirst($p['status']) ?></span>
    </td>
    <td style="padding:12px 16px;white-space:nowrap;">
        <button onclick='openEditPage(<?= json_encode($p) ?>)' style="background:var(--bg);color:var(--text-primary);border:1px solid var(--border);padding:6px 12px;border-radius:7px;font-size:.76rem;font-weight:600;cursor:pointer;">Edit</button>
        <button onclick='openViewPage(<?= json_encode($p) ?>)' style="background:none;color:var(--text-sub);border:none;padding:6px 8px;font-size:.76rem;font-weight:600;cursor:pointer;text-decoration:underline;">View</button>
        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this page?');"><input type="hidden" name="page_id" value="<?= $p['id'] ?>"><input type="hidden" name="delete_page" value="1"><button type="submit" style="background:none;color:#dc2626;border:none;padding:6px 8px;font-size:.76rem;font-weight:600;cursor:pointer;"><i class="fa fa-trash"></i></button></form>
    </td>
</tr>
<?php endforeach; ?>
</table>
</div>
<?php endif; ?>
</div>

<!-- ══ ADD/EDIT PAGE MODAL ══ -->
<div id="pageModalOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:200;align-items:center;justify-content:center;padding:20px;">
    <div style="background:#fff;border-radius:16px;max-width:560px;width:100%;max-height:88vh;overflow-y:auto;padding:26px;">
        <h2 id="pageModalTitle" style="font-size:1.1rem;font-weight:700;margin-bottom:16px;">Add Page</h2>
        <form method="POST" id="pageForm">
            <input type="hidden" name="page_id" id="pf_id" value="">
            <div style="margin-bottom:14px;">
                <label style="display:block;font-size:.83rem;font-weight:600;margin-bottom:6px;">Page Title *</label>
                <input type="text" name="title" id="pf_title" required placeholder="e.g. About Us"
                    style="width:100%;padding:10px 12px;border:1.5px solid var(--border);border-radius:9px;font-family:inherit;font-size:.87rem;outline:none;">
            </div>
            <div style="margin-bottom:14px;">
                <label style="display:block;font-size:.83rem;font-weight:600;margin-bottom:6px;">URL *</label>
                <input type="text" name="slug" id="pf_slug" required placeholder="/about"
                    style="width:100%;padding:10px 12px;border:1.5px solid var(--border);border-radius:9px;font-family:inherit;font-size:.87rem;outline:none;">
            </div>
            <div style="margin-bottom:14px;">
                <label style="display:block;font-size:.83rem;font-weight:600;margin-bottom:6px;">Content</label>
                <textarea name="content" id="pf_content" rows="6" placeholder="Page content..."
                    style="width:100%;padding:10px 12px;border:1.5px solid var(--border);border-radius:9px;font-family:inherit;font-size:.87rem;outline:none;resize:vertical;"></textarea>
            </div>
            <div style="margin-bottom:18px;">
                <label style="display:block;font-size:.83rem;font-weight:600;margin-bottom:6px;">Status</label>
                <select name="status" id="pf_status" style="width:100%;padding:10px 12px;border:1.5px solid var(--border);border-radius:9px;font-family:inherit;font-size:.87rem;outline:none;">
                    <option value="draft">Draft</option>
                    <option value="published">Published</option>
                </select>
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button type="button" onclick="closePageModal()" style="background:var(--bg);color:var(--text-primary);border:1px solid var(--border);padding:10px 18px;border-radius:9px;font-family:inherit;font-size:.87rem;font-weight:600;cursor:pointer;">Cancel</button>
                <button type="submit" id="pf_submit" name="add_page" value="1" style="background:var(--green-btn);color:#fff;border:none;padding:10px 20px;border-radius:9px;font-family:inherit;font-size:.87rem;font-weight:700;cursor:pointer;">Create Page</button>
            </div>
        </form>
    </div>
</div>

<!-- ══ VIEW PAGE MODAL (preview only — not live on the public site yet) ══ -->
<div id="viewModalOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:200;align-items:center;justify-content:center;padding:20px;">
    <div style="background:#fff;border-radius:16px;max-width:600px;width:100%;max-height:85vh;overflow-y:auto;padding:26px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
            <h2 id="viewModalTitle" style="font-size:1.15rem;font-weight:700;"></h2>
            <button onclick="closeViewModal()" style="background:none;border:none;font-size:1.1rem;color:var(--text-muted);cursor:pointer;"><i class="fa fa-xmark"></i></button>
        </div>
        <p id="viewModalSlug" style="font-size:.8rem;color:var(--text-muted);margin-bottom:16px;"></p>
        <div style="background:var(--bg);border-radius:10px;padding:16px;font-size:.87rem;line-height:1.6;white-space:pre-wrap;" id="viewModalContent"></div>
        <p style="font-size:.76rem;color:var(--text-muted);margin-top:14px;"><i class="fa fa-circle-info"></i> This is a content preview — pages aren't live on the public site yet.</p>
    </div>
</div>

<script>
function openAddPage(){
    document.getElementById('pageModalTitle').textContent = 'Add Page';
    document.getElementById('pageForm').reset();
    document.getElementById('pf_id').value = '';
    document.getElementById('pf_submit').name = 'add_page';
    document.getElementById('pf_submit').textContent = 'Create Page';
    document.getElementById('pageModalOverlay').style.display = 'flex';
}
function openEditPage(p){
    document.getElementById('pageModalTitle').textContent = 'Edit Page';
    document.getElementById('pf_id').value = p.id;
    document.getElementById('pf_title').value = p.title;
    document.getElementById('pf_slug').value = p.slug;
    document.getElementById('pf_content').value = p.content || '';
    document.getElementById('pf_status').value = p.status;
    document.getElementById('pf_submit').name = 'edit_page';
    document.getElementById('pf_submit').textContent = 'Save Changes';
    document.getElementById('pageModalOverlay').style.display = 'flex';
}
function closePageModal(){ document.getElementById('pageModalOverlay').style.display = 'none'; }

function openViewPage(p){
    document.getElementById('viewModalTitle').textContent = p.title;
    document.getElementById('viewModalSlug').textContent = p.slug + ' · ' + (p.status === 'published' ? 'Published' : 'Draft');
    document.getElementById('viewModalContent').textContent = p.content || '(No content yet)';
    document.getElementById('viewModalOverlay').style.display = 'flex';
}
function closeViewModal(){ document.getElementById('viewModalOverlay').style.display = 'none'; }
</script>

<?php include __DIR__ . '/super_admin_footer.php'; ?>