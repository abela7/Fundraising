<?php
/**
 * WhatsApp PAY Setup
 * - Manage authorized WhatsApp operators (07 / +44)
 * - Edit Amharic bot message templates
 */

declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/WhatsAppPaymentCommandHandler.php';

require_login();
require_admin();

$page_title = 'WhatsApp PAY Setup';
$db = db();
$handler = new WhatsAppPaymentCommandHandler($db); // ensures tables + seeds templates

$success = '';
$error = '';

/**
 * Format digits as UK local for display hint.
 */
function formatUkLocal(string $digits): string
{
    if (str_starts_with($digits, '44') && strlen($digits) >= 12) {
        return '0' . substr($digits, 2);
    }
    return $digits;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf();
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'add_operator') {
            $name = trim((string)($_POST['name'] ?? ''));
            $phoneRaw = trim((string)($_POST['phone'] ?? ''));
            $linkedUserId = (int)($_POST['linked_user_id'] ?? 0);
            $notes = trim((string)($_POST['notes'] ?? ''));
            $active = isset($_POST['active']) ? 1 : 0;

            if ($name === '' || $phoneRaw === '' || $linkedUserId <= 0) {
                throw new RuntimeException('Name, WhatsApp number, and linked system user are required.');
            }

            $phoneDigits = WhatsAppPaymentCommandHandler::normalizeOperatorPhone($phoneRaw);
            if ($phoneDigits === '' || strlen($phoneDigits) < 10) {
                throw new RuntimeException('Invalid WhatsApp number. Use 07XXXXXXXXX or +44XXXXXXXXXX.');
            }

            // Validate linked user
            $u = $db->prepare("SELECT id FROM users WHERE id = ? AND active = 1 AND role IN ('admin','registrar') LIMIT 1");
            $u->bind_param('i', $linkedUserId);
            $u->execute();
            if (!$u->get_result()->fetch_assoc()) {
                throw new RuntimeException('Linked user must be an active admin or registrar.');
            }
            $u->close();

            $stmt = $db->prepare("
                INSERT INTO whatsapp_pay_operators (name, phone_raw, phone_digits, linked_user_id, active, notes, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->bind_param('sssiss', $name, $phoneRaw, $phoneDigits, $linkedUserId, $active, $notes);
            if (!$stmt->execute()) {
                throw new RuntimeException('Could not add operator. Number may already exist.');
            }
            $stmt->close();
            $success = 'Operator added successfully.';
        }

        if ($action === 'toggle_operator') {
            $id = (int)($_POST['operator_id'] ?? 0);
            $stmt = $db->prepare("UPDATE whatsapp_pay_operators SET active = IF(active=1,0,1), updated_at = NOW() WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            $success = 'Operator status updated.';
        }

        if ($action === 'delete_operator') {
            $id = (int)($_POST['operator_id'] ?? 0);
            $stmt = $db->prepare("DELETE FROM whatsapp_pay_operators WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            $success = 'Operator removed.';
        }

        if ($action === 'update_operator') {
            $id = (int)($_POST['operator_id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $phoneRaw = trim((string)($_POST['phone'] ?? ''));
            $linkedUserId = (int)($_POST['linked_user_id'] ?? 0);
            $notes = trim((string)($_POST['notes'] ?? ''));
            $active = isset($_POST['active']) ? 1 : 0;

            if ($id <= 0 || $name === '' || $phoneRaw === '' || $linkedUserId <= 0) {
                throw new RuntimeException('Missing required operator fields.');
            }

            $phoneDigits = WhatsAppPaymentCommandHandler::normalizeOperatorPhone($phoneRaw);
            if ($phoneDigits === '' || strlen($phoneDigits) < 10) {
                throw new RuntimeException('Invalid WhatsApp number.');
            }

            $stmt = $db->prepare("
                UPDATE whatsapp_pay_operators
                SET name = ?, phone_raw = ?, phone_digits = ?, linked_user_id = ?, active = ?, notes = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->bind_param('sssissi', $name, $phoneRaw, $phoneDigits, $linkedUserId, $active, $notes, $id);
            $stmt->execute();
            $stmt->close();
            $success = 'Operator updated.';
        }

        if ($action === 'save_templates') {
            $bodies = $_POST['template_body'] ?? [];
            if (!is_array($bodies)) {
                throw new RuntimeException('Invalid template payload.');
            }
            $stmt = $db->prepare("UPDATE whatsapp_pay_message_templates SET body = ?, updated_at = NOW() WHERE template_key = ?");
            foreach ($bodies as $key => $body) {
                $key = (string)$key;
                $body = (string)$body;
                if ($key === '') {
                    continue;
                }
                $stmt->bind_param('ss', $body, $key);
                $stmt->execute();
            }
            $stmt->close();
            $success = 'Bot messages saved.';
        }

        if ($action === 'reset_templates') {
            $defaults = $handler->defaultTemplates();
            $stmt = $db->prepare("
                UPDATE whatsapp_pay_message_templates
                SET body = ?, label = ?, description = ?, placeholders_help = ?, updated_at = NOW()
                WHERE template_key = ?
            ");
            foreach ($defaults as $key => $tpl) {
                $body = $tpl['body'];
                $label = $tpl['label'];
                $desc = $tpl['description'];
                $ph = $tpl['placeholders'];
                $stmt->bind_param('sssss', $body, $label, $desc, $ph, $key);
                $stmt->execute();
            }
            $stmt->close();
            $success = 'Templates reset to Amharic defaults.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// Load data
$users = [];
$ures = $db->query("SELECT id, name, phone, role FROM users WHERE active = 1 AND role IN ('admin','registrar') ORDER BY name");
if ($ures) {
    while ($row = $ures->fetch_assoc()) {
        $users[] = $row;
    }
}

$operators = [];
$ores = $db->query("
    SELECT o.*, u.name AS linked_user_name, u.role AS linked_user_role
    FROM whatsapp_pay_operators o
    LEFT JOIN users u ON u.id = o.linked_user_id
    ORDER BY o.active DESC, o.name ASC
");
if ($ores) {
    while ($row = $ores->fetch_assoc()) {
        $operators[] = $row;
    }
}

$templates = [];
$tres = $db->query("SELECT * FROM whatsapp_pay_message_templates ORDER BY label ASC");
if ($tres) {
    while ($row = $tres->fetch_assoc()) {
        $templates[] = $row;
    }
}

$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editOperator = null;
if ($editId > 0) {
    foreach ($operators as $op) {
        if ((int)$op['id'] === $editId) {
            $editOperator = $op;
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../assets/theme.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/theme.css'); ?>">
    <style>
        .setup-card { border: 1px solid #e5e7eb; border-radius: 12px; background: #fff; }
        .setup-card .card-header {
            background: #f8fafc; border-bottom: 1px solid #e5e7eb;
            font-weight: 600; padding: 0.9rem 1.1rem;
        }
        .template-box textarea {
            font-family: Consolas, Monaco, monospace;
            font-size: 0.9rem;
            min-height: 140px;
        }
        .ph-help { font-size: 0.8rem; color: #6b7280; }
        .phone-hint { font-size: 0.8rem; color: #6b7280; }
        .badge-active { background: #10b981; }
        .badge-inactive { background: #9ca3af; }
    </style>
</head>
<body>
<div class="app-wrapper">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    <div class="app-content">
        <?php include __DIR__ . '/../includes/topbar.php'; ?>
        <main class="main-content">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                <div>
                    <h1 class="h4 mb-1"><i class="fab fa-whatsapp text-success me-2"></i><?php echo htmlspecialchars($page_title); ?></h1>
                    <p class="text-muted mb-0">Authorize WhatsApp numbers and edit Amharic bot replies for PAY commands.</p>
                </div>
                <div class="d-flex gap-2">
                    <a href="record-pledge-payment.php" class="btn btn-outline-secondary btn-sm">Record Payment</a>
                    <a href="review-pledge-payments.php" class="btn btn-outline-secondary btn-sm">Review Payments</a>
                </div>
            </div>

            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="fas fa-check-circle me-1"></i><?php echo htmlspecialchars($success); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fas fa-exclamation-circle me-1"></i><?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="row g-3">
                <!-- Operators -->
                <div class="col-lg-5">
                    <div class="setup-card mb-3">
                        <div class="card-header">
                            <i class="fas fa-user-plus me-1"></i>
                            <?php echo $editOperator ? 'Edit Operator' : 'Add WhatsApp Operator'; ?>
                        </div>
                        <div class="card-body p-3">
                            <form method="post">
                                <?php echo csrf_input(); ?>
                                <input type="hidden" name="action" value="<?php echo $editOperator ? 'update_operator' : 'add_operator'; ?>">
                                <?php if ($editOperator): ?>
                                    <input type="hidden" name="operator_id" value="<?php echo (int)$editOperator['id']; ?>">
                                <?php endif; ?>

                                <div class="mb-3">
                                    <label class="form-label">Display Name</label>
                                    <input type="text" name="name" class="form-control" required
                                           value="<?php echo htmlspecialchars((string)($editOperator['name'] ?? '')); ?>"
                                           placeholder="Liqe Liqawunt">
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">WhatsApp Number</label>
                                    <input type="text" name="phone" class="form-control" required
                                           value="<?php echo htmlspecialchars((string)($editOperator['phone_raw'] ?? '')); ?>"
                                           placeholder="07XXXXXXXXX or +44XXXXXXXXXX">
                                    <div class="phone-hint mt-1">Accepts <code>07...</code> or <code>+44...</code>. Stored/matched as international digits.</div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Linked System User</label>
                                    <select name="linked_user_id" class="form-select" required>
                                        <option value="">Select admin/registrar...</option>
                                        <?php foreach ($users as $u): ?>
                                            <option value="<?php echo (int)$u['id']; ?>"
                                                <?php echo ((int)($editOperator['linked_user_id'] ?? 0) === (int)$u['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($u['name'] . ' (' . $u['role'] . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="phone-hint mt-1">Used for audit logs and payment ownership.</div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Notes (optional)</label>
                                    <input type="text" name="notes" class="form-control"
                                           value="<?php echo htmlspecialchars((string)($editOperator['notes'] ?? '')); ?>"
                                           placeholder="Evening shift registrar">
                                </div>

                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="checkbox" name="active" id="opActive"
                                           <?php echo (!$editOperator || !empty($editOperator['active'])) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="opActive">Active</label>
                                </div>

                                <div class="d-flex gap-2">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save me-1"></i><?php echo $editOperator ? 'Update' : 'Add Operator'; ?>
                                    </button>
                                    <?php if ($editOperator): ?>
                                        <a href="whatsapp-pay-setup.php" class="btn btn-outline-secondary">Cancel</a>
                                    <?php endif; ?>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="setup-card">
                        <div class="card-header"><i class="fas fa-users me-1"></i> Authorized Operators</div>
                        <div class="card-body p-0">
                            <?php if (empty($operators)): ?>
                                <div class="p-3 text-muted">No operators yet. Add a WhatsApp number above.</div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm mb-0 align-middle">
                                        <thead class="table-light">
                                        <tr>
                                            <th>Name</th>
                                            <th>WhatsApp</th>
                                            <th>Linked User</th>
                                            <th></th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($operators as $op): ?>
                                            <tr>
                                                <td>
                                                    <?php echo htmlspecialchars($op['name']); ?>
                                                    <?php if ((int)$op['active'] === 1): ?>
                                                        <span class="badge badge-active">Active</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-inactive">Off</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <code><?php echo htmlspecialchars($op['phone_raw']); ?></code><br>
                                                    <span class="phone-hint"><?php echo htmlspecialchars(formatUkLocal((string)$op['phone_digits'])); ?></span>
                                                </td>
                                                <td class="small">
                                                    <?php echo htmlspecialchars((string)($op['linked_user_name'] ?? '—')); ?>
                                                </td>
                                                <td class="text-nowrap">
                                                    <a class="btn btn-sm btn-outline-primary" href="?edit=<?php echo (int)$op['id']; ?>">Edit</a>
                                                    <form method="post" class="d-inline">
                                                        <?php echo csrf_input(); ?>
                                                        <input type="hidden" name="action" value="toggle_operator">
                                                        <input type="hidden" name="operator_id" value="<?php echo (int)$op['id']; ?>">
                                                        <button class="btn btn-sm btn-outline-secondary" type="submit">Toggle</button>
                                                    </form>
                                                    <form method="post" class="d-inline" onsubmit="return confirm('Remove this operator?');">
                                                        <?php echo csrf_input(); ?>
                                                        <input type="hidden" name="action" value="delete_operator">
                                                        <input type="hidden" name="operator_id" value="<?php echo (int)$op['id']; ?>">
                                                        <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Templates -->
                <div class="col-lg-7">
                    <div class="setup-card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <span><i class="fas fa-language me-1"></i> Bot Messages (Amharic editable)</span>
                            <form method="post" onsubmit="return confirm('Reset all messages to Amharic defaults?');">
                                <?php echo csrf_input(); ?>
                                <input type="hidden" name="action" value="reset_templates">
                                <button type="submit" class="btn btn-sm btn-outline-warning">Reset Defaults</button>
                            </form>
                        </div>
                        <div class="card-body p-3">
                            <form method="post">
                                <?php echo csrf_input(); ?>
                                <input type="hidden" name="action" value="save_templates">

                                <?php foreach ($templates as $tpl): ?>
                                    <div class="mb-4 template-box">
                                        <label class="form-label fw-semibold mb-1">
                                            <?php echo htmlspecialchars($tpl['label']); ?>
                                            <span class="badge text-bg-light border ms-1"><?php echo htmlspecialchars($tpl['template_key']); ?></span>
                                        </label>
                                        <?php if (!empty($tpl['description'])): ?>
                                            <div class="ph-help mb-1"><?php echo htmlspecialchars((string)$tpl['description']); ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($tpl['placeholders_help'])): ?>
                                            <div class="ph-help mb-2">Placeholders: <code><?php echo htmlspecialchars((string)$tpl['placeholders_help']); ?></code></div>
                                        <?php endif; ?>
                                        <textarea class="form-control" name="template_body[<?php echo htmlspecialchars($tpl['template_key']); ?>]" rows="6"><?php echo htmlspecialchars((string)$tpl['body']); ?></textarea>
                                    </div>
                                <?php endforeach; ?>

                                <button type="submit" class="btn btn-success">
                                    <i class="fas fa-save me-1"></i>Save Bot Messages
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="alert alert-info mt-3 mb-0">
                        <strong>How to use:</strong><br>
                        1. Add the person’s WhatsApp number here (must match the phone they text from).<br>
                        2. Link them to a registrar/admin user.<br>
                        3. From that WhatsApp send: <code>PAY 0335 50</code> then <code>አዎ</code> / <code>YES</code>.
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
