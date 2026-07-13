<?php
/**
 * ADMIN — User Management (users.php) - FIGMA REDESIGN
 * -------------------------------------
 * สิทธิ์: admin เท่านั้น
 * CRUD: เพิ่ม / แก้ไข / ลบ ผู้ใช้งาน
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';

require_role(['officer']);

$pdo  = getDB();
$root = '../';
$msg  = '';
$msg_type = 'success';

// Ensure the `position` column exists (Auto-migrate for Figma design)
try {
    $pdo->query("SELECT position FROM users LIMIT 1");
} catch (PDOException $e) {
    if ($e->getCode() == '42S22') { // Column not found
        $pdo->exec("ALTER TABLE users ADD COLUMN position VARCHAR(255) DEFAULT NULL AFTER email");
    }
}

// Affiliations for dropdown
$affiliations = $pdo->query('SELECT * FROM affiliation_id ORDER BY affiliation_item')->fetchAll();

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $username  = trim($_POST['username'] ?? '');
        $password  = $_POST['password'] ?? '';
        $firstname = trim($_POST['firstname'] ?? '');
        $lastname  = trim($_POST['lastname'] ?? '');
        $position  = trim($_POST['position'] ?? '');
        $affil     = (int)($_POST['affiliation'] ?? 0);
        $email     = trim($_POST['email'] ?? '');
        $role_new  = 'officer'; // ค่าเริ่มต้น (ไม่มี role นักศึกษาแล้ว)

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO users (username, password, firstname, lastname, role, Affiliation, email, position)
                 VALUES (:u, :p, :fn, :ln, :r, :a, :e, :pos)'
            );
            $stmt->execute([
                ':u'  => $username,
                ':p'  => $password,
                ':fn' => $firstname,
                ':ln' => $lastname,
                ':r'  => $role_new,
                ':a'  => $affil,
                ':e'  => $email ?: null,
                ':pos'=> $position ?: null,
            ]);
            $msg = 'เพิ่มข้อมูลผู้ใช้งาน "' . htmlspecialchars($firstname . ' ' . $lastname) . '" สำเร็จ';
            $msg_type = 'success';
        } catch (PDOException $e) {
            $msg_type = 'danger';
            if ($e->getCode() === '23000') {
                $msg = 'Username หรือ Email นี้มีอยู่ในระบบแล้ว';
            } else {
                $msg = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
            }
        }
    }
    
    // Deletion
    if ($action === 'delete') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid === (int)$_SESSION['user_id']) {
            $msg_type = 'danger';
            $msg = 'ไม่สามารถลบบัญชีของตัวเองได้';
        } else {
            try {
                $pdo->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $uid]);
                $msg = 'ลบผู้ใช้งานสำเร็จ';
                $msg_type = 'success';
            } catch (PDOException $e) {
                $msg_type = 'danger';
                $msg = 'เกิดข้อผิดพลาดในการลบ';
            }
        }
    }
}

// Fetch users for the list
$users = $pdo->query(
    'SELECT u.*, a.affiliation_item FROM users u
     LEFT JOIN affiliation_id a ON a.id = u.Affiliation
     ORDER BY u.id DESC'
)->fetchAll();

$fullname = $_SESSION['firstname'] . ' ' . $_SESSION['lastname'];
$role     = $_SESSION['role'];

// Default Date/Time for the Figma Form
$current_date = date('Y-m-d');
$current_time = date('H:i:s');
$page_title = "ข้อมูลผู้ใช้งาน";
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ตั้งค่าการใช้งาน | ข้อมูลผู้ใช้งาน</title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600&family=Inter:wght@400;500;600&family=Sarabun:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $root ?>assets/css/sidebar.css<?= asset_v('assets/css/sidebar.css') ?>">
    <style>
        :root {
            --bg-body: #F3F6F9;
            --bg-sidebar: #FFFFFF;
            --text-main: #1F2937;
            --text-muted: #6B7280;
            --primary: #7C3AED;      /* Figma Purple */
            --primary-hover: #6D28D9;
            --secondary: #E5E7EB;
            --danger: #EF4444;       /* Figma Red */
            --danger-hover: #DC2828;
            --success: #3B82F6;      /* Figma Blue for normal buttons */
            --success-hover: #2563EB;
            --input-bg: #F9FAFB;
            --input-border: #E5E7EB;
            --font-th: 'Kanit', sans-serif;
            --font-en: 'Kanit', sans-serif;
            --card-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.01);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: var(--font-th);
            background-color: var(--bg-body);
            color: var(--text-main);
            display: flex;
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* ─── Sidebar (Figma Style) ────────────────────── */
        .sidebar {
            width: 280px;
            background: var(--bg-sidebar);
            border-right: 1px solid var(--secondary);
            display: flex;
            flex-direction: column;
            padding: 2rem 1.5rem;
            position: fixed;
            height: 100vh;
            z-index: 100;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 2.5rem;
            padding-left: 0.5rem;
        }
        .brand-icon {
            width: 32px;
            height: 32px;
            background: var(--primary);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 18px;
        }
        .brand-text {
            font-family: var(--font-en);
            font-size: 1.25rem;
            font-weight: 700;
            color: #B45309; /* Figma Gold/Orange */
        }
        .brand-text span {
            color: var(--primary);
        }

        .user-profile {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-bottom: 2.5rem;
        }
        .avatar {
            width: 80px;
            height: 80px;
            background: #C4B5FD; /* Light purple */
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1rem;
            color: white;
        }
        .avatar svg { width: 40px; height: 40px; }
        .user-name {
            font-weight: 700;
            font-family: var(--font-en);
            font-size: 1.1rem;
            letter-spacing: 0.5px;
            color: var(--text-main);
        }

        .nav-menu {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            flex: 1;
        }
        .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0.8rem 1.2rem;
            border-radius: 12px;
            color: var(--text-muted);
            text-decoration: none;
            font-weight: 500;
            transition: all 0.2s;
            cursor: pointer;
        }
        .nav-item:hover {
            background: #F3F4F6;
            color: var(--text-main);
        }
        .nav-item.active {
            background: var(--primary);
            color: white;
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.25);
        }
        
        .nav-label {
            font-size: 0.75rem;
            font-weight: 600;
            color: #9CA3AF;
            letter-spacing: 1px;
            margin: 1.5rem 0 0.5rem 1rem;
            text-transform: uppercase;
        }

        /* ─── Main Content ──────────────────────────────── */
        .main-content {
            margin-left: 280px;
            flex: 1;
            padding: 2.5rem 3rem;
            width: calc(100% - 280px);
        }

        .page-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 2rem;
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--text-main);
        }
        .page-header span {
            color: var(--text-muted);
        }

        /* ─── Forms & Cards ─────────────────────────────── */
        .card {
            background: white;
            border-radius: 20px;
            padding: 2.5rem;
            box-shadow: var(--card-shadow);
            border: 1px solid rgba(0,0,0,0.02);
            animation: fadeIn 0.4s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .card-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--secondary);
            color: var(--text-main);
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem 2.5rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            position: relative;
        }
        
        .form-label {
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--text-muted);
        }

        .form-control {
            background: var(--input-bg);
            border: 1px solid var(--input-border);
            padding: 0.875rem 1.2rem;
            border-radius: 12px;
            font-family: inherit;
            font-size: 1rem;
            color: var(--text-main);
            transition: all 0.2s;
            width: 100%;
        }
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(124, 58, 237, 0.1);
            background: white;
        }
        .form-control:read-only {
            background: #F3F4F6;
            color: #4B5563;
        }

        /* ─── Custom Select (Glassmorphism Premium) ────── */
        .custom-select-wrapper {
            position: relative;
            width: 100%;
        }
        .custom-select-trigger {
            background: var(--input-bg);
            border: 1px solid var(--input-border);
            padding: 0.875rem 1.2rem;
            border-radius: 12px;
            font-family: inherit;
            font-size: 1rem;
            color: var(--text-main);
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.2s;
        }
        .custom-select-trigger:focus-within, .custom-select-trigger.active {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(124, 58, 237, 0.1);
            background: white;
        }
        .custom-select-icon {
            transition: transform 0.2s;
            color: var(--text-muted);
        }
        .custom-select-trigger.active .custom-select-icon {
            transform: rotate(180deg);
        }
        .custom-options {
            position: absolute;
            top: calc(100% + 8px);
            left: 0;
            width: 100%;
            background: white;
            border: 1px solid var(--input-border);
            border-radius: 16px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.08);
            z-index: 50;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            max-height: 250px;
            overflow-y: auto;
            padding: 0.5rem;
        }
        .custom-select-wrapper.active .custom-options {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }
        .custom-option {
            padding: 0.75rem 1rem;
            border-radius: 8px;
            cursor: pointer;
            transition: background 0.15s;
            font-size: 0.95rem;
        }
        .custom-option:hover {
            background: #F3F4F6;
            color: var(--primary);
        }
        .custom-option.selected {
            background: rgba(124, 58, 237, 0.08);
            color: var(--primary);
            font-weight: 600;
        }

        /* ─── Buttons ───────────────────────────────────── */
        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            margin-top: 2.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--secondary);
            grid-column: 1 / -1;
        }
        .btn {
            padding: 0.75rem 2rem;
            border-radius: 12px;
            font-family: inherit;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            border: none;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .btn-cancel {
            background: var(--danger);
            color: white;
        }
        .btn-cancel:hover { background: var(--danger-hover); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(239,68,68,0.3); }
        .btn-save {
            background: var(--success);
            color: white;
        }
        .btn-save:hover { background: var(--success-hover); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(59,130,246,0.3); }

        .btn-toggle-view {
            background: transparent;
            border: 1px solid var(--input-border);
            color: var(--text-main);
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-size: 0.9rem;
        }
        .btn-toggle-view:hover {
            background: var(--input-bg);
        }

        /* Lists */
        .list-table-container {
            margin-top: 2rem;
        }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 1rem; text-align: left; border-bottom: 1px solid var(--secondary); font-size: 0.95rem; }
        th { color: var(--text-muted); font-weight: 500; }
        .badge { padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.8rem; font-weight: 600; }
        .badge.admin { background: rgba(124,58,237,0.1); color: var(--primary); }
        .badge.user { background: rgba(59,130,246,0.1); color: var(--success); }

        .alert {
            padding: 1rem 1.5rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert.success { background: rgba(59,130,246,0.1); color: var(--success-hover); border: 1px solid rgba(59,130,246,0.2); }
        .alert.danger { background: rgba(239,68,68,0.1); color: var(--danger-hover); border: 1px solid rgba(239,68,68,0.2); }

    </style>
</head>
<body>

    <?php include_once __DIR__ . '/includes/sidebar.php'; ?>

    <!-- MAIN CONTENT -->
    <main class="main-content">
        
        <?php include_once __DIR__ . '/includes/header.php'; ?>

        <div style="display:flex; align-items:center; margin-bottom: 20px;">
            <div style="margin-left: auto;">
                <button class="btn-toggle-view" id="btn-toggle-view">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline; margin-bottom:-2px;"><list></list><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg>
                    แสดงตารางผู้ใช้
                </button>
            </div>
        </div>

        <?php if ($msg): ?>
            <div class="alert <?= $msg_type ?>">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 8 12 12 14 14"></polyline></svg>
                <?= htmlspecialchars($msg) ?>
            </div>
        <?php endif; ?>

        <!-- FIGMA FORM COMPONENT -->
        <div class="card" id="form-view">
            <div class="card-title">ลงทะเบียนเข้าใช้งานระบบ</div>

            <form method="POST" action="users.php">
                <input type="hidden" name="action" value="add">
                
                <div class="form-grid">
                    <!-- Row 1 -->
                    <div class="form-group">
                        <label class="form-label">วันลงทะเบียนเข้าใช้งานระบบ</label>
                        <input type="date" class="form-control" name="reg_date" value="<?= $current_date ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label class="form-label">เวลาลงทะเบียนเข้าใช้งานระบบ</label>
                        <input type="time" class="form-control" name="reg_time" value="<?= $current_time ?>" readonly step="1">
                    </div>

                    <!-- Row 2 -->
                    <div class="form-group">
                        <label class="form-label">ชื่อ</label>
                        <input type="text" class="form-control" name="firstname" placeholder="ชื่อ" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">นามสกุล</label>
                        <input type="text" class="form-control" name="lastname" placeholder="นามสกุล" required>
                    </div>

                    <!-- Row 3 -->
                    <div class="form-group">
                        <label class="form-label">Username</label>
                        <input type="text" class="form-control" name="username" placeholder="Username" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Password</label>
                        <input type="password" class="form-control" name="password" placeholder="Password" required>
                    </div>

                    <!-- Row 4 -->
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" name="email" placeholder="example@up.ac.th">
                    </div>
                    <div class="form-group">
                        <label class="form-label">สังกัดงาน :</label>
                        <!-- CUSTOM DROPDOWN -->
                        <div class="custom-select-wrapper" id="affiliation-select">
                            <input type="hidden" name="affiliation" id="affiliation-val" required>
                            <div class="custom-select-trigger" onclick="toggleSelect('affiliation-select')">
                                <span id="affiliation-label">เลือกสังกัดงาน</span>
                                <svg class="custom-select-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>
                            </div>
                            <div class="custom-options">
                                <?php foreach ($affiliations as $a): ?>
                                    <div class="custom-option" onclick="selectOption('affiliation-select', '<?= $a['id'] ?>', '<?= htmlspecialchars($a['affiliation_item']) ?>')">
                                        <?= htmlspecialchars($a['affiliation_item']) ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Row 5 -->
                    <div class="form-group">
                        <label class="form-label">ตำแหน่ง</label>
                        <input type="text" class="form-control" name="position" placeholder="เช่น นักวิชาการคอมพิวเตอร์">
                    </div>

                    <!-- Actions -->
                    <div class="form-actions">
                        <button type="reset" class="btn btn-cancel">ยกเลิก</button>
                        <button type="submit" class="btn btn-save">ตกลง</button>
                    </div>
                </div>
            </form>
        </div>

        <!-- LIST USERS COMPONENT (Hidden by default, toggleable) -->
        <div class="card list-table-container" id="list-view" style="display: none;">
            <div class="card-title">รายชื่อผู้ใช้งานทั้งหมด</div>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>ชื่อ - นามสกุล</th>
                            <th>Username</th>
                            <th>สิทธิ์</th>
                            <th>สังกัดงาน</th>
                            <th>ตำแหน่ง</th>
                            <th>จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                        <tr>
                            <td><?= htmlspecialchars($u['firstname'] . ' ' . $u['lastname']) ?></td>
                            <td><strong><?= htmlspecialchars($u['username']) ?></strong></td>
                            <td>
                                <span class="badge <?= $u['role'] === 'admin' ? 'admin' : 'user' ?>">
                                    <?= htmlspecialchars($u['role']) ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($u['affiliation_item'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($u['position'] ?? '-') ?></td>
                            <td>
                                <?php if ($u['id'] !== (int)$_SESSION['user_id']): ?>
                                <form method="POST" onsubmit="return confirm('ยืนยันการลบผู้ใช้งานใช่หรือไม่?');" style="display:inline;">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                    <button type="submit" style="background:none; border:none; color:var(--danger); cursor:pointer;">
                                        ลบ
                                    </button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>

    <script>
        // Custom Select Logic
        function toggleSelect(id) {
            document.querySelectorAll('.custom-select-wrapper').forEach(el => {
                if(el.id !== id) el.classList.remove('active');
            });
            document.getElementById(id).classList.toggle('active');
        }

        function selectOption(wrapperId, value, text) {
            const wrapper = document.getElementById(wrapperId);
            wrapper.querySelector('input[type="hidden"]').value = value;
            wrapper.querySelector('.custom-select-trigger span').innerText = text;
            wrapper.classList.remove('active');
        }

        // Close select when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.custom-select-wrapper')) {
                document.querySelectorAll('.custom-select-wrapper').forEach(el => el.classList.remove('active'));
            }
        });

        // View Toggling (Form vs List)
        const btnToggle = document.getElementById('btn-toggle-view');
        const formView = document.getElementById('form-view');
        const listView = document.getElementById('list-view');

        btnToggle.addEventListener('click', () => {
            if (formView.style.display === 'none') {
                formView.style.display = 'block';
                listView.style.display = 'none';
                btnToggle.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline; margin-bottom:-2px;"><list></list><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg> แสดงตารางผู้ใช้';
            } else {
                formView.style.display = 'none';
                listView.style.display = 'block';
                btnToggle.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline; margin-bottom:-2px;"><path d="M12 5v14"></path><path d="M5 12h14"></path></svg> เพิ่มผู้ใช้งาน';
            }
        });
    </script>
</body>
</html>
